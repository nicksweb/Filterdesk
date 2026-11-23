<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-person approval: a request for site-wide access or a sensitive
 * category keeps the first IT officer's approval until a second officer
 * confirms it, and a hand-made change to a site-wide list waits as a
 * proposal (pending add or removal) until a second officer confirms it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_requests', function (Blueprint $table): void {
            $table->json('first_approval')->nullable();
        });
        Schema::table('policy_entries', function (Blueprint $table): void {
            $table->string('pending_action', 10)->nullable()->index();
            $table->foreignId('proposed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('proposed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('policy_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('proposed_by_id');
            $table->dropColumn(['pending_action', 'proposed_at']);
        });
        Schema::table('website_requests', function (Blueprint $table): void {
            $table->dropColumn('first_approval');
        });
    }
};
