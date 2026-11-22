<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A layer is a second controller filter on the same networks as its parent
 * policy. Controllers apply every filter on a network, so the layer only adds
 * blocks: it carries the parent's blocklist feeds and keeps the parent's own
 * filter short enough to manage in the controller's web interface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('filter_policies', function (Blueprint $table): void {
            $table->foreignId('layer_of_id')->nullable()->unique()->after('audience')->constrained('filter_policies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('filter_policies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('layer_of_id');
        });
    }
};
