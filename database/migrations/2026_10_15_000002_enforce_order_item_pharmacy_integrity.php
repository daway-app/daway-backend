<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1.2 — Enforce order-item pharmacy integrity.
 *
 * Phase 1.1 guaranteed that an order item can only point at a sub-order of the
 * SAME parent order, via:
 *
 *      FOREIGN KEY (sub_order_id, order_id) → sub_orders (id, order_id)
 *
 * That still left one hole: `order_items.pharmacy_id` (a denormalised copy of
 * the selling pharmacy, kept for historical display) could disagree with the
 * pharmacy of the sub-order it belongs to. Nothing at the database level
 * prevented order #7's item claiming `pharmacy_id = 3` while its sub-order
 * belongs to pharmacy #4.
 *
 * This migration closes that hole by WIDENING the composite key to include the
 * pharmacy:
 *
 *      FOREIGN KEY (sub_order_id, order_id, pharmacy_id)
 *          REFERENCES sub_orders (id, order_id, pharmacy_id)
 *          ON DELETE CASCADE
 *
 * DESIGN NOTE — the three-column key REPLACES the two-column one instead of
 * being added next to it. The three-column FK strictly SUBSUMES the Phase 1.1
 * guarantee (a matching (id, order_id, pharmacy_id) triple implies a matching
 * (id, order_id) pair), so keeping both would only duplicate an index and a
 * write-time check. `down()` restores the two-column FK exactly.
 *
 * `order_status_histories` is NOT touched: it has no `pharmacy_id` column, so
 * its Phase 1.1 two-column FK remains exactly as it was.
 *
 * WHY THE PHARMACY COLUMN CAN BE NULL ON THE PARENT — and why that is safe:
 * `sub_orders.pharmacy_id` is `nullOnDelete` (Phase 1 policy: keep financial
 * history when a pharmacy is deleted). So deleting a pharmacy sets it to NULL.
 * That is a change to the FK's parent key, and the default ON UPDATE action
 * would reject it — EXCEPT that the same pharmacy deletion also CASCADE-deletes
 * `order_items` (via `order_items.pharmacy_id → pharmacies ON DELETE CASCADE`),
 * so no child row survives to reference the vanished key. Verified empirically
 * on MariaDB: the admin "delete pharmacy" path (PharmacyController::destroy)
 * still succeeds; items are removed, the sub-order and the parent order survive
 * with `pharmacy_id = NULL`.
 *
 * DATA HANDLING (no silent mutation): any existing row whose link does not
 * resolve — dangling sub-order, different parent order, OR a pharmacy mismatch
 * — is inspected and reported loudly on STDERR, then downgraded to a LEGACY row
 * (`sub_order_id = NULL`). That rule preserves the historical meaning of the row
 * (its `order_id` and its recorded `pharmacy_id` both stay exactly as they were)
 * while removing the contradictory link. Rows are never deleted.
 *
 * Idempotent: every step is existence-guarded, and it is a no-op if Phase 1 has
 * not been applied.
 */
