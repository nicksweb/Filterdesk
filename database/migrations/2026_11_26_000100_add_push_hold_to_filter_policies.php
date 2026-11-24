<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A push hold: after a policy's filter link changes, nothing is pushed to
 * that filter automatically until a person pushes the policy from its page,
 * where the plan is shown first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('filter_policies', function (Blueprint $table): void {
            $table->string('push_hold', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('filter_policies', function (Blueprint $table): void {
            $table->dropColumn('push_hold');
        });
    }
};
