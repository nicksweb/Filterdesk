<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IP feed processing: options (collapse dense /24s) and what the last
 * processing did (read, dropped, merged, kept), so large lists such as
 * AbuseIPDB fit a UniFi firewall list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('list_sources', function (Blueprint $table): void {
            $table->json('options')->nullable();
            $table->json('stats')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('list_sources', function (Blueprint $table): void {
            $table->dropColumn(['options', 'stats']);
        });
    }
};
