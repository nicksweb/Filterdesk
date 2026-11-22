<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Network types replace the fixed Staff / Student / Guest / Other audiences.
 * A type is a named set of shared allow and block lists ("Staff",
 * "Students", "CCTV", "Exam rooms"); policies, lists and probes refer to it
 * by key. Existing audience values become types, and requests' fixed scopes
 * become a comma-separated list of type keys (or "site").
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'staff' => ['Staff', 'Staff devices and networks.', true, true, 1],
        'student' => ['Students', 'Student devices and networks.', true, false, 2],
        'guest' => ['Guests', 'Visitor and guest networks.', false, false, 3],
        'other' => ['Other networks', 'Networks without a type of their own.', false, false, 9],
    ];

    private const LEGACY_SCOPES = ['staff' => 'staff', 'staff_students' => 'staff,student', 'students' => 'student', 'site' => 'site'];

    public function up(): void
    {
        Schema::create('network_types', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->boolean('requestable')->default(true);
            $table->boolean('auto_approve')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $used = collect(array_keys(self::DEFAULTS))
            ->merge(DB::table('filter_policies')->pluck('audience'))
            ->merge(DB::table('policy_lists')->whereNotNull('audience')->pluck('audience'))
            ->merge(DB::table('network_profiles')->whereNotNull('audience')->pluck('audience'))
            ->filter()->unique()->values();
        $now = now();
        foreach ($used as $index => $key) {
            [$name, $description, $requestable, $autoApprove, $order] = self::DEFAULTS[$key] ?? [ucfirst(str_replace(['-', '_'], ' ', $key)), null, false, false, 10 + $index];
            DB::table('network_types')->insert([
                'key' => $key, 'name' => $name, 'description' => $description, 'requestable' => $requestable,
                'auto_approve' => $autoApprove, 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (self::LEGACY_SCOPES as $old => $new) {
            DB::table('website_requests')->where('access_scope', $old)->update(['access_scope' => $new]);
            DB::table('website_requests')->where('applied_scope', $old)->update(['applied_scope' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::LEGACY_SCOPES as $old => $new) {
            DB::table('website_requests')->where('access_scope', $new)->update(['access_scope' => $old]);
            DB::table('website_requests')->where('applied_scope', $new)->update(['applied_scope' => $old]);
        }
        Schema::dropIfExists('network_types');
    }
};
