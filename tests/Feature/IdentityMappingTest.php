<?php

namespace Tests\Feature;

use App\Models\IntegrationConfig;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\Auth\UserProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/** Sign-in groups set a person's network types and department. */
class IdentityMappingTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->installWithPolicies();
        IntegrationConfig::query()->create(['provider' => 'oidc', 'name' => 'OpenID Connect', 'mode' => 'active', 'enabled' => true, 'configuration' => [
            'issuer' => 'https://login.example.test', 'client_id' => 'c', 'default_role' => 'teacher',
            'type_rules' => UserProvisioningService::parseMappingRules("group:science=staff\ngroup:boarding=student", 'type_rules'),
            'department_rules' => UserProvisioningService::parseMappingRules("group:science=Science\ngroup:english=English", 'department_rules'),
        ], 'credentials' => ['client_secret' => 's']]);
    }

    public function test_groups_set_network_types_and_department_and_requests_use_them(): void
    {
        $user = app(UserProvisioningService::class)->provision(['sub' => 'abc', 'email' => 'teacher@example.test', 'name' => 'Teacher', 'groups' => ['Science', 'Boarding']]);

        $this->assertSame(['staff', 'student'], $user->network_types);
        $this->assertSame('Science', $user->department);

        $this->actingAs($user)->get(route('requests.create'))->assertOk();
        $this->actingAs($user)->post(route('requests.store'), [
            'domains' => 'lab.example.org', 'network_types' => ['staff'], 'category' => 'Teaching and learning', 'use_case' => 'Science lab simulations for Year 10',
            'access_duration' => 'permanent', 'standards_confirmed' => '1',
        ]);
        $this->assertSame('Science', WebsiteRequest::query()->where('requester_id', $user->id)->latest('id')->value('department'));
    }

    public function test_type_rules_must_name_existing_types(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('admin.settings.update', 'authentication'), [
            'default_role' => 'teacher', 'emergency_local_login' => '1', 'type_rules' => 'group:x=nonsense',
        ])->assertSessionHasErrors('type_rules');
    }
}
