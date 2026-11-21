<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** How a network hands out addresses: by UniFi ("unifi"), a DHCP relay to another server ("relay"), or not at all ("none"). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_assignments', function (Blueprint $table): void {
            $table->string('dhcp_mode', 10)->nullable()->after('dhcp_stop');
        });
    }

    public function down(): void
    {
        Schema::table('network_assignments', function (Blueprint $table): void {
            $table->dropColumn('dhcp_mode');
        });
    }
};
