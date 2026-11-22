<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maps controller networks (VLANs) to the filter policy that should govern
 * them, and records the name of the controller content filter a policy is
 * linked to so the mapping can be shown without a live controller call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_assignments', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_network_id', 190)->unique();
            $table->string('name');
            $table->unsignedInteger('vlan')->nullable();
            $table->string('purpose', 60)->nullable();
            $table->foreignId('filter_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('filter_policies', function (Blueprint $table): void {
            $table->string('provider_name')->nullable()->after('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::table('filter_policies', function (Blueprint $table): void {
            $table->dropColumn('provider_name');
        });
        Schema::dropIfExists('network_assignments');
    }
};
