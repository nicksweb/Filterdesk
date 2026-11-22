<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Recurring access or block patterns: on requests (as asked) and on the list entries they create. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_requests', function (Blueprint $table): void {
            $table->json('schedule')->nullable();
        });
        Schema::table('policy_entries', function (Blueprint $table): void {
            $table->json('schedule')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('website_requests', function (Blueprint $table): void {
            $table->dropColumn('schedule');
        });
        Schema::table('policy_entries', function (Blueprint $table): void {
            $table->dropColumn('schedule');
        });
    }
};
