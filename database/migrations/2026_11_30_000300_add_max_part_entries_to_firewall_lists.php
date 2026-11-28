<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UniFi limits an IP list by its stored size (about 126 KB), not by entries.
 * When it refuses a part, the list remembers how many of its entries fit
 * and is split at that size from then on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firewall_lists', function (Blueprint $table): void {
            $table->unsignedInteger('max_part_entries')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('firewall_lists', function (Blueprint $table): void {
            $table->dropColumn('max_part_entries');
        });
    }
};
