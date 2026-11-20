<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - website_requests.access_from: the approved start of access, which IT
 *   can change from the requested start (expires_at is the approved end).
 * - website_requests.expiry_reminded_at: the requester was told access ends soon.
 * - users.password_login_enabled: per-account switch for password sign-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_requests', function (Blueprint $table): void {
            $table->timestamp('access_from')->nullable()->after('approved_at');
            $table->timestamp('expiry_reminded_at')->nullable()->after('expires_at');
        });

        DB::table('website_requests')->whereNotNull('approved_at')->update(['access_from' => DB::raw('requested_from')]);

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('password_login_enabled')->default(true)->after('auth_source');
        });
    }

    public function down(): void
    {
        Schema::table('website_requests', function (Blueprint $table): void {
            $table->dropColumn(['access_from', 'expiry_reminded_at']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('password_login_enabled');
        });
    }
};
