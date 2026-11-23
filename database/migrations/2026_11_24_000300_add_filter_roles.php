<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learned structure: a policy is a group filter (one type of user) or a
 * site-wide layer (a filter on nearly every network); one site-wide layer
 * takes the site-wide blocks. Networks are user or device networks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('filter_policies', function (Blueprint $table): void {
            $table->string('role', 20)->default('group');
            $table->boolean('receives_site_blocks')->default(false);
        });
        Schema::table('network_assignments', function (Blueprint $table): void {
            $table->string('role', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('filter_policies', fn (Blueprint $table) => $table->dropColumn(['role', 'receives_site_blocks']));
        Schema::table('network_assignments', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
