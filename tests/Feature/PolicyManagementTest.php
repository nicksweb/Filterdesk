<?php

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\ListScope;
use App\Enums\RequestStatus;
use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Models\PolicyEntry;
use App\Models\PolicyList;
use App\Models\Setting;
use App\Models\User;
use App\Services\Policy\PolicyEngine;
use App\Services\Requests\RequestWorkflow;
use App\Services\SettingsRepository;
use App\Support\Reach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

class PolicyManagementTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private PolicyEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->engine = $this->installWithPolicies();
    }

    public function test_defaults_create_a_policy_per_audience_and_site_and_audience_lists(): void
    {
        $this->assertEqualsCanonicalizing(['student', 'staff'], FilterPolicy::query()->pluck('slug')->all());
        $this->assertTrue(PolicyList::query()->where('scope', ListScope::Site)->where('kind', ListKind::Allow)->exists());
        $this->assertTrue(PolicyList::query()->where('scope', ListScope::Audience)->where('audience', 'student')->where('kind', ListKind::Allow)->where('is_default', true)->exists());

        $this->engine->ensureDefaults();
        $this->assertSame(6, PolicyList::query()->count(), 'ensureDefaults must be idempotent');
    }

    public function test_manual_site_wide_entry_reaches_student_and_staff_policies_without_a_request(): void
    {
        $admin = User::factory()->admin()->create();
        $siteAllow = PolicyList::query()->where('scope', ListScope::Site)->where('kind', ListKind::Allow)->firstOrFail();

        $this->actingAs($admin)->post(route('admin.policy.entries.store'), [
            'policy_list_id' => $siteAllow->id,
            'domains' => "https://Resources.Example.edu.au/path\n*.cdn.example.net",
            'note' => 'Whole-school resource',
        ])->assertRedirect()->assertSessionHas('success');

        foreach (FilterPolicy::all() as $policy) {
            $this->assertSame(['cdn.example.net', 'resources.example.edu.au'], $this->engine->desired($policy)['allow'], $policy->name);
        }
    }

    public function test_approval_scope_decides_which_policies_receive_the_domains(): void
    {
        $it = User::factory()->it()->create();
        $workflow = app(RequestWorkflow::class);
        $student = FilterPolicy::query()->where('audience', 'student')->firstOrFail();
        $staff = FilterPolicy::query()->where('audience', 'staff')->firstOrFail();

        $workflow->approve($this->pendingRequest(['staffonly.example.org'], Reach::types('staff')), $it);
        $workflow->approve($this->pendingRequest(['everyone.example.org'], Reach::types('staff', 'student')), $it);

        $this->assertSame(['everyone.example.org', 'staffonly.example.org'], $this->engine->desired($staff)['allow']);
        $this->assertSame(['everyone.example.org'], $this->engine->desired($student)['allow']);
    }

    public function test_site_wide_override_reaches_a_guest_policy(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('admin.policy.policies.store'), ['name' => 'Guests', 'audience' => 'guest'])->assertRedirect();
        $guest = FilterPolicy::query()->where('slug', 'guests')->firstOrFail();
        $this->assertTrue(PolicyList::query()->where('audience', 'guest')->where('kind', ListKind::Allow)->exists());

        app(RequestWorkflow::class)->approve($this->pendingRequest(['open.example.org']), $admin, scope: Reach::siteWide());
        app(RequestWorkflow::class)->approve($this->pendingRequest(['class.example.org']), $admin);

        $this->assertSame(['open.example.org'], $this->engine->desired($guest)['allow']);
    }

    public function test_temporary_access_leaves_desired_state_and_is_flagged_for_removal(): void
    {
        $it = User::factory()->it()->create();
        $request = $this->pendingRequest(['exam.example.org'], Reach::types('staff', 'student'), [
            'requested_until' => now()->addHour(),
        ]);
        app(RequestWorkflow::class)->approve($request, $it);
        $student = FilterPolicy::query()->where('audience', 'student')->firstOrFail();
        $this->assertSame(['exam.example.org'], $this->engine->desired($student)['allow']);

        $student->update(['current_allowlist' => ['exam.example.org'], 'current_blocklist' => [], 'snapshot_at' => now()]);
        $this->travel(2)->hours();
        $this->artisan('requests:expire')->assertSuccessful();

        $report = $this->engine->reconcile($student->refresh());
        $this->assertSame([], $report['allow']);
        $this->assertSame(['exam.example.org'], $report['allow_remove']);
        $this->assertSame(RequestStatus::Expired, $request->refresh()->status);
    }

    public function test_snapshot_normalises_unifi_syntax_and_separates_unmanaged_entries(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = FilterPolicy::query()->where('audience', 'staff')->firstOrFail();
        $staffAllow = $this->engine->defaultAllowList('staff');
        $this->engine->upsertEntry($staffAllow, 'wanted.example.org', ['source' => 'manual']);

        $this->actingAs($admin)->post(route('admin.policy.policies.snapshot', $staff), [
            'current_allowlist' => "www\\.legacy.example.org\n*.wildcard.example.org",
            'current_blocklist' => '',
        ])->assertRedirect()->assertSessionHas('success');

        $report = $this->engine->reconcile($staff->refresh());
        $this->assertContains('www.legacy.example.org', $report['current_allow']);
        $this->assertContains('wildcard.example.org', $report['current_allow']);
        $this->assertSame(['wanted.example.org'], $report['allow_add']);
        $this->assertEqualsCanonicalizing(['www.legacy.example.org', 'wildcard.example.org'], $report['allow_review']);
        $this->assertFalse($report['in_sync']);

        $this->actingAs($admin)->post(route('admin.policy.policies.synced', $staff))->assertRedirect();
        $this->assertTrue($this->engine->reconcile($staff->refresh())['in_sync']);
    }

    public function test_block_entries_are_overridden_by_allow_entries_and_reported_as_conflicts(): void
    {
        $student = FilterPolicy::query()->where('audience', 'student')->firstOrFail();
        $siteBlock = PolicyList::query()->where('scope', ListScope::Site)->where('kind', ListKind::Block)->firstOrFail();
        $this->engine->upsertEntry($siteBlock, 'games.example.org', ['source' => 'manual']);
        $this->engine->upsertEntry($siteBlock, 'video.example.org', ['source' => 'manual']);
        $this->engine->upsertEntry($this->engine->defaultAllowList('student'), 'video.example.org', ['source' => 'manual']);

        $desired = $this->engine->desired($student);
        $this->assertSame(['games.example.org'], $desired['block']);
        $this->assertSame(['video.example.org'], $desired['conflicts']);
        $this->assertSame(['video.example.org'], $desired['allow']);
    }

    public function test_revoking_keeps_a_shared_entry_for_another_granted_request(): void
    {
        $it = User::factory()->it()->create();
        $workflow = app(RequestWorkflow::class);
        $first = $workflow->approve($this->pendingRequest(['shared.example.org', 'only-first.example.org']), $it);
        $second = $workflow->approve($this->pendingRequest(['shared.example.org']), $it);

        $workflow->revoke($first, $it, 'No longer required.');

        $student = FilterPolicy::query()->where('audience', 'student')->firstOrFail();
        $this->assertSame(['shared.example.org'], $this->engine->desired($student)['allow']);
        $this->assertSame($second->id, PolicyEntry::query()->where('domain', 'shared.example.org')->value('website_request_id'));
        $this->assertSame(RequestStatus::Revoked, $first->refresh()->status);
    }

    public function test_subdomain_matching_collapses_covered_entries_only_when_configured(): void
    {
        $student = FilterPolicy::query()->where('audience', 'student')->firstOrFail();
        $list = $this->engine->defaultAllowList('student');
        foreach (['example.com', 'www.example.com', 'cdn.example.com', 'other.org'] as $domain) {
            $this->engine->upsertEntry($list, $domain, ['source' => 'manual']);
        }

        $this->assertSame(['cdn.example.com', 'example.com', 'other.org', 'www.example.com'], $this->engine->desired($student)['allow']);

        IntegrationConfig::query()->where('provider', 'mock')->update(['enabled' => false]);
        IntegrationConfig::query()->create(['provider' => 'unifi', 'name' => 'UniFi', 'mode' => 'read_only', 'enabled' => true,
            'configuration' => ['controller_url' => 'https://unifi.example.test', 'entry_matching' => 'includes_subdomains'], 'credentials' => ['api_key' => 'x']]);

        $this->assertSame(['example.com', 'other.org'], $this->engine->desired($student)['allow']);
    }

    public function test_it_staff_can_reconcile_but_only_admins_change_structure(): void
    {
        $it = User::factory()->it()->create();
        $teacher = User::factory()->teacher()->create();
        $student = FilterPolicy::query()->where('audience', 'student')->firstOrFail();

        $this->actingAs($it)->get(route('admin.policy.index'))->assertOk()->assertSee('Students');
        $this->actingAs($it)->get(route('admin.policy.policies.show', $student))->assertOk()->assertSee('Desired allow list');
        $this->actingAs($it)->post(route('admin.policy.policies.store'), ['name' => 'X', 'audience' => 'guest'])->assertForbidden();
        $this->actingAs($teacher)->get(route('admin.policy.index'))->assertForbidden();
    }

    public function test_lookup_finds_parent_domain_entries_and_export_is_plain_text(): void
    {
        $admin = User::factory()->admin()->create();
        $list = $this->engine->defaultAllowList('staff');
        $this->engine->upsertEntry($list, 'example.edu.au', ['source' => 'manual']);

        $this->actingAs($admin)->get(route('admin.policy.index', ['lookup' => 'https://learn.example.edu.au/x']))
            ->assertOk()->assertSee('Staff allow');

        $staff = FilterPolicy::query()->where('audience', 'staff')->firstOrFail();
        $this->actingAs($admin)->get(route('admin.policy.policies.export', [$staff, 'allow']))
            ->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8')->assertSeeText('example.edu.au');
    }

    public function test_legacy_master_lists_and_existing_approvals_are_backfilled(): void
    {
        Setting::query()->create(['key' => 'policy', 'value' => ['master_allowlist' => ['legacy.example.org'], 'master_blocklist' => ['bad.example.org']]]);
        $request = $this->pendingRequest(['old-approval.example.org'], Reach::types('staff', 'student'), ['status' => RequestStatus::ActionRequired]);

        $this->artisan('itrequests:upgrade', ['--skip-migrations' => true])->assertSuccessful();
        $this->artisan('itrequests:upgrade', ['--skip-migrations' => true])->assertSuccessful();

        $student = FilterPolicy::query()->where('audience', 'student')->firstOrFail();
        $desired = $this->engine->desired($student);
        $this->assertSame(['legacy.example.org', 'old-approval.example.org'], $desired['allow']);
        $this->assertSame(['bad.example.org'], $desired['block']);
        $this->assertSame(2, PolicyEntry::query()->where('website_request_id', $request->id)->count());
        $this->assertSame(config('version.app'), app(SettingsRepository::class)->get('installation.version'));
    }

    public function test_list_pages_use_the_compact_pager(): void
    {
        $list = $this->engine->defaultAllowList('student');
        foreach (range(1, 105) as $index) {
            $this->engine->upsertEntry($list, "page{$index}.example.org", ['source' => 'manual']);
        }

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.policy.lists.show', $list))
            ->assertOk()->assertSee('class="pager"', false)->assertSee('1–100 of 105', false)->assertDontSee('<svg', false);
    }
}
