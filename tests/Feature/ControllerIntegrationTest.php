<?php

namespace Tests\Feature;

use App\Enums\Audience;
use App\Enums\ListKind;
use App\Enums\ListScope;
use App\Models\AuditEvent;
use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Models\NetworkAssignment;
use App\Models\PolicyEntry;
use App\Models\PolicyList;
use App\Models\User;
use App\Services\Policy\PolicyEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

class ControllerIntegrationTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private const CONSOLE = 'CONSOLE1:12345';

    private PolicyEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->engine = $this->installWithPolicies();
    }

    private function useUniFi(): void
    {
        IntegrationConfig::query()->where('provider', 'mock')->update(['enabled' => false]);
        IntegrationConfig::query()->create(['provider' => 'unifi', 'name' => 'UniFi', 'mode' => 'read_only', 'enabled' => true,
            'configuration' => ['connection' => 'cloud', 'console_id' => self::CONSOLE], 'credentials' => ['api_key' => 'key']]);

        $base = 'api.ui.com/v1/connector/consoles/'.rawurlencode(self::CONSOLE).'/proxy/network';
        Http::fake([
            "{$base}/v2/api/site/default/content-filtering" => Http::response([
                ['_id' => 'cf-students', 'name' => 'Student filter', 'enabled' => true, 'allow_list' => ['allowed.example.org', 'handmade.example.org'], 'block_list' => ['games.example.org', 'wanted.example.org'], 'network_ids' => ['net-student'], 'categories' => ['ADULT']],
                ['_id' => 'cf-staff', 'name' => 'Staff filter', 'enabled' => true, 'allow_list' => [], 'block_list' => [], 'network_ids' => [], 'categories' => []],
            ]),
            "{$base}/api/s/default/rest/networkconf" => Http::response(['data' => [
                ['_id' => 'net-student', 'name' => 'Students', 'purpose' => 'corporate', 'vlan' => 20],
                ['_id' => 'net-staff', 'name' => 'Staff', 'purpose' => 'corporate', 'vlan' => 10],
                ['_id' => 'net-wan', 'name' => 'Internet', 'purpose' => 'wan'],
            ]]),
        ]);
    }

    public function test_linking_a_controller_filter_fetches_its_lists_and_maps_its_networks(): void
    {
        $this->useUniFi();
        $admin = User::factory()->admin()->create();
        $student = FilterPolicy::query()->where('audience', Audience::Student)->firstOrFail();
        $this->engine->upsertEntry($this->engine->defaultAllowList(Audience::Student), 'allowed.example.org', ['source' => 'manual']);
        $this->engine->upsertEntry($this->engine->defaultAllowList(null), 'wanted.example.org', ['source' => 'manual']);

        $this->actingAs($admin)->get(route('admin.policy.networks.index'))
            ->assertOk()->assertSee('Student filter')->assertSee('VLAN 20', false)->assertDontSee('Internet');

        $this->actingAs($admin)->post(route('admin.policy.networks.link'), ['filter_id' => 'cf-students', 'filter_policy_id' => $student->id])
            ->assertRedirect()->assertSessionHas('success');

        $student->refresh();
        $this->assertSame('Student filter', $student->provider_name);
        $this->assertSame($student->id, NetworkAssignment::query()->where('provider_network_id', 'net-student')->value('filter_policy_id'));

        $report = $this->engine->reconcile($student);
        $this->assertSame(['handmade.example.org'], $report['allow_review']);
        $this->assertSame(['games.example.org'], $report['block_review'], 'A hand-made controller block is reviewed, not silently removed');
        $this->assertSame(['wanted.example.org'], $report['block_remove']);
        $this->assertSame(['wanted.example.org'], $report['controller_blocks_allowed']);

        $this->actingAs($admin)->get(route('admin.policy.networks.index'))->assertOk()->assertSee('Matches');
    }

    public function test_hand_made_controller_entries_can_be_imported_into_a_managed_list(): void
    {
        $this->useUniFi();
        $it = User::factory()->it()->create();
        $student = FilterPolicy::query()->where('audience', Audience::Student)->firstOrFail();
        $student->update(['provider_reference' => 'cf-students']);

        $this->actingAs($it)->post(route('admin.policy.policies.pull', $student))->assertSessionHas('success');
        $studentBlock = PolicyList::query()->where('scope', ListScope::Audience)->where('audience', Audience::Student)->where('kind', ListKind::Block)->firstOrFail();

        $this->actingAs($it)->post(route('admin.policy.policies.adopt', $student), [
            'kind' => 'block', 'domains' => ['games.example.org', 'not-in-controller.example.org'], 'policy_list_id' => $studentBlock->id,
        ])->assertSessionHas('success');

        $this->assertSame(['games.example.org'], $studentBlock->entries()->pluck('domain')->all());
        $this->assertSame(['wanted.example.org'], $this->engine->reconcile($student->refresh())['block_review']);

        $staffAllow = $this->engine->defaultAllowList(Audience::Staff);
        $this->actingAs($it)->post(route('admin.policy.policies.adopt', $student), [
            'kind' => 'allow', 'domains' => ['handmade.example.org'], 'policy_list_id' => $staffAllow->id,
        ])->assertSessionHasErrors('policy_list_id');
    }

    public function test_only_admins_change_the_network_mapping(): void
    {
        $it = User::factory()->it()->create();
        $staff = FilterPolicy::query()->where('audience', Audience::Staff)->firstOrFail();
        NetworkAssignment::query()->create(['provider_network_id' => 'sim-guest', 'name' => 'Guest Wi-Fi', 'vlan' => 80]);

        $this->actingAs($it)->get(route('admin.policy.networks.index'))->assertOk()->assertSee('Guest Wi-Fi');
        $this->actingAs($it)->put(route('admin.policy.networks.update'), ['assignments' => ['sim-guest' => $staff->id]])->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())->put(route('admin.policy.networks.update'), ['assignments' => ['sim-guest' => $staff->id]])
            ->assertSessionHas('success');
        $this->assertSame($staff->id, NetworkAssignment::query()->where('provider_network_id', 'sim-guest')->value('filter_policy_id'));
    }

    public function test_adding_an_already_allowed_hostname_says_where_it_is(): void
    {
        $admin = User::factory()->admin()->create();
        $this->engine->upsertEntry($this->engine->defaultAllowList(Audience::Student), 'school.example.org', ['source' => 'manual']);
        FilterPolicy::query()->where('audience', Audience::Student)->update(['current_blocklist' => ['school.example.org'], 'snapshot_at' => now()]);
        $siteAllow = $this->engine->defaultAllowList(null);

        $this->actingAs($admin)->post(route('admin.policy.entries.store'), ['policy_list_id' => $siteAllow->id, 'domains' => 'school.example.org'])
            ->assertRedirect(route('admin.policy.lists.show', $siteAllow))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'also in Student allow') && str_contains($message, 'blocks school.example.org'));
    }

    public function test_scanner_lists_page_and_certificate_hosts_and_adds_the_selection(): void
    {
        Http::fake([
            'crt.sh/*' => Http::response([['name_value' => "*.example.com\nplay.example.com"], ['name_value' => 'other.test']]),
            '*' => fn ($request) => Http::response(['results' => array_map(fn () => [
                'status' => 'allowed', 'duration_ms' => 100,
                'hosts' => [['host' => 'www.example.com', 'requests' => 3, 'failed' => false], ['host' => 'cdn.examplecdn.net', 'requests' => 0, 'failed' => true]],
            ], $request['urls'] ?? [])]),
        ]);
        $it = User::factory()->it()->create();

        $this->actingAs($it)->post(route('admin.policy.scan.store'), ['websites' => 'https://www.example.com', 'certificates' => '1'])
            ->assertOk()->assertSee('play.example.com')->assertSee('cdn.examplecdn.net')->assertSee('Failed on Student', false)->assertDontSee('other.test');

        $siteBlock = PolicyList::query()->where('scope', ListScope::Site)->where('kind', ListKind::Block)->firstOrFail();
        $this->actingAs($it)->post(route('admin.policy.entries.store'), ['policy_list_id' => $siteBlock->id, 'hosts' => ['example.com', 'play.example.com']])
            ->assertSessionHas('success');
        $this->assertEqualsCanonicalizing(['example.com', 'play.example.com'], $siteBlock->entries()->pluck('domain')->all());
    }

    public function test_admin_can_view_as_a_student_and_return(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.preview', 'student'))->assertRedirect(route('dashboard'));
        $preview = User::query()->where('email', 'preview-student@preview.invalid')->firstOrFail();
        $this->assertAuthenticatedAs($preview);
        $this->assertTrue($preview->isStudent());

        $this->get(route('dashboard'))->assertOk()->assertSee('Viewing as')->assertSee('Return to my account');
        $this->get(route('admin.policy.index'))->assertForbidden();
        $this->assertNotContains($preview->id, User::query()->itStaff()->pluck('id')->all());

        $this->post(route('impersonation.stop'))->assertRedirect(route('admin.users.index'));
        $this->assertAuthenticatedAs($admin);
        $this->assertSame($admin->id, AuditEvent::query()->where('action', 'user.impersonation_ended')->value('metadata')['impersonator_id'] ?? null);
    }

    public function test_admins_and_non_admins_cannot_be_misused_for_impersonation(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $teacher = User::factory()->teacher()->create();

        $this->actingAs($admin)->post(route('admin.users.impersonate', $otherAdmin))->assertForbidden();
        $this->actingAs($teacher)->post(route('admin.users.impersonate', User::factory()->student()->create()))->assertForbidden();
        $this->actingAs($teacher)->post(route('impersonation.stop'))->assertForbidden();

        $this->actingAs($admin)->post(route('admin.users.impersonate', $teacher))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($teacher);
        $this->post(route('admin.users.impersonate', User::factory()->student()->create()))->assertForbidden();
    }

    public function test_entries_survive_when_scanner_input_is_invalid(): void
    {
        $it = User::factory()->it()->create();
        $this->actingAs($it)->post(route('admin.policy.scan.store'), ['websites' => 'ftp://example.com'])->assertSessionHasErrors('websites');
        $this->assertSame(0, PolicyEntry::query()->count());
    }
}
