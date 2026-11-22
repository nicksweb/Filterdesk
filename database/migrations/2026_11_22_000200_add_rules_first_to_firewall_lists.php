<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the app moves a list's blocking rules above the rules people made
 * for the same zones, so an Allow rule cannot let listed addresses through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firewall_lists', function (Blueprint $table): void {
            $table->boolean('rules_first')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('firewall_lists', function (Blueprint $table): void {
            $table->dropColumn('rules_first');
        });
    }
};
