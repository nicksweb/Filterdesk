<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Keep each network's gateway/prefix so probe containers can be planned. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_assignments', function (Blueprint $table): void {
            $table->string('subnet', 50)->nullable()->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('network_assignments', function (Blueprint $table): void {
            $table->dropColumn('subnet');
        });
    }
};