return new class extends Migration
{
    private const FK_NAME = 'order_items_sub_order_pharmacy_foreign';

    private const OLD_FK_NAME = 'order_items_sub_order_id_order_id_foreign';

    private const PARENT_KEY = 'sub_orders_id_order_id_pharmacy_unique';

    public function up(): void
    {
        if (! Schema::hasTable('sub_orders') || ! Schema::hasColumn('order_items', 'sub_order_id')) {
            return; // Phase 1 not applied — nothing to harden.
        }

        $this->remediateInvalidLinks();

        // Parent key required by the three-column FK (MySQL + SQLite both need a
        // unique index over exactly the referenced column list).
        if (! $this->indexExists('sub_orders', self::PARENT_KEY)) {
            Schema::table('sub_orders', function (Blueprint $table) {
                $table->unique(['id', 'order_id', 'pharmacy_id'], self::PARENT_KEY);
            });
        }

        if ($this->pharmacyFkExists()) {
            return; // already hardened
        }

        if ($this->driver() === 'sqlite') {
            $this->rebuildOrderItemsOnSqlite();

            return;
        }

        // Drop the narrower Phase 1.1 FK and its now-redundant index first.
        if ($this->fkExists('order_items', self::OLD_FK_NAME)) {
            DB::statement(sprintf(
                'ALTER TABLE %s DROP FOREIGN KEY %s',
                $this->wrap('order_items'),
                $this->wrap(self::OLD_FK_NAME)
            ));
        }

        if ($this->indexExists('order_items', self::OLD_FK_NAME)) {
            // MySQL keeps the FK's backing index after the FK is dropped.
            DB::statement(sprintf(
                'ALTER TABLE %s DROP INDEX %s',
                $this->wrap('order_items'),
                $this->wrap(self::OLD_FK_NAME)
            ));
        }

        DB::statement(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (sub_order_id, order_id, pharmacy_id) '
            .'REFERENCES sub_orders (id, order_id, pharmacy_id) ON DELETE CASCADE',
            $this->wrap('order_items'),
            $this->wrap(self::FK_NAME)
        ));
    }

    public function down(): void
    {
        if (! Schema::hasTable('sub_orders') || ! Schema::hasColumn('order_items', 'sub_order_id')) {
            return;
        }

        if ($this->driver() === 'sqlite') {
            $this->rebuildOrderItemsOnSqlite(withPharmacyFk: false);
        } else {
            if ($this->fkExists('order_items', self::FK_NAME)) {
                DB::statement(sprintf(
                    'ALTER TABLE %s DROP FOREIGN KEY %s',
                    $this->wrap('order_items'),
                    $this->wrap(self::FK_NAME)
                ));
            }

            if ($this->indexExists('order_items', self::FK_NAME)) {
                DB::statement(sprintf(
                    'ALTER TABLE %s DROP INDEX %s',
                    $this->wrap('order_items'),
                    $this->wrap(self::FK_NAME)
                ));
            }

            // Restore the Phase 1.1 two-column FK exactly as it was.
            DB::statement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (sub_order_id, order_id) '
                .'REFERENCES sub_orders (id, order_id) ON DELETE CASCADE',
                $this->wrap('order_items'),
                $this->wrap(self::OLD_FK_NAME)
            ));
        }

        if ($this->indexExists('sub_orders', self::PARENT_KEY)) {
            Schema::table('sub_orders', function (Blueprint $table) {
                $table->dropUnique(self::PARENT_KEY);
            });
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data inspection / remediation — never deletes rows, never silent
    // ─────────────────────────────────────────────────────────────────────────

    private function remediateInvalidLinks(): void
    {
        $base = fn () => DB::table('order_items')
            ->whereNotNull('sub_order_id')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('sub_orders')
                    ->whereColumn('sub_orders.id', 'order_items.sub_order_id');
            });

        $dangling = $base()->count();

        $linked = fn () => DB::table('order_items')
            ->whereNotNull('sub_order_id')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('sub_orders')
                    ->whereColumn('sub_orders.id', 'order_items.sub_order_id');
            });

        $crossParent = $linked()
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('sub_orders')
                    ->whereColumn('sub_orders.id', 'order_items.sub_order_id')
                    ->whereColumn('sub_orders.order_id', 'order_items.order_id');
            })
            ->count();

        $pharmacyMismatch = $linked()
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('sub_orders')
                    ->whereColumn('sub_orders.id', 'order_items.sub_order_id')
                    ->whereColumn('sub_orders.order_id', 'order_items.order_id');
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('sub_orders')
                    ->whereColumn('sub_orders.id', 'order_items.sub_order_id')
                    ->whereColumn('sub_orders.order_id', 'order_items.order_id')
                    ->whereColumn('sub_orders.pharmacy_id', 'order_items.pharmacy_id');
            })
            ->count();

        if ($dangling === 0 && $crossParent === 0 && $pharmacyMismatch === 0) {
            fwrite(STDERR, "[Phase 1.2] order_items: 0 invalid links — nothing to remediate.\n");

            return;
        }

        fwrite(STDERR, sprintf(
            "[Phase 1.2] order_items: %d dangling, %d cross-parent, %d pharmacy-mismatch link(s). "
            ."Downgrading these to legacy rows (sub_order_id = NULL); order_id and pharmacy_id are preserved.\n",
            $dangling,
            $crossParent,
            $pharmacyMismatch
        ));

        DB::table('order_items')
            ->whereNotNull('sub_order_id')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('sub_orders')
                    ->whereColumn('sub_orders.id', 'order_items.sub_order_id')
                    ->whereColumn('sub_orders.order_id', 'order_items.order_id')
                    ->whereColumn('sub_orders.pharmacy_id', 'order_items.pharmacy_id');
            })
            ->update(['sub_order_id' => null]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SQLite table rebuild
    // ─────────────────────────────────────────────────────────────────────────

    private function rebuildOrderItemsOnSqlite(bool $withPharmacyFk = true): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('order_items_new');

        Schema::create('order_items_new', function (Blueprint $table) use ($withPharmacyFk) {
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

            if ($withPharmacyFk) {
                $table->foreign(['sub_order_id', 'order_id', 'pharmacy_id'])
                    ->references(['id', 'order_id', 'pharmacy_id'])
                    ->on('sub_orders')
                    ->cascadeOnDelete();
            } else {
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

        // Indexes AFTER the rename: SQLite index names are global, so they must
        // not collide with the dropped table's still-registered index names.
        Schema::table('order_items', function (Blueprint $table) {
            $table->index(['order_id', 'pharmacy_id'], 'order_items_order_id_pharmacy_id_index');
            $table->index('moh_medicine_id', 'order_items_moh_medicine_id_index');
            $table->index('sub_order_id', 'order_items_sub_order_id_index');
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

    private function wrap(string $identifier): string
    {
        return DB::connection()->getQueryGrammar()->wrap($identifier);
    }

    private function indexExists(string $table, string $index): bool
    {
        if ($this->driver() === 'sqlite') {
            return DB::table('sqlite_master')->where('type', 'index')->where('name', $index)->exists();
        }

        return DB::selectOne(
            'select count(*) c from information_schema.STATISTICS '
            .'where TABLE_SCHEMA = database() and TABLE_NAME = ? and INDEX_NAME = ?',
            [$table, $index]
        )->c > 0;
    }

    private function fkExists(string $table, string $constraint): bool
    {
        if ($this->driver() === 'sqlite') {
            return false; // SQLite rebuilds wholesale; constraint names are not queryable.
        }

        return DB::selectOne(
            'select count(*) c from information_schema.TABLE_CONSTRAINTS '
            .'where CONSTRAINT_SCHEMA = database() and TABLE_NAME = ? and CONSTRAINT_NAME = ?',
            [$table, $constraint]
        )->c > 0;
    }

    /**
     * True when the three-column (sub_order_id, order_id, pharmacy_id) FK exists.
     */
    private function pharmacyFkExists(): bool
    {
        if ($this->driver() === 'sqlite') {
            $rows = DB::select('PRAGMA foreign_key_list("order_items")');

            $froms = collect($rows)
                ->filter(fn ($r) => $r->table === 'sub_orders')
                ->pluck('from')
                ->all();

            return in_array('sub_order_id', $froms, true)
                && in_array('order_id', $froms, true)
                && in_array('pharmacy_id', $froms, true);
        }

        return DB::selectOne(
            'select count(*) c from information_schema.KEY_COLUMN_USAGE '
            .'where TABLE_SCHEMA = database() and TABLE_NAME = ? '
            .'and REFERENCED_TABLE_NAME = ? and COLUMN_NAME = ?',
            ['order_items', 'sub_orders', 'pharmacy_id']
        )->c > 0;
    }
};
