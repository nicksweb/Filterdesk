<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

class RequestFormSettingsTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->installWithPolicies();
        app(SettingsRepository::class)->set('approval', ['categories' => ['Teaching and learning'], 'students_can_request' => true]);
    }

    private function submit(User $user, array $overrides = []): TestResponse
    {
        return $this->actingAs($user)->post(route('requests.store'), $overrides + [
            'request_type' => 'allow', 'domains' => 'learn.example.org', 'network_types' => ['student'],
            'standards_confirmed' => '1', 'access_duration' => 'permanent',
        ]);
    }

    public function test_the_reason_is_optional_for_staff_required_for_students_and_students_get_their_own_categories(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put(route('admin.settings.update', 'approval'), [
            'default_temporary_days' => 30, 'categories' => "Teaching and learning\nAdministration",
            'students_can_request' => '1',
            'form' => ['access_label' => 'Class and unit', 'access_hint' => 'Year 9 Science, unit 3', 'staff_required' => '0', 'student_required' => '1',
                'min_length' => 5, 'student_categories' => "Homework\nAssignment research", 'short_form' => 'nobody'],
        ])->assertSessionHas('success');

        $teacher = User::factory()->teacher()->create();
        $this->actingAs($teacher)->get(route('requests.create'))->assertOk()
            ->assertSee('Class and unit')->assertSee('Year 9 Science, unit 3')->assertSee('optional')->assertSee('Administration')->assertDontSee('Homework');
        $this->submit($teacher, ['category' => 'Administration'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(WebsiteRequest::query()->sole()->use_case);

        $student = User::factory()->student()->create();
        $this->actingAs($student)->get(route('requests.create'))->assertOk()->assertSee('Homework')->assertDontSee('Administration');
        $this->submit($student, ['category' => 'Homework'])->assertSessionHasErrors('use_case');
        $this->submit($student, ['category' => 'Administration', 'use_case' => 'For my science homework'])->assertSessionHasErrors('category');
        $this->submit($student, ['category' => 'Homework', 'use_case' => 'For my science homework'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, WebsiteRequest::query()->count());
    }
}
