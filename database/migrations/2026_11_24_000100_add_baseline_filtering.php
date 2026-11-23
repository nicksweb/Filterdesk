<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baseline filtering: a network with no content filter gets the Students
 * filter unless an admin has allowed it to stay unfiltered, with a reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_assignments', function (Blueprint $table): void {
            $table->string('unfiltered_reason', 255)->nullable();
            $table->timestamp('unfiltered_reviewed_at')->nullable();
            $table->foreignId('unfiltered_reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('network_assignments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('unfiltered_reviewed_by_id');
            $table->dropColumn(['unfiltered_reason', 'unfiltered_reviewed_at']);
        });
    }
};
