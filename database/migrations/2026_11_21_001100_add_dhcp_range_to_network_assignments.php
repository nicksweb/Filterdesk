<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The network's DHCP range, so probe addresses are planned outside it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_assignments', function (Blueprint $table): void {
            $table->string('dhcp_start', 45)->nullable()->after('subnet');
            $table->string('dhcp_stop', 45)->nullable()->after('dhcp_start');
        });
    }

    public function down(): void
    {
        Schema::table('network_assignments', function (Blueprint $table): void {
            $table->dropColumn(['dhcp_start', 'dhcp_stop']);
        });
    }
};
