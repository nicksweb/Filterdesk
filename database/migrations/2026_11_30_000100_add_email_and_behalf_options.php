<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fewer emails: each person chooses all request updates or only the ones
 * that matter, IT alerts can wait for one summary, admins see what's new
 * once per release, and IT can submit a request for someone else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email_updates', 20)->default('all');
            $table->string('whats_new_seen', 20)->nullable();
        });
        Schema::table('website_requests', function (Blueprint $table): void {
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('pending_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 40);
            $table->text('line');
            $table->string('url', 500)->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_alerts');
        Schema::table('website_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('submitted_by_id');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['email_updates', 'whats_new_seen']);
        });
    }
};
