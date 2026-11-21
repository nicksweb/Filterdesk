<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The request reason can be optional for staff (Settings → Approval policy). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_requests', function (Blueprint $table): void {
            $table->text('use_case')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('website_requests', function (Blueprint $table): void {
            $table->text('use_case')->nullable(false)->default('')->change();
        });
    }
};
