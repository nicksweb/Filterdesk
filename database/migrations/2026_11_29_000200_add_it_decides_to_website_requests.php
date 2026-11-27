<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A request sent with the short form: the networks and the period were left for IT to decide. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_requests', function (Blueprint $table): void {
            $table->boolean('it_decides')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('website_requests', function (Blueprint $table): void {
            $table->dropColumn('it_decides');
        });
    }
};
