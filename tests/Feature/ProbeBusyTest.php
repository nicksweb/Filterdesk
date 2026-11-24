<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * A probe that is at its limit of concurrent checks answers "busy" after the
 * queue wait; the person checking sees that message for that network, and
 * the other networks' results still show.
 */
class ProbeBusyTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    public function test_a_busy_probe_is_reported_and_the_others_still_answer(): void
    {
        Notification::fake();
        Cache::flush();
        $this->installWithPolicies();
        Http::fake([
            'probe-student:3000/*' => Http::response(['status' => 'probe_busy', 'message' => 'This probe is busy with 6 other check(s). Try again in a moment.'], 503),
            'probe-staff:3000/*' => Http::response(['results' => [['status' => 'allowed', 'http_status' => 200, 'page_title' => 'Example', 'final_url' => 'https://example.org/', 'hosts' => []]]]),
        ]);

        $this->actingAs(User::factory()->admin()->create())->post(route('checks.store'), ['websites' => 'example.org'])
            ->assertOk()->assertSee('This probe is busy with 6 other check(s)')->assertSee('Example');
    }
}
