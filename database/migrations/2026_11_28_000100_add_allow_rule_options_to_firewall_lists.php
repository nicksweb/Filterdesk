<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow rule options: limit an allow list's rules to some networks (so a
 * network another rule keeps off the internet stays off), and place them
 * just above the block rules that match its addresses instead of at the top.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firewall_lists', function (Blueprint $table): void {
            $table->json('source_networks')->nullable();
            $table->string('placement', 20)->default('top');
            $table->timestamp('verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('firewall_lists', function (Blueprint $table): void {
            $table->dropColumn(['source_networks', 'placement', 'verified_at']);
        });
    }
};
