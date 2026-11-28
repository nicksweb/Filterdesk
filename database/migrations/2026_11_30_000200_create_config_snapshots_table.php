<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versions of what was in force: each content filter's allow and block
 * lists (as read from UniFi or pushed) and each firewall allow list's
 * entries. A row is added only when something changed, so "how it was at
 * 9 am on Tuesday" is the last row for each one at or before that time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('config_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 30);
            $table->string('reference', 120);
            $table->string('name');
            $table->json('data');
            $table->string('hash', 64);
            $table->string('source', 30);
            $table->timestamp('taken_at')->index();
            $table->index(['kind', 'reference', 'taken_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_snapshots');
    }
};
