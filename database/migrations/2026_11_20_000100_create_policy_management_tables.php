<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the single "master allow/block + one UniFi snapshot" model with
 * audience-aware filter policies and composable domain lists.
 *
 * - filter_policies: one per content-filter profile in the network provider
 *   (e.g. UniFi "Students", "Staff", "Guests"), each tagged with an audience.
 * - policy_lists:    named allow/block lists scoped site-wide, to an audience,
 *   or to one policy. A policy's desired state is the union of every list
 *   that applies to it.
 * - policy_entries:  individual domains, recording where each came from
 *   (manual, approved request, import) and an optional effective window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_profiles', function (Blueprint $table): void {
            $table->string('audience')->default('other')->after('name');
            $table->unsignedInteger('sort_order')->default(0);
        });

        DB::table('network_profiles')->where('key', 'student')->update(['audience' => 'student', 'sort_order' => 1]);
        DB::table('network_profiles')->where('key', 'staff')->update(['audience' => 'staff', 'sort_order' => 2]);

        Schema::create('filter_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('audience')->index();
            $table->text('description')->nullable();
            $table->foreignId('network_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_reference')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('current_allowlist')->nullable();
            $table->json('current_blocklist')->nullable();
            $table->timestamp('snapshot_at')->nullable();
            $table->foreignId('snapshot_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('policy_lists', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('kind')->index();
            $table->string('scope')->index();
            $table->string('audience')->nullable()->index();
            $table->foreignId('filter_policy_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('policy_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_list_id')->constrained()->cascadeOnDelete();
            $table->string('domain')->index();
            $table->string('source')->default('manual');
            $table->foreignId('website_request_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('added_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['policy_list_id', 'domain']);
        });

        Schema::table('website_requests', function (Blueprint $table): void {
            $table->json('approved_domains')->nullable();
            $table->json('related_domains')->nullable();
            $table->string('applied_scope')->nullable();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
        });

        Schema::table('request_events', function (Blueprint $table): void {
            $table->boolean('internal')->default(false);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('notify_new_requests')->default(true);
            $table->string('auth_source')->default('local');
        });

        DB::table('users')->whereNotNull('oidc_subject')->update(['auth_source' => 'oidc']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['notify_new_requests', 'auth_source']);
        });
        Schema::table('request_events', function (Blueprint $table): void {
            $table->dropColumn('internal');
        });
        Schema::table('website_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by_id');
            $table->dropColumn(['approved_domains', 'related_domains', 'applied_scope', 'assigned_at']);
        });
        Schema::dropIfExists('policy_entries');
        Schema::dropIfExists('policy_lists');
        Schema::dropIfExists('filter_policies');
        Schema::table('network_profiles', function (Blueprint $table): void {
            $table->dropColumn(['audience', 'sort_order']);
        });
    }
};
