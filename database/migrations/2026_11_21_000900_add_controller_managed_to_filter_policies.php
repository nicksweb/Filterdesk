<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hostnames this app wrote into a controller filter. When the app stops
 * wanting one (an entry deleted, a feed switched off), it is removed from
 * the controller rather than mistaken for a hand-made entry and imported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('filter_policies', function (Blueprint $table): void {
            $table->json('controller_managed')->nullable()->after('controller_exclusions');
        });
    }

    public function down(): void
    {
        Schema::table('filter_policies', function (Blueprint $table): void {
            $table->dropColumn('controller_managed');
        });
    }
};
