<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integrations: API tokens (stored as SHA-256 hashes; each acts as the IT
 * officer who created it) and outgoing webhooks for request and policy
 * events (JSON signed with HMAC-SHA256, or a Microsoft Teams card).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('token_hash', 64)->unique();
            $table->json('abilities');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('url', 1000);
            $table->string('format', 10)->default('json');
            $table->text('secret')->nullable();
            $table->json('events');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_delivered_at')->nullable();
            $table->string('last_status', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('api_tokens');
    }
};
