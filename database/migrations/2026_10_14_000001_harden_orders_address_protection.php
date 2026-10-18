<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 — Safe hardening: protect historical orders when a user deletes an address.
 *
 * 1. orders.address_id FK: ON DELETE CASCADE -> ON DELETE SET NULL,
 *    so deleting an address does NOT cascade-delete historical orders.
 * 2. Add orders.address_snapshot (JSON): frozen copy of the delivery address
 *    captured at checkout time (recipient_name, phone, address, city, lat/lng).
 *    The order becomes immutable w.r.t. later edits/deletes of the source address.
 *
 * Guarded with hasColumn so it is re-runnable on a fresh test DB without errors.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'address_snapshot')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->json('address_snapshot')->nullable()->after('address_id');
            });
        }

        $this->switchFkToDeleteNull();
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'address_snapshot')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('address_snapshot');
            });
        }

        $this->switchFkToCascade();
    }

    private function switchFkToDeleteNull(): void
    {
        // SQLite (test) doesn't support dropping FKs inline across all versions;
        // guard with try/catch so a re-run on an already-migrated DB is a no-op.
        try {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropForeign(['address_id']);
            });
        } catch (\Throwable $e) {
            // FK may already be absent (e.g. fresh sqlite) — safe to continue.
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('address_id')
                ->references('id')
                ->on('addresses')
                ->nullOnDelete();
        });
    }

    private function switchFkToCascade(): void
    {
        try {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropForeign(['address_id']);
            });
        } catch (\Throwable $e) {
            // ignore
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('address_id')
                ->references('id')
                ->on('addresses')
                ->cascadeOnDelete();
        });
    }
};
