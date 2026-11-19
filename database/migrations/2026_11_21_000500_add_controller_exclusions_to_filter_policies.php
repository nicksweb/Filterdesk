<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hostnames removed from a controller filter by hand. With the controller
 * as the source of truth they are kept out of that policy's desired lists,
 * so a push never puts them back until IT restores them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('filter_policies', function (Blueprint $table): void {
            $table->json('controller_exclusions')->nullable()->after('sync_baseline');
        });
    }

    public function down(): void
    {
        Schema::table('filter_policies', function (Blueprint $table): void {
            $table->dropColumn('controller_exclusions');
        });
    }
};
