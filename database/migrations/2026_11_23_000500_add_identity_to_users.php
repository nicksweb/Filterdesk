<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity mapping: the network types a person uses (from sign-in groups or
 * set by hand), which the request form chooses by default, and their
 * department, which the termly report groups requests by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('network_types')->nullable();
            $table->string('department', 120)->nullable();
        });
        Schema::table('website_requests', function (Blueprint $table): void {
            $table->string('department', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('website_requests', fn (Blueprint $table) => $table->dropColumn('department'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['network_types', 'department']));
    }
};
