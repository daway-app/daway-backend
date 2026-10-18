<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — Multi-pharmacy orders.
 *
 * A single patient checkout can now span MULTIPLE pharmacies. The `orders`
 * row becomes the parent "header" (patient, address snapshot, grand totals),
 * and each pharmacy in the cart gets its own `sub_orders` row with an
 * INDEPENDENT lifecycle (status / payment_status / totals).
 *
 * Backward compatibility: legacy orders created before this migration keep
 * working because `order_items.sub_order_id` is nullable (added in the next
 * migration) and `orders.pharmacy_id` is still populated for single-pharmacy
 * orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sub_orders')) {
            return;
        }

        Schema::create('sub_orders', function (Blueprint $table) {
            $table->id();

            // Parent order. Deleting the parent removes its sub-orders.
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            // Owning pharmacy. Kept nullable so a deleted pharmacy does not
            // destroy financial history (same policy as orders.pharmacy_id).
            $table->foreignId('pharmacy_id')->nullable()->constrained('pharmacies')->nullOnDelete();

            // Money. `shipping_fee` / `commission` are reserved for Phase 3
            // (settlement) and Phase 4 (unified delivery) — they exist now so
            // those phases are pure data writes, not schema churn.
            $table->decimal('subtotal', 10, 2)->default(0.00);
            $table->decimal('discount', 10, 2)->default(0.00);
            $table->decimal('shipping_fee', 10, 2)->default(0.00);
            $table->decimal('commission', 10, 2)->default(0.00);
            $table->decimal('total', 10, 2)->default(0.00);

            // Independent lifecycle per pharmacy.
            $table->string('status')->default('pending');
            $table->string('payment_status')->default('pending');

            $table->text('notes')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            // One sub-order per (parent order, pharmacy) — the core invariant
            // that makes the checkout grouping idempotent and safe.
            $table->unique(['order_id', 'pharmacy_id'], 'sub_orders_order_pharmacy_unique');

            $table->index(['pharmacy_id', 'status'], 'sub_orders_pharmacy_status_index');
            $table->index(['status', 'created_at'], 'sub_orders_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_orders');
    }
};
