<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * With "IP lists only", a list that grows into a new UniFi IP list part is
 * not in any rule IT made: the new parts are kept here, shown on the list
 * until IT confirms they've added them to their rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firewall_lists', function (Blueprint $table): void {
            $table->json('new_parts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('firewall_lists', function (Blueprint $table): void {
            $table->dropColumn('new_parts');
        });
    }
};
