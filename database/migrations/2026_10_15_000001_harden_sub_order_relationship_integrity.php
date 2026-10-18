<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1.1 — Relationship integrity hardening for sub-orders.
 *
 * Phase 1 added `order_items.sub_order_id` and `order_status_histories.sub_order_id`
 * as plain indexed columns with NO foreign key. That left two integrity holes:
 *
 *   1. a row could point at a `sub_orders.id` that does not exist (dangling link);
 *   2. a row could point at a sub-order that belongs to a DIFFERENT parent order
 *      (cross-parent link) — e.g. order #7's item claiming sub-order of order #9.
 *
 * A single-column FK `sub_order_id → sub_orders.id` only closes hole (1).
 * Closing hole (2) requires a COMPOSITE FK that ties both columns together:
 *
 *      FOREIGN KEY (sub_order_id, order_id)
 *          REFERENCES sub_orders (id, order_id)
 *          ON DELETE CASCADE
 *
 * Because MySQL/SQLite both use MATCH SIMPLE semantics for composite FKs, a row
 * with `sub_order_id = NULL` satisfies the constraint without being checked —
 * so every legacy (pre-Phase-1) row stays valid with `sub_order_id = NULL`.
 *
 * DELETION POLICY — ON DELETE CASCADE, deliberately:
 *   - the FK is child→parent, so deleting a sub-order can NEVER delete the parent
 *     order; it can only affect rows that reference that sub-order;
 *   - deleting a sub-order removes exactly ITS items/history rows (rows that are
 *     meaningless once the sub-order is gone) — legacy rows (NULL) are untouched;
 *   - deleting a parent order already cascades to its items via the existing
 *     `order_items.order_id → orders` FK; CASCADE keeps both paths consistent
 *     instead of dead-locking the parent delete (which RESTRICT would do);
 *   - `ON DELETE SET NULL` is IMPOSSIBLE here: it would null BOTH child columns,
 *     but `order_items.order_id` / `order_status_histories.order_id` are NOT NULL.
 *
 * ENGINE HANDLING:
 *   - MySQL/MariaDB: plain `ALTER TABLE ... ADD CONSTRAINT` (cheap, in place).
 *   - SQLite: cannot add an FK to an existing table, so the table is REBUILT
 *     (create new → copy rows → drop old → rename → recreate indexes) with
 *     foreign keys temporarily disabled. Migrations are not wrapped in a
 *     transaction (Grammar::$transactions = false), so the PRAGMA takes effect.
 *
 * The migration is idempotent: each step is guarded by an existence check, and it
 * is a no-op when Phase 1 has not been applied yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sub_orders')) {
            return; // Phase 1 not applied — nothing to harden.
        }

        $this->remediateInvalidLinks();

        // The composite FK needs a matching UNIQUE key on the parent columns
        // (required by both MySQL and SQLite).
        $this->ensureSubOrdersParentKey();

        $this->addCompositeForeignKey(
            'order_items',
            fn () => $this->rebuildOrderItemsOnSqlite()
        );

        $this->addCompositeForeignKey(
            'order_status_histories',
            fn () => $this->rebuildHistoriesOnSqlite()
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('sub_orders')) {
            return;
        }

        $this->dropCompositeForeignKey('order_items', fn () => $this->rebuildOrderItemsOnSqlite(false));
        $this->dropCompositeForeignKey('order_status_histories', fn () => $this->rebuildHistoriesOnSqlite(false));

        $this->dropIndexIfExists('sub_orders', 'sub_orders_id_order_id_unique');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Preflight remediation — never deletes rows
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Any row whose `sub_order_id` does not resolve to a sub-order of the SAME
     * parent order is downgraded to a legacy row (`sub_order_id = NULL`).
     * The row itself, its `order_id` and all money columns are preserved.
     */
    private function remediateInvalidLinks(): void
    {
        foreach (['order_items', 'order_status_histories'] as $table) {
            if (! Schema::hasColumn($table, 'sub_order_id')) {
                continue;
            }

            $dangling = DB::table($table)
                ->whereNotNull('sub_order_id')
                ->whereNotExists(function ($q) use ($table) {
                    $q->select(DB::raw(1))
                        ->from('sub_orders')
                        ->whereColumn('sub_orders.id', $table.'.sub_order_id');
                })
                ->count();

            $crossParent = DB::table($table)
                ->whereNotNull('sub_order_id')
                ->whereExists(function ($q) use ($table) {
                    $q->select(DB::raw(1))
                        ->from('sub_orders')
                        ->whereColumn('sub_orders.id', $table.'.sub_order_id');
                })
                ->whereNotExists(function ($q) use ($table) {
                    $q->select(DB::raw(1))
                        ->from('sub_orders')
                        ->whereColumn('sub_orders.id', $table.'.sub_order_id')
                        ->whereColumn('sub_orders.order_id', $table.'.order_id');
                })
                ->count();

            if ($dangling === 0 && $crossParent === 0) {
                continue;
            }

            DB::table($table)
                ->whereNotNull('sub_order_id')
                ->whereNotExists(function ($q) use ($table) {
                    $q->select(DB::raw(1))
                        ->from('sub_orders')
                        ->whereColumn('sub_orders.id', $table.'.sub_order_id')
                        ->whereColumn('sub_orders.order_id', $table.'.order_id');
                })
                ->update(['sub_order_id' => null]);

            fwrite(STDERR, sprintf(
                "[Phase 1.1] %s: downgraded %d dangling + %d cross-parent link(s) to NULL.\n",
                $table,
                $dangling,
                $crossParent
            ));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Parent key — UNIQUE (id, order_id) on sub_orders
    // ─────────────────────────────────────────────────────────────────────────

    private function ensureSubOrdersParentKey(): void
    {
        if ($this->indexExists('sub_orders', 'sub_orders_id_order_id_unique')) {
            return;
        }

        Schema::table('sub_orders', function (Blueprint $table) {
            // Redundant on `id` alone, but REQUIRED as the referenced key of the
            // composite FK (MySQL + SQLite both need a unique index on exactly
            // the referenced column list).
            $table->unique(['id', 'order_id'], 'sub_orders_id_order_id_unique');
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Child composite FK — driver-aware
    // ─────────────────────────────────────────────────────────────────────────

    private function addCompositeForeignKey(string $table, callable $sqliteRebuild): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'sub_order_id')) {
            return;
        }

        if ($this->compositeFkExists($table)) {
            return; // already hardened
        }

        if ($this->driver() === 'sqlite') {
            $sqliteRebuild();

            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (sub_order_id, order_id) '
            .'REFERENCES sub_orders (id, order_id) ON DELETE CASCADE',
            $this->wrap($table),
            $this->wrap($this->constraintNameFor($table))
        ));
    }

    private function dropCompositeForeignKey(string $table, callable $sqliteRebuild): void
    {
        if (! Schema::hasTable($table) || ! $this->compositeFkExists($table)) {
            return;
        }

        if ($this->driver() === 'sqlite') {
            $sqliteRebuild();

            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE %s DROP FOREIGN KEY %s',
            $this->wrap($table),
            $this->wrap($this->constraintNameFor($table))
        ));
    }

    private function constraintNameFor(string $table): string
    {
        return $table.'_sub_order_id_order_id_foreign';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. SQLite table rebuilds
    // ─────────────────────────────────────────────────────────────────────────

    private function rebuildOrderItemsOnSqlite(bool $withCompositeFk = true): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('order_items_new');

        Schema::create('order_items_new', function (Blueprint $table) use ($withCompositeFk) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('sub_order_id')->nullable();
            $table->unsignedBigInteger('pharmacy_id');
            $table->unsignedBigInteger('pharmacy_medicine_id');
            $table->unsignedBigInteger('moh_medicine_id')->nullable();
            $table->string('medicine_trade_name');
            $table->text('medicine_description')->nullable();
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total_price', 10, 2);
            $table->string('availability_status')->default('available');
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('pharmacy_id')->references('id')->on('pharmacies')->cascadeOnDelete();
            $table->foreign('pharmacy_medicine_id')->references('id')->on('pharmacy_medicines')->cascadeOnDelete();

            if ($withCompositeFk) {
                $table->foreign(['sub_order_id', 'order_id'])
                    ->references(['id', 'order_id'])
                    ->on('sub_orders')
                    ->cascadeOnDelete();
            }
        });

        DB::statement(
            'INSERT INTO order_items_new '
            .'(id, order_id, sub_order_id, pharmacy_id, pharmacy_medicine_id, moh_medicine_id, '
            .'medicine_trade_name, medicine_description, quantity, unit_price, total_price, '
            .'availability_status, created_at, updated_at) '
            .'SELECT id, order_id, sub_order_id, pharmacy_id, pharmacy_medicine_id, moh_medicine_id, '
            .'medicine_trade_name, medicine_description, quantity, unit_price, total_price, '
            .'availability_status, created_at, updated_at FROM order_items'
        );

        Schema::drop('order_items');
        Schema::rename('order_items_new', 'order_items');

        // Indexes are created AFTER the rename: SQLite index names are global,
        // so they must not collide with the old table's still-present indexes.
        Schema::table('order_items', function (Blueprint $table) {
            $table->index(['order_id', 'pharmacy_id'], 'order_items_order_id_pharmacy_id_index');
            $table->index('moh_medicine_id', 'order_items_moh_medicine_id_index');
            $table->index('sub_order_id', 'order_items_sub_order_id_index');
        });

        Schema::enableForeignKeyConstraints();
    }

    private function rebuildHistoriesOnSqlite(bool $withCompositeFk = true): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('order_status_histories_new');

        Schema::create('order_status_histories_new', function (Blueprint $table) use ($withCompositeFk) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('sub_order_id')->nullable();
            $table->string('status');
            $table->string('note')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();

            if ($withCompositeFk) {
                $table->foreign(['sub_order_id', 'order_id'])
                    ->references(['id', 'order_id'])
                    ->on('sub_orders')
                    ->cascadeOnDelete();
            }
        });

        DB::statement(
            'INSERT INTO order_status_histories_new '
            .'(id, order_id, sub_order_id, status, note, status_changed_at, created_at, updated_at) '
            .'SELECT id, order_id, sub_order_id, status, note, status_changed_at, created_at, updated_at '
            .'FROM order_status_histories'
        );

        Schema::drop('order_status_histories');
        Schema::rename('order_status_histories_new', 'order_status_histories');

        Schema::table('order_status_histories', function (Blueprint $table) {
            $table->index(['order_id', 'created_at'], 'order_status_histories_order_id_created_at_index');
            $table->index('sub_order_id', 'order_status_histories_sub_order_id_index');
        });

        Schema::enableForeignKeyConstraints();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    /**
     * Driver-aware identifier quoting: backticks on MySQL/MariaDB, double quotes
     * on SQLite. Delegated to the connection's query grammar so we never have to
     * hard-code the dialect (ANSI double quotes are NOT valid identifiers in
     * MySQL unless ANSI_QUOTES is enabled — a real failure we hit).
     */
    private function wrap(string $identifier): string
    {
        return DB::connection()->getQueryGrammar()->wrap($identifier);
    }

    private function indexExists(string $table, string $index): bool
    {
        if ($this->driver() === 'sqlite') {
            return DB::table('sqlite_master')
                ->where('type', 'index')
                ->where('name', $index)
                ->exists();
        }

        return DB::selectOne(
            'select count(*) c from information_schema.STATISTICS '
            .'where TABLE_SCHEMA = database() and TABLE_NAME = ? and INDEX_NAME = ?',
            [$table, $index]
        )->c > 0;
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (! $this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($index) {
            $blueprint->dropUnique($index);
        });
    }

    /**
     * True when the composite (sub_order_id, order_id) → sub_orders FK is present.
     */
    private function compositeFkExists(string $table): bool
    {
        if ($this->driver() === 'sqlite') {
            $rows = DB::select('PRAGMA foreign_key_list("'.str_replace('"', '""', $table).'")');

            foreach ($rows as $row) {
                if ($row->table === 'sub_orders' && $row->from === 'sub_order_id') {
                    return true;
                }
            }

            return false;
        }

        $row = DB::selectOne(
            'select count(*) c from information_schema.KEY_COLUMN_USAGE '
            .'where TABLE_SCHEMA = database() and TABLE_NAME = ? '
            .'and REFERENCED_TABLE_NAME = ? and COLUMN_NAME = ?',
            [$table, 'sub_orders', 'sub_order_id']
        );

        return $row->c > 0;
    }
};
