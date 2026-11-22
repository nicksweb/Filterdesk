<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the result of the last automatic comparison between a policy and
 * its controller filter, and the controller lists last known to be correct
 * (after a push, or when IT accepted the controller's state), so manual
 * edits made in the controller can be told apart from pending changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('filter_policies', function (Blueprint $table): void {
            $table->string('sync_status', 20)->nullable()->after('snapshot_by_id');
            $table->timestamp('sync_checked_at')->nullable()->after('sync_status');
            $table->json('sync_details')->nullable()->after('sync_checked_at');
            $table->json('sync_baseline')->nullable()->after('sync_details');
        });
    }

    public function down(): void
    {
        Schema::table('filter_policies', function (Blueprint $table): void {
            $table->dropColumn(['sync_status', 'sync_checked_at', 'sync_details', 'sync_baseline']);
        });
    }
};
