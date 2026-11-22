<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every exception IT or a request made has an owner and a review date.
 * Entries due for review appear in the review queue; one nobody reviews
 * expires after a grace period. last_seen_at records when the site last
 * came up in a check or request, to flag stale entries.
 *
 * Existing entries get a review date a year after they were made, but never
 * sooner than four weeks from now, so nothing expires because of the upgrade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_entries', function (Blueprint $table): void {
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('review_at')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('review_notified_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
        });

        $floor = now()->addWeeks(4);
        DB::table('policy_entries')->whereIn('source', ['manual', 'request'])->orderBy('id')->chunkById(1000, function ($entries) use ($floor): void {
            foreach ($entries as $entry) {
                $owner = $entry->website_request_id ? DB::table('website_requests')->where('id', $entry->website_request_id)->value('requester_id') : $entry->added_by_id;
                $review = max($floor, Carbon::parse($entry->created_at ?? now())->addYear());
                DB::table('policy_entries')->where('id', $entry->id)->update(['owner_id' => $owner, 'review_at' => $review]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('policy_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('owner_id');
            $table->dropConstrainedForeignId('reviewed_by_id');
            $table->dropColumn(['review_at', 'reviewed_at', 'review_notified_at', 'last_seen_at']);
        });
    }
};
