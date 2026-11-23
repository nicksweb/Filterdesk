<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Policy versions: every publish (manual, automatic or scheduled) groups
 * its controller writes into one numbered release that can be reviewed and
 * rolled back as a whole. Releases that changed nothing are not kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_releases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('number')->nullable()->unique();
            $table->string('trigger', 40);
            $table->string('status', 20)->default('running');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('summary')->nullable();
            $table->json('firewall')->nullable();
            $table->foreignId('rollback_of_id')->nullable()->constrained('policy_releases')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::table('controller_pushes', function (Blueprint $table): void {
            $table->foreignId('policy_release_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('controller_pushes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('policy_release_id');
        });
        Schema::dropIfExists('policy_releases');
    }
};
