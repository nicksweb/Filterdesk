<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * School-hours layers: a second filter for the same network type that UniFi
 * switches on by its own schedule (for example "Student - School Time" next
 * to "Students - All Hours"). It keeps its allow and block lists; the
 * parent's allows go into it too, so an approval works at every hour. An
 * allow can be kept out of it ("outside school hours only").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('filter_policies', function (Blueprint $table): void {
            $table->string('layer_kind', 20)->nullable();
        });
        Schema::table('policy_entries', function (Blueprint $table): void {
            $table->string('hours', 20)->nullable();
        });
        Schema::table('website_requests', function (Blueprint $table): void {
            $table->string('hours', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('filter_policies', fn (Blueprint $table) => $table->dropColumn('layer_kind'));
        Schema::table('policy_entries', fn (Blueprint $table) => $table->dropColumn('hours'));
        Schema::table('website_requests', fn (Blueprint $table) => $table->dropColumn('hours'));
    }
};
