<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Third-party blocklist feeds (domain lists such as hagezi, and IP lists)
 * and firewall IP lists kept in step with a controller IP group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firewall_lists', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('family', 8)->default('ipv4');
            $table->string('provider_reference', 190)->nullable();
            $table->string('provider_name')->nullable();
            $table->json('manual_entries')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('max_entries')->default(4000);
            $table->timestamp('last_pushed_at')->nullable();
            $table->string('last_push_status', 20)->nullable();
            $table->text('last_push_message')->nullable();
            $table->unsignedInteger('live_count')->nullable();
            $table->json('referenced_by')->nullable();
            $table->timestamps();
        });

        Schema::create('list_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('preset', 80)->nullable();
            $table->string('url', 2048);
            $table->string('type', 10);
            $table->foreignId('policy_list_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('firewall_list_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('refresh_hours')->default(24);
            $table->unsignedInteger('max_entries')->default(20000);
            $table->json('ip_entries')->nullable();
            $table->timestamp('last_fetched_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->text('last_message')->nullable();
            $table->unsignedInteger('entry_count')->default(0);
            $table->string('etag')->nullable();
            $table->string('last_modified')->nullable();
            $table->timestamps();
        });

        Schema::table('policy_entries', function (Blueprint $table): void {
            $table->foreignId('list_source_id')->nullable()->after('website_request_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('policy_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('list_source_id');
        });
        Schema::dropIfExists('list_sources');
        Schema::dropIfExists('firewall_lists');
    }
};
