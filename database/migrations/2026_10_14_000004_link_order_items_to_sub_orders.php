<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — link items and status history to their sub-order.
 *
 * `order_items.sub_order_id`  → which pharmacy sub-order this line belongs to.
 * `order_status_histories.sub_order_id` → per-pharmacy status timeline.
 *
 * Both columns are NULLABLE on purpose:
 *  - legacy rows (created before Phase 1) keep NULL and stay readable;
 *  - the parent order's own history rows keep NULL.
 *
 * NOTE ON THE MISSING FOREIGN KEY: like `checkout_idempotencies.order_id`
 * (Phase 0), we add a plain indexed BIGINT instead of a real FK constraint.
 * SQLite (used by the whole test suite) cannot ALTER-add a foreign key to an
 * existing table, and the value of the constraint here is low — the parent
 * rows are already guarded by `sub_orders` cascade on delete. This keeps the
 * migration identical on SQLite and MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_items') && ! Schema::hasColumn('order_items', 'sub_order_id')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->unsignedBigInteger('sub_order_id')->nullable()->after('order_id');
                $table->index('sub_order_id', 'order_items_sub_order_id_index');
            });
        }

        if (Schema::hasTable('order_status_histories') && ! Schema::hasColumn('order_status_histories', 'sub_order_id')) {
            Schema::table('order_status_histories', function (Blueprint $table) {
                $table->unsignedBigInteger('sub_order_id')->nullable()->after('order_id');
                $table->index('sub_order_id', 'order_status_histories_sub_order_id_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('order_items') && Schema::hasColumn('order_items', 'sub_order_id')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropIndex('order_items_sub_order_id_index');
                $table->dropColumn('sub_order_id');
            });
        }

        if (Schema::hasTable('order_status_histories') && Schema::hasColumn('order_status_histories', 'sub_order_id')) {
            Schema::table('order_status_histories', function (Blueprint $table) {
                $table->dropIndex('order_status_histories_sub_order_id_index');
                $table->dropColumn('sub_order_id');
            });
        }
    }
};
