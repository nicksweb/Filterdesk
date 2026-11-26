<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firewall allow lists: an IP list whose rules let its addresses through
 * the firewall (placed above the block rules for the same zones), holding
 * addresses, subnets and domains with a note, request, expiry and who added
 * each one. Optionally served at a secret URL as plain text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firewall_lists', function (Blueprint $table): void {
            $table->string('kind', 10)->default('block');
            $table->json('allow_entries')->nullable();
            $table->boolean('all_zones')->default(false);
            $table->string('feed_token', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('firewall_lists', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'allow_entries', 'all_zones', 'feed_token']);
        });
    }
};
