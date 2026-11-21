<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A firewall IP list can span several controller IP groups (UniFi accepts
 * about 20,000 addresses per group), each with blocking rules the app
 * created. pushed_hash lets scheduled refreshes skip unchanged lists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firewall_lists', function (Blueprint $table): void {
            $table->json('groups')->nullable();
            $table->json('rules')->nullable();
            $table->json('rule_zones')->nullable();
            $table->boolean('block_incoming')->default(false);
            $table->string('pushed_hash', 64)->nullable();
        });

        foreach (DB::table('firewall_lists')->whereNotNull('provider_reference')->get() as $list) {
            DB::table('firewall_lists')->where('id', $list->id)->update([
                'groups' => json_encode([['id' => $list->provider_reference, 'name' => $list->provider_name ?: $list->name, 'count' => (int) $list->live_count]]),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('firewall_lists', function (Blueprint $table): void {
            $table->dropColumn(['groups', 'rules', 'rule_zones', 'block_incoming', 'pushed_hash']);
        });
    }
};
