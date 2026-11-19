<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\Setting;
use App\Models\User;
use App\Models\WebsiteRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::query()->create(['key' => 'installation.completed', 'value' => true]);
    }

    public function test_admin_can_create_a_local_dummy_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Dummy Teacher',
            'email' => 'dummy.teacher@example.edu.au',
            'role' => 'teacher',
            'password' => 'temporary-password-2026',
            'password_confirmation' => 'temporary-password-2026',
            'enabled' => '1',
        ])->assertRedirect();

        $user = User::query()->where('email', 'dummy.teacher@example.edu.au')->firstOrFail();
        $this->assertSame('teacher', $user->role);
        $this->assertTrue($user->enabled);
        $this->assertTrue(Hash::check('temporary-password-2026', $user->password));
    }

    public function test_policy_comparison_combines_master_entries_and_approved_requests(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $teacher = User::factory()->create(['role' => 'teacher']);
        WebsiteRequest::query()->create([
            'reference' => 'WEB-2026-00020',
            'requester_id' => $teacher->id,
            'domains' => ['approved.example.org'],
            'access_scope' => 'staff_students',
            'category' => 'Teaching and learning',
            'use_case' => 'An approved resource required for a classroom teaching activity.',
            'standards_confirmed' => true,
            'status' => RequestStatus::Active,
        ]);

        $this->actingAs($admin)->post(route('admin.policy.master'), [
            'master_allowlist' => "permanent.example.org\nwww\\.escaped.example.org",
            'master_blocklist' => "blocked.example.org\napproved.example.org",
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('admin.policy.compare'), [
            'current_allowlist' => 'permanent.example.org',
            'current_blocklist' => "blocked.example.org\napproved.example.org",
        ])->assertRedirect();

        $this->actingAs($admin)->get(route('admin.policy.index'))
            ->assertOk()
            ->assertSee('approved.example.org')
            ->assertSee('www.escaped.example.org')
            ->assertSee('Remove from block list');
    }
}
