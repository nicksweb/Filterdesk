<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every write to a controller content filter, with the lists before and
 * after, so a push can be audited and rolled back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('controller_pushes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('filter_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_reference', 190);
            $table->string('provider_name')->nullable();
            $table->string('trigger', 40);
            $table->string('status', 20);
            $table->json('before_allow');
            $table->json('before_block');
            $table->json('after_allow');
            $table->json('after_block');
            $table->json('changes');
            $table->text('message')->nullable();
            $table->foreignId('rollback_of_id')->nullable()->constrained('controller_pushes')->nullOnDelete();
            $table->timestamps();
            $table->index(['filter_policy_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('controller_pushes');
    }
};
