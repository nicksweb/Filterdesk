<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\FilterPolicy;
use App\Models\IntegrationConfig;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\Network\MockNetworkProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

class BlockRequestTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    public function test_staff_can_ask_for_a_site_to_be_blocked_for_students_and_it_reaches_the_student_filter(): void
    {
        Notification::fake();
        Cache::flush();
        $engine = $this->installWithPolicies('active');
        IntegrationConfig::query()->where('provider', 'mock')->update(['configuration' => ['auto_push' => true, 'controller_authoritative' => false]]);
        $students = FilterPolicy::query()->where('audience', 'student')->firstOrFail();
        $students->update(['provider_reference' => 'sim-filter-students']);
        $engine->upsertEntry($engine->defaultAllowList(null), 'games.example', ['source' => 'manual']);
        $this->travel(1)->minute();
        $teacher = User::factory()->teacher()->create();

        $this->actingAs($teacher)->get(route('requests.create', ['type' => 'block', 'domains' => 'games.example']))->assertOk()->assertSee('Request a website block')->assertSee('Block it on')->assertSee('Students');
        $this->actingAs($teacher)->post(route('requests.store'), [
            'request_type' => 'block', 'domains' => 'games.example', 'access_scope' => 'students', 'category' => 'Teaching and learning',
            'use_case' => 'Students keep playing this during maths lessons.', 'standards_confirmed' => '1', 'access_duration' => 'permanent',
        ])->assertRedirect();

        $request = WebsiteRequest::query()->firstOrFail();
        $this->assertTrue($request->isBlock());
        $this->assertSame('student', $request->access_scope->value());

        $it = User::factory()->it()->create();
        $this->actingAs($it)->get(route('requests.show', $request))->assertOk()->assertSee('Block selected hostnames');
        $this->actingAs($it)->post(route('it.requests.action', $request), ['action' => 'approve', 'domains' => ['games.example', 'www.games.example']])->assertSessionHas('success');

        $desired = $engine->desired($students->refresh());
        $this->assertContains('games.example', $desired['block'], 'The student block beats the older site-wide allow');
        $this->assertContains('games.example', $engine->desired(FilterPolicy::query()->where('audience', 'staff')->firstOrFail())['allow'], 'Staff keep access');
        $this->assertContains('games.example', collect((new MockNetworkProvider)->contentFilters())->firstWhere('id', 'sim-filter-students')['block']);
        $this->assertSame(RequestStatus::Active, $request->refresh()->status);
    }

    public function test_the_check_page_offers_both_request_types(): void
    {
        $this->installWithPolicies();
        $this->actingAs(User::factory()->teacher()->create())->get(route('requests.create', ['type' => 'block']))->assertOk()->assertSee('Submit block request');
        $this->actingAs(User::factory()->teacher()->create())->post(route('requests.store'), [
            'request_type' => 'allow', 'domains' => 'x.example', 'network_types' => ['guest'], 'category' => 'Teaching and learning',
            'use_case' => 'A twenty character use case.', 'standards_confirmed' => '1', 'access_duration' => 'permanent',
        ])->assertSessionHasErrors('network_types.0');
    }
}
