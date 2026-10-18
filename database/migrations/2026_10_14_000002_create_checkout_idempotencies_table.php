<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 — Idempotency for the checkout endpoint.
 *
 * Stores one row per (user_id, idempotency_key). A replay of the same key by
 * the same user returns the original order_id instead of creating a duplicate.
 *
 * The unique composite key guarantees correctness under concurrency — this is
 * NOT a cache, it is the durable record of intent. The row is written inside
 * the SAME transaction that creates the order, so a rolled-back order leaves
 * no orphan idempotency row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('checkout_idempotencies')) {
            Schema::create('checkout_idempotencies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('key_hash')->index(); // sha1 of the idempotency key
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['user_id', 'key_hash'], 'checkout_idempotencies_user_key_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_idempotencies');
    }
};
