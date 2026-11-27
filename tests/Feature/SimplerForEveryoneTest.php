<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\FirewallList;
use App\Models\IntegrationConfig;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Notifications\RequestDecisionNotification;
use App\Services\Auth\UserProvisioningService;
use App\Services\Network\MockNetworkProvider;
use App\Services\Network\ProviderRequestFailed;
use App\Services\Policy\FirewallListPusher;
use App\Services\Policy\ProtectedSites;
use App\Services\Requests\RequestWorkflow;
use App\Services\SettingsRepository;
use App\Services\Support;
use App\Services\Website\ProbeSettings;
use App\Support\Reach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * The 2026.12.30 feedback: a signed-out website check, the short request
 * form, plain results and emails, "never IT or admin", IP lists only, the
 * UniFi list size, and the helpdesk that can never be blocked.
 */
class SimplerForEveryoneTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->installWithPolicies('active');
    }

    public function test_the_signed_out_check_is_off_until_allowed_then_gives_one_plain_line_per_network_type(): void
    {
        $this->get(route('checks.public'))->assertNotFound();
        app(SettingsRepository::class)->set(ProbeSettings::PUBLIC_KEY, true);
        app(SettingsRepository::class)->set(ProbeSettings::EMBED_KEY, ['https://intranet.example.edu.au']);
        Http::fake(['probe-student:3000/*' => Http::response(['results' => [['status' => 'blocked', 'block_method' => 'dns', 'addresses' => ['203.0.113.250'], 'hosts' => []]]]),
            'probe-staff:3000/*' => Http::response(['results' => [['status' => 'allowed', 'addresses' => ['93.184.216.34'], 'hosts' => [], 'page_title' => 'Secret title']]])]);

        $this->get(route('checks.public'))->assertOk()->assertSee('Is a website blocked?');
        $this->post(route('checks.public.store'), ['websites' => 'games.example.org'])->assertOk()
            ->assertSee('Students')->assertSee('Blocked')->assertSee('Staff')->assertSee('Allowed')->assertSee('Sign in to request access')
            ->assertDontSee('203.0.113.250')->assertDontSee('93.184.216.34')->assertDontSee('Secret title')->assertDontSee('Student Access');
        $this->assertTrue(AuditEvent::query()->where('action', 'check.public')->exists());
        $this->post(route('checks.public.store'), ['websites' => 'a.example b.example c.example d.example'])->assertSessionHasErrors('websites');

        $embed = $this->get(route('checks.embed'))->assertOk()->assertDontSee('Primary navigation');
        $this->assertStringContainsString('frame-ancestors \'self\' https://intranet.example.edu.au', $embed->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('intranet.example.edu.au', $this->get(route('checks.public'))->headers->get('Content-Security-Policy'), 'Only the embed page can be framed by those sites');
    }

    public function test_students_get_the_short_form_and_it_is_told_what_was_left_to_it(): void
    {
        app(SettingsRepository::class)->set('approval.students_can_request', true);
        $student = User::factory()->student()->create();
        $this->actingAs($student)->get(route('requests.create', ['domains' => 'history.example.org']))->assertOk()
            ->assertSee('Ask for a website')->assertSee('Why do you need it?')->assertDontSee('Which networks need access?')->assertDontSee('Request a block instead');
        $this->actingAs($student)->post(route('requests.store'), ['domains' => 'history.example.org', 'use_case' => 'My assignment on the gold rush'])
            ->assertRedirect()->assertSessionHas('success', "Sent. IT will email you when they've looked at it.");

        $request = WebsiteRequest::query()->sole();
        $this->assertTrue($request->it_decides);
        $this->assertSame(['student'], $request->access_scope->types);
        $this->assertNull($request->requested_until);
        $this->actingAs(User::factory()->it()->create())->get(route('requests.show', $request))->assertOk()->assertSee('Sent with the short form');

        // Staff keep the full form unless the setting gives it to them too.
        $this->actingAs(User::factory()->teacher()->create())->get(route('requests.create'))->assertOk()->assertSee('Which networks need access?');
    }

    public function test_someone_on_the_never_it_list_never_gets_it_and_loses_it_at_their_next_sign_in(): void
    {
        $configuration = ['role_rules' => [['type' => 'group', 'value' => 'it-staff', 'role' => 'it']], 'default_role' => 'teacher',
            'never_it' => UserProvisioningService::parseNeverIt("helper@school.example\ngroup:Contractors\nnot-an-address"), 'never_it_role' => 'teacher'];
        $this->assertSame(['helper@school.example', 'group:contractors'], $configuration['never_it']);
        $users = app(UserProvisioningService::class);

        $this->assertSame('it', $users->explainRole(['email' => 'tech@school.example', 'groups' => ['it-staff']], $configuration)['role']);
        $capped = $users->explainRole(['email' => 'helper@school.example', 'groups' => ['it-staff']], $configuration);
        $this->assertSame('teacher', $capped['role']);
        $this->assertStringContainsString('never IT or admin', $capped['rule']);
        $this->assertSame('teacher', $users->explainRole(['email' => 'temp@school.example', 'groups' => ['it-staff', 'Contractors']], $configuration)['role']);

        IntegrationConfig::query()->create(['provider' => 'oidc', 'name' => 'OIDC', 'mode' => 'active', 'enabled' => true, 'configuration' => $configuration]);
        $helper = User::factory()->it()->create(['email' => 'helper@school.example', 'oidc_subject' => 'sub-1', 'auth_source' => 'oidc']);
        $users->provision(['sub' => 'sub-1', 'email' => 'helper@school.example', 'name' => 'Helper', 'groups' => ['it-staff']]);
        $this->assertSame('teacher', $helper->refresh()->role);
        $this->assertTrue(AuditEvent::query()->where('action', 'user.role_capped')->exists());
    }

    public function test_ip_lists_only_never_writes_rules_and_warns_about_a_new_part(): void
    {
        IntegrationConfig::query()->where('provider', 'mock')->update(['configuration' => ['firewall_rules' => 'lists_only', 'max_ip_group_entries' => 100]]);
        $this->expectExceptionObject(new ProviderRequestFailed("Filterdesk is set to keep IP lists only, so it doesn't create firewall rules (Settings → Network provider → Firewall rules)."));
        try {
            app(MockNetworkProvider::class)->createBlockRule('x', 'g', 'ipv4', 'zone-internal', 'zone-external');
        } finally {
            $addresses = array_map(fn (int $index) => long2ip(ip2long('8.8.0.0') + $index), range(0, 149));
            $list = FirewallList::query()->create(['name' => 'Bad IPs', 'family' => 'ipv4', 'manual_entries' => array_slice($addresses, 0, 90), 'rule_zones' => ['zone-internal'], 'max_entries' => 1000]);
            $pusher = app(FirewallListPusher::class);
            $this->assertTrue($pusher->push($list, null, force: true)['ok']);
            $this->assertSame([], Cache::get('mock.firewall_rules', []), 'No rule is created, even with a zone ticked');

            $list->forceFill(['manual_entries' => $addresses])->save();
            $result = $pusher->push($list->refresh(), null, force: true);
            $this->assertStringContainsString('NEW UniFi IP list: Bad IPs (2 of 2)', $result['message']);
            $this->assertSame(['Bad IPs (2 of 2)'], $list->refresh()->new_parts);

            $admin = User::factory()->admin()->create();
            $this->actingAs($admin)->get(route('admin.policy.blocklists.index'))->assertOk()
                ->assertSee('New UniFi IP list:')->assertSee('Use them in your own firewall rules in UniFi')->assertDontSee('Create blocking rules in UniFi')->assertSee('UniFi IP list(s) kept by Filterdesk');
            $this->actingAs($admin)->post(route('admin.policy.blocklists.firewall.parts-added', $list))->assertSessionHas('success');
            $this->assertNull($list->refresh()->new_parts);
        }
    }

    public function test_the_settings_save_the_list_size_and_the_rule_mode_and_log_the_change(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put(route('admin.settings.update', 'network'), ['provider' => 'mock', 'mode' => 'active', 'entry_matching' => 'exact',
            'max_ip_group_entries' => 50000, 'firewall_rules' => 'lists_only'])->assertSessionHas('success');
        $configuration = IntegrationConfig::query()->where('provider', 'mock')->sole()->configuration;
        $this->assertSame(50000, $configuration['max_ip_group_entries']);
        $this->assertSame('lists_only', $configuration['firewall_rules']);
        $this->assertSame(50000, app(FirewallListPusher::class)->groupLimit());
        $this->actingAs($admin)->put(route('admin.settings.update', 'network'), ['provider' => 'mock', 'mode' => 'active', 'entry_matching' => 'exact', 'firewall_rules' => 'manage']);
        $this->assertTrue(AuditEvent::query()->where('action', 'settings.firewall_rules_changed')->exists());
        $this->assertSame(50000, IntegrationConfig::query()->where('provider', 'mock')->sole()->configuration['max_ip_group_entries'], 'Kept when the field is left out');
    }

    public function test_requester_emails_are_plain_end_with_the_helpdesk_and_can_skip_the_approved_step(): void
    {
        app(Support::class)->save(['name' => 'IT Service Desk', 'email' => 'servicedesk@school.example']);
        $teacher = User::factory()->teacher()->create();
        $request = $this->pendingRequest(['lesson.example.org'], Reach::types('student'), ['requester_id' => $teacher->id, 'requested_until' => now()->addDays(3)->setTime(15, 0)]);
        app(RequestWorkflow::class)->approve($request, User::factory()->it()->create(), 'For the Year 9 unit.');

        Notification::assertSentTo($teacher, RequestDecisionNotification::class, function ($notification, $channels, $notifiable) {
            $mail = $notification->toMail($notifiable);
            $text = implode("\n", [...$mail->introLines, ...$mail->outroLines]);

            return str_contains($text, 'For the Year 9 unit.') && str_contains($text, 'For anything else, contact IT Service Desk at servicedesk@school.example.')
                && str_contains($text, ' until ') && ! str_contains($text, 'Australia/');
        });

        // With "approved" off, a request still being set up sends nothing until it works; one that works straight away gets one email.
        app(SettingsRepository::class)->set('notifications.requester.approved', false);
        Notification::fake();
        IntegrationConfig::query()->where('provider', 'mock')->update(['mode' => 'read_only']);
        $second = $this->pendingRequest(['other.example.org'], Reach::types('student'), ['requester_id' => $teacher->id]);
        app(RequestWorkflow::class)->approve($second, User::factory()->it()->create());
        Notification::assertNotSentTo($teacher, RequestDecisionNotification::class);
        app(RequestWorkflow::class)->markApplied($second->refresh(), User::factory()->it()->create());
        Notification::assertSentTo($teacher, RequestDecisionNotification::class, fn ($notification, $channels, $notifiable) => str_starts_with($notification->toMail($notifiable)->subject, 'Now allowed'));
    }

    public function test_the_helpdesk_this_app_and_sign_in_can_never_be_blocked(): void
    {
        app(Support::class)->save(['email' => 'help@school.example', 'article_url' => 'https://intranet.school.example/it/websites']);
        config(['app.url' => 'https://filterdesk.school.example']);
        $sites = app(ProtectedSites::class);

        $this->assertTrue($sites->protects('intranet.school.example'));
        $this->assertTrue($sites->protects('www.intranet.school.example'));
        $this->assertTrue($sites->protects('school.example'), 'The helpdesk email domain, at the top level');
        $this->assertTrue($sites->protects('filterdesk.school.example'));
        $this->assertFalse($sites->protects('games.school.example'), 'Other subdomains of the school can still be blocked');
        $this->assertSame('the helpdesk support article (Help and support)', $sites->why('*.intranet.school.example'));

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.settings.show', 'protected'))->assertOk()->assertSee('Always protected')->assertSee('*.intranet.school.example');
    }
}
