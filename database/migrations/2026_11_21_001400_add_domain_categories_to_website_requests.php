<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Website categories looked up when a request is submitted, as a guide for IT. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_requests', function (Blueprint $table): void {
            $table->json('domain_categories')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('website_requests', function (Blueprint $table): void {
            $table->dropColumn('domain_categories');
        });
    }
};
