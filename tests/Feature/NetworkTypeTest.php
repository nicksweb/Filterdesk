<?php

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Models\FilterPolicy;
use App\Models\NetworkType;
use App\Models\PolicyList;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\Policy\PolicyEngine;
use App\Services\Requests\RequestWorkflow;
use App\Support\Reach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

class NetworkTypeTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private PolicyEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->engine = $this->installWithPolicies();
    }

    public function test_an_admin_defines_a_network_type_and_requests_for_it_reach_only_its_policies(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put(route('admin.settings.update', 'types'), ['new' => ['name' => 'Exam rooms', 'description' => 'Locked down during exams']])
            ->assertSessionHas('success');

        $type = NetworkType::query()->where('key', 'exam_rooms')->sole();
        $this->assertTrue($type->requestable);
        $this->assertTrue(PolicyList::query()->where('scope', 'audience')->where('audience', 'exam_rooms')->where('kind', ListKind::Allow)->exists(), 'The type gets its own lists straight away');

        $this->actingAs($admin)->post(route('admin.policy.policies.store'), ['name' => 'Exam hall', 'audience' => 'exam_rooms'])->assertRedirect();
        $exam = FilterPolicy::query()->where('audience', 'exam_rooms')->sole();
        $this->assertSame('Exam rooms', $exam->typeLabel());

        $teacher = User::factory()->teacher()->create();
        $this->actingAs($teacher)->get(route('requests.create'))->assertOk()->assertSee('Exam rooms')->assertSee('Locked down during exams');
        $this->actingAs($teacher)->post(route('requests.store'), [
            'request_type' => 'allow', 'domains' => 'exam.example.org', 'network_types' => ['exam_rooms'], 'category' => 'Teaching and learning',
            'use_case' => 'Needed for the online exam in the hall.', 'standards_confirmed' => '1', 'access_duration' => 'permanent',
        ])->assertRedirect();
        $request = WebsiteRequest::query()->sole();
        $this->assertSame('exam_rooms', $request->access_scope->value());
        $this->assertSame('Exam rooms', $request->access_scope->label());

        $this->actingAs($admin)->get(route('admin.settings.show', 'types'))->assertOk()->assertSee('Add a network type')->assertSee('exam_rooms');
        $this->actingAs($admin)->get(route('requests.show', $request))->assertOk()->assertSee('Exam rooms (requested)')->assertSee('Site-wide (every network)');
        $this->actingAs($admin)->get(route('admin.policy.index'))->assertOk()->assertSee('Exam hall');

        app(RequestWorkflow::class)->approve($request, $admin);
        $this->assertContains('exam.example.org', $this->engine->desired($exam)['allow']);
        $staff = FilterPolicy::query()->where('audience', 'staff')->sole();
        $this->assertNotContains('exam.example.org', $this->engine->desired($staff)['allow'], 'Other types are not affected');

        $this->actingAs($admin)->put(route('admin.settings.update', 'types'), ['types' => [$type->id => ['name' => 'Exam rooms', 'delete' => '1']]])
            ->assertSessionHasErrors("types.{$type->id}.delete");
        $this->assertTrue(NetworkType::query()->whereKey($type->id)->exists());
    }

    public function test_renaming_a_type_relabels_everything_and_one_type_must_stay_requestable(): void
    {
        $admin = User::factory()->admin()->create();
        $students = NetworkType::query()->where('key', 'student')->sole();
        $staff = NetworkType::query()->where('key', 'staff')->sole();

        $this->actingAs($admin)->put(route('admin.settings.update', 'types'), ['types' => [
            $students->id => ['name' => 'Pupils', 'requestable' => '1'],
            $staff->id => ['name' => 'Staff', 'requestable' => '1', 'auto_approve' => '1'],
        ]])->assertSessionHas('success');
        $this->assertSame('Pupils', FilterPolicy::query()->where('audience', 'student')->sole()->typeLabel());
        $this->assertSame('Staff and Pupils', Reach::types('staff', 'student')->label());

        $this->actingAs($admin)->put(route('admin.settings.update', 'types'), ['types' => NetworkType::query()->get()->mapWithKeys(fn ($type) => [$type->id => ['name' => $type->name, 'requestable' => '0']])->all()])
            ->assertSessionHasErrors('types');
    }

    public function test_old_fixed_scopes_are_still_understood(): void
    {
        $this->assertSame('staff,student', Reach::from('staff_students')->value());
        $this->assertSame('student', Reach::from('students')->value());
        $this->assertTrue(Reach::from('site')->siteWide);
        $this->assertSame('staff,student', Reach::types('student', 'staff', 'staff')->value(), 'Keys are sorted and unique');
        $this->assertNull(Reach::tryFrom(''));
    }
}
