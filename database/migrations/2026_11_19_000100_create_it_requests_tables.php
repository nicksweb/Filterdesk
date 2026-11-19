<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('oidc_subject')->nullable()->unique();
            $table->string('role')->default('teacher')->index();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_login_at')->nullable();
        });

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('integration_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->index();
            $table->string('name');
            $table->string('mode')->default('read_only');
            $table->json('configuration')->nullable();
            $table->text('credentials')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status')->nullable();
            $table->text('last_test_message')->nullable();
            $table->timestamps();
        });

        Schema::create('network_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('probe_url');
            $table->string('unifi_network_id')->nullable();
            $table->string('unifi_policy_id')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('website_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('requester_id')->constrained('users');
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('domains');
            $table->string('access_scope');
            $table->string('category');
            $table->text('use_case');
            $table->boolean('standards_confirmed')->default(false);
            $table->string('status')->default('pending')->index();
            $table->timestamp('requested_from')->nullable();
            $table->timestamp('requested_until')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->json('provider_state')->nullable();
            $table->timestamps();
        });

        Schema::create('request_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('website_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('url');
            $table->string('hostname')->index();
            $table->json('results');
            $table->unsignedInteger('duration_ms');
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->index();
            $table->nullableMorphs('subject');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('website_checks');
        Schema::dropIfExists('request_events');
        Schema::dropIfExists('website_requests');
        Schema::dropIfExists('network_profiles');
        Schema::dropIfExists('integration_configs');
        Schema::dropIfExists('settings');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['oidc_subject', 'role', 'enabled', 'last_login_at']);
        });
    }
};
