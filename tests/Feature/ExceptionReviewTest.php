<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\FilterPolicy;
use App\Models\PolicyEntry;
use App\Models\User;
use App\Notifications\ExceptionsDueNotification;
use App\Services\Policy\PolicyEngine;
use App\Services\SettingsRepository;
use App\Support\Reach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPolicies;
use Tests\TestCase;

/**
 * Exceptions made by IT or from requests have an owner and a review date;
 * owners are told when one is due, and one nobody keeps is removed after
 * the grace period (2 weeks by default).
 */
class ExceptionReviewTest extends TestCase
{
    use BuildsPolicies, RefreshDatabase;

    private PolicyEngine $engine;

    private User $it;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::flush();
        $this->engine = $this->installWithPolicies();
        $this->it = User::factory()->it()->create();
        app(SettingsRepository::class)->set('notifications.it_address', 'it@example.test');
    }

    public function test_new_exceptions_get_an_owner_and_a_review_date_but_feeds_do_not(): void
    {
        $manual = $this->engine->upsertEntry($this->engine->defaultAllowList('staff'), 'tool.example.org', ['source' => 'manual', 'added_by_id' => $this->it->id]);
        $request = $this->pendingRequest(['lesson.example.org'], Reach::types('staff'));
        $fromRequest = $this->engine->upsertEntry($this->engine->defaultAllowList('staff'), 'lesson.example.org', ['source' => 'request', 'website_request_id' => $request->id]);
        $feed = $this->engine->upsertEntry($this->engine->defaultBlockList('staff'), 'ads.example.org', ['source' => 'feed']);

        $this->assertSame($this->it->id, $manual->owner_id);
        $this->assertSame($request->requester_id, $fromRequest->owner_id, 'A request entry belongs to the requester');
        $this->assertTrue($manual->review_at->between(now()->addMonths(12)->subMinute(), now()->addMonths(12)->addMinute()));
        $this->assertNull($feed->review_at);
    }

    public function test_due_exceptions_are_emailed_kept_or_removed_after_two_weeks(): void
    {
        $owner = User::factory()->teacher()->create();
        $kept = $this->engine->upsertEntry($this->engine->defaultAllowList('staff'), 'keep.example.org', ['source' => 'manual', 'added_by_id' => $owner->id]);
        $left = $this->engine->upsertEntry($this->engine->defaultAllowList('staff'), 'left.example.org', ['source' => 'manual', 'added_by_id' => $owner->id]);
        $staff = fn () => $this->engine->desired(FilterPolicy::query()->where('audience', 'staff')->firstOrFail())['allow'];

        $this->travel(12)->months();
        $this->travel(1)->day();
        $this->artisan('exceptions:review')->assertSuccessful();
        Notification::assertSentTo($owner, ExceptionsDueNotification::class);
        Notification::assertSentOnDemand(ExceptionsDueNotification::class);

        $this->actingAs($owner)->get(route('reviews.index'))->assertOk()->assertSee('keep.example.org')->assertSee('left.example.org');
        $this->actingAs($owner)->post(route('reviews.keep'), ['entries' => [$kept->id]])->assertSessionHas('success');
        $this->assertTrue($kept->refresh()->review_at->isFuture());
        $this->assertSame($owner->id, $kept->reviewed_by_id);

        $this->travel(14)->days();
        $this->artisan('exceptions:review')->assertSuccessful();
        $this->travel(10)->minutes();
        $this->assertContains('keep.example.org', $staff());
        $this->assertNotContains('left.example.org', $staff(), 'Removed two weeks after its review date (after the usual closing margin)');
        $this->assertStringContainsString('Expired: not reviewed', $left->refresh()->note);
    }

    public function test_someone_else_cannot_keep_an_exception_they_do_not_own(): void
    {
        $owner = User::factory()->teacher()->create();
        $other = User::factory()->teacher()->create();
        $entry = $this->engine->upsertEntry($this->engine->defaultAllowList('staff'), 'mine.example.org', ['source' => 'manual', 'added_by_id' => $owner->id]);
        $this->travel(13)->months();

        $this->actingAs($other)->post(route('reviews.keep'), ['entries' => [$entry->id]]);

        $this->assertNull($entry->refresh()->reviewed_at);
    }

    public function test_checks_and_requests_mark_entries_as_seen(): void
    {
        $entry = $this->engine->upsertEntry($this->engine->defaultAllowList('staff'), 'example.org', ['source' => 'manual']);
        PolicyEntry::markSeen(['www.example.org']);

        $this->assertNotNull($entry->refresh()->last_seen_at);
        $this->assertFalse($entry->isStale());
    }

    public function test_the_report_counts_requests_and_suggests_policy_changes(): void
    {
        $categories = ['ok' => true, 'categories' => ['Video Streaming'], 'risks' => [], 'unifi' => [['id' => 'STREAMING_MEDIA', 'label' => 'Streaming media', 'blocked_by' => []]]];
        foreach (range(1, 5) as $index) {
            $this->pendingRequest(["video{$index}.example.org"], Reach::types('staff'), [
                'status' => RequestStatus::Active, 'approved_at' => now()->addHours(2), 'domain_categories' => ["video{$index}.example.org" => $categories],
            ]);
        }
        $this->pendingRequest(['no.example.org'], Reach::types('staff'), ['status' => RequestStatus::Rejected, 'resolution' => 'Not for teaching', 'resolved_at' => now()]);

        $this->actingAs($this->it)->get(route('admin.insights'))->assertOk()
            ->assertSee('5 Streaming media requests approved for Staff')->assertSee('Not for teaching')->assertSee('Print or save as PDF');
        $csv = $this->actingAs($this->it)->get(route('admin.insights.csv'))->assertOk()->streamedContent();
        $this->assertStringContainsString('video1.example.org', $csv);
        $this->assertSame(7, substr_count(trim($csv), "\n") + 1, 'A header and one row per request');
    }
}
