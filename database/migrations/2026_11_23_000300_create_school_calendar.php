<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The school calendar, typed in by IT: terms, holidays and exam periods
 * (dates), and the bell times of each period (the same every school day).
 * Recurring schedules can follow it ("Term 2, Mondays, periods 3–4"), and
 * each network type can switch to exam mode during exam periods.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('kind', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();
            $table->index(['kind', 'start_date']);
        });
        Schema::create('bell_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 60);
            $table->string('starts_at', 5);
            $table->string('ends_at', 5);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::table('network_types', function (Blueprint $table): void {
            $table->string('exam_mode', 10)->default('off');
        });
        Schema::table('policy_lists', function (Blueprint $table): void {
            $table->string('purpose', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('policy_lists', fn (Blueprint $table) => $table->dropColumn('purpose'));
        Schema::table('network_types', fn (Blueprint $table) => $table->dropColumn('exam_mode'));
        Schema::dropIfExists('bell_periods');
        Schema::dropIfExists('calendar_periods');
    }
};
