<?php

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;

/**
 * MySQL 8.4-only migration recovery checks. This test deliberately has no
 * RefreshDatabase trait or application seeders because its cases perform DDL.
 * The database-name guard prevents migrate:fresh from running outside the
 * disposable GitHub Actions database configured for Phase F1.1.3.
 */
final class MySqlMigrationRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the isolated MySQL 8.4 Actions service.');
        }

        $database = DB::selectOne('SELECT DATABASE() AS name')->name ?? null;
        if ($database !== 'daway_f113_test') {
            throw new LogicException('Refusing destructive test setup outside daway_f113_test.');
        }

        $exitCode = Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
        if ($exitCode !== 0) {
            throw new RuntimeException('Test database migration failed: '.Artisan::output());
        }
    }

    public function test_clean_migrations_create_expected_mysql_columns_foreign_keys_and_indexes(): void
    {
        $this->assertMysqlColumn('medicine_barcodes', 'moh_medicine_id', 'bigint unsigned', 'YES');
        $this->assertForeignKey('medicine_barcodes', 'moh_medicine_id', 'moh_medicines', 'id', 'CASCADE');
        $this->assertMysqlColumn('purchase_items', 'medicine_name', 'varchar(200)', 'NO');
        $this->assertMysqlColumn('purchase_items', 'barcode', 'varchar(32)', 'YES');
        $this->assertMysqlColumn('notifications', 'dedupe_key', 'varchar(191)', 'YES');

        foreach ([
            'purchase_id' => ['purchases', 'CASCADE'],
            'pharmacy_medicine_id' => ['pharmacy_medicines', 'SET NULL'],
            'medicine_id' => ['medicines', 'SET NULL'],
            'moh_medicine_id' => ['moh_medicines', 'SET NULL'],
        ] as $column => [$target, $deleteRule]) {
            $this->assertForeignKey('purchase_items', $column, $target, 'id', $deleteRule);
            $this->assertTrue($this->hasLeadingIndex('purchase_items', $column), "Missing MySQL index for purchase_items.{$column}");
        }

        $this->assertForeignKey('notifications', 'order_id', 'orders', 'id', 'SET NULL');
        $this->assertForeignKey('notifications', 'sub_order_id', 'sub_orders', 'id', 'SET NULL');
        $this->assertIndex('notifications', 'notifications_dedupe_key_unique', ['dedupe_key'], true);
        $this->assertIndex('notifications', 'notifications_user_read_created_index', ['user_id', 'is_read', 'created_at'], false);

        $this->assertMysqlColumn('order_payment_submissions', 'order_grand_total', 'decimal(10,2)', 'YES');
        $this->assertMysqlColumn('order_payment_submissions', 'amount_status', 'varchar(255)', 'YES');
        $this->assertMysqlColumn('order_payment_submissions', 'overage_amount', 'decimal(10,2)', 'NO');
        $this->assertNull($this->mysqlColumn('order_payment_submissions', 'amount_status')->column_default);
    }

    public function test_rerunning_all_four_migrations_keeps_the_expected_schema(): void
    {
        $this->migration('2026_10_17_000001_create_purchase_items_table.php')->up();
        $this->migration('2026_10_22_000001_add_order_context_to_notifications_table.php')->up();
        $this->migration('2026_10_25_000001_add_amount_reconciliation_to_payment_submissions.php')->up();
        $this->migration('2026_10_27_000001_make_moh_medicine_id_nullable_on_medicine_barcodes.php')->up();

        $this->assertForeignKey('medicine_barcodes', 'moh_medicine_id', 'moh_medicines', 'id', 'CASCADE');
        $this->assertForeignKey('notifications', 'order_id', 'orders', 'id', 'SET NULL');
        $this->assertForeignKey('notifications', 'sub_order_id', 'sub_orders', 'id', 'SET NULL');
        $this->assertIndex('notifications', 'notifications_dedupe_key_unique', ['dedupe_key'], true);
        $this->assertMysqlColumn('order_payment_submissions', 'amount_status', 'varchar(255)', 'YES');
    }

    public function test_notification_column_without_fk_is_repaired_and_keeps_existing_row(): void
    {
        [$userId, $orderId] = $this->createUserAndOrder();
        $id = DB::table('notifications')->insertGetId([
            'user_id' => $userId,
            'type' => 'migration-test',
            'message' => 'preserve this notification',
            'order_id' => $orderId,
            'created_at' => now(),
        ]);

        $this->dropForeignKey('notifications', 'order_id');
        $this->migration('2026_10_22_000001_add_order_context_to_notifications_table.php')->up();

        $this->assertDatabaseHas('notifications', ['id' => $id, 'order_id' => $orderId, 'message' => 'preserve this notification']);
        $this->assertForeignKey('notifications', 'order_id', 'orders', 'id', 'SET NULL');
    }

    public function test_nullable_barcode_without_fk_is_repaired_and_preserves_links_and_value(): void
    {
        [$mohId, $localId, $barcodeId] = $this->createBarcode();
        $this->dropForeignKey('medicine_barcodes', 'moh_medicine_id');

        $this->migration('2026_10_27_000001_make_moh_medicine_id_nullable_on_medicine_barcodes.php')->up();

        $this->assertDatabaseHas('medicine_barcodes', [
            'id' => $barcodeId,
            'moh_medicine_id' => $mohId,
            'local_medicine_id' => $localId,
            'barcode' => 'F113-BARCODE-KEEP',
        ]);
        $this->assertForeignKey('medicine_barcodes', 'moh_medicine_id', 'moh_medicines', 'id', 'CASCADE');
    }

    public function test_wrong_barcode_fk_fails_diagnostically_without_changing_the_row(): void
    {
        [$mohId, $localId, $barcodeId] = $this->createBarcode();
        if (! DB::table('medicines')->where('id', $mohId)->exists()) {
            DB::table('medicines')->insert([
                'id' => $mohId,
                'trade_name' => 'Wrong FK target fixture',
                'active_ingredient' => 'fixture',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->dropForeignKey('medicine_barcodes', 'moh_medicine_id');
        DB::statement('ALTER TABLE `medicine_barcodes` ADD CONSTRAINT `f113_wrong_barcode_fk` FOREIGN KEY (`moh_medicine_id`) REFERENCES `medicines` (`id`) ON DELETE CASCADE');

        try {
            $this->migration('2026_10_27_000001_make_moh_medicine_id_nullable_on_medicine_barcodes.php')->up();
            $this->fail('Expected a diagnostic exception for an FK targeting medicines.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('FK غير متوقع', $exception->getMessage());
        }

        $this->assertDatabaseHas('medicine_barcodes', [
            'id' => $barcodeId,
            'moh_medicine_id' => $mohId,
            'local_medicine_id' => $localId,
            'barcode' => 'F113-BARCODE-KEEP',
        ]);
        $this->assertForeignKey('medicine_barcodes', 'moh_medicine_id', 'medicines', 'id', 'CASCADE');
    }

    public function test_duplicate_notification_keys_fail_without_deleting_either_row(): void
    {
        [$userId] = $this->createUserAndOrder();
        DB::statement('ALTER TABLE `notifications` DROP INDEX `notifications_dedupe_key_unique`');

        foreach (['first retained', 'second retained'] as $message) {
            DB::table('notifications')->insert([
                'user_id' => $userId,
                'type' => 'migration-test',
                'message' => $message,
                'dedupe_key' => 'f113-duplicate',
            ]);
        }

        try {
            $this->migration('2026_10_22_000001_add_order_context_to_notifications_table.php')->up();
            $this->fail('Expected a diagnostic exception for duplicate dedupe keys.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('dedupe_key مكررة', $exception->getMessage());
        }

        $this->assertSame(2, DB::table('notifications')->where('dedupe_key', 'f113-duplicate')->count());
        $this->assertDatabaseHas('notifications', ['dedupe_key' => 'f113-duplicate', 'message' => 'first retained']);
        $this->assertDatabaseHas('notifications', ['dedupe_key' => 'f113-duplicate', 'message' => 'second retained']);
    }

    public function test_partial_purchase_schema_fails_without_dropping_the_table(): void
    {
        $this->dropForeignKey('purchase_items', 'purchase_id');

        try {
            $this->migration('2026_10_17_000001_create_purchase_items_table.php')->up();
            $this->fail('Expected a diagnostic exception for a partial purchase_items schema.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('purchase_id', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('purchase_items'));
        $this->assertFalse($this->hasForeignKey('purchase_items', 'purchase_id'));
    }

    public function test_purchase_schema_with_wrong_varchar_length_is_rejected(): void
    {
        DB::statement('ALTER TABLE `purchase_items` MODIFY `medicine_name` VARCHAR(199) NOT NULL');

        try {
            $this->migration('2026_10_17_000001_create_purchase_items_table.php')->up();
            $this->fail('Expected a diagnostic exception for an incorrect purchase item length.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('طول purchase_items.medicine_name', $exception->getMessage());
        }

        $this->assertMysqlColumn('purchase_items', 'medicine_name', 'varchar(199)', 'NO');
        $this->assertForeignKey('purchase_items', 'purchase_id', 'purchases', 'id', 'CASCADE');
    }

    public function test_notification_schema_with_wrong_dedupe_length_is_rejected_without_changing_the_row(): void
    {
        [$userId] = $this->createUserAndOrder();
        $id = DB::table('notifications')->insertGetId([
            'user_id' => $userId,
            'type' => 'migration-test',
            'message' => 'preserve length fixture',
            'dedupe_key' => 'f113-length',
        ]);
        DB::statement('ALTER TABLE `notifications` MODIFY `dedupe_key` VARCHAR(190) NULL');

        try {
            $this->migration('2026_10_22_000001_add_order_context_to_notifications_table.php')->up();
            $this->fail('Expected a diagnostic exception for an incorrect dedupe key length.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('notifications.dedupe_key', $exception->getMessage());
        }

        $this->assertMysqlColumn('notifications', 'dedupe_key', 'varchar(190)', 'YES');
        $this->assertDatabaseHas('notifications', ['id' => $id, 'dedupe_key' => 'f113-length', 'message' => 'preserve length fixture']);
    }

    public function test_legacy_payment_default_is_removed_and_backfill_uses_order_snapshot_fields(): void
    {
        [$userId, $orderId] = $this->createUserAndOrder(50.00, 5.00);
        DB::statement("ALTER TABLE `order_payment_submissions` MODIFY `amount_status` VARCHAR(255) NOT NULL DEFAULT 'matches'");

        $matchingId = $this->insertPayment($orderId, 55.00);
        $overId = $this->insertPayment($orderId, 60.00);
        $this->migration('2026_10_25_000001_add_amount_reconciliation_to_payment_submissions.php')->up();

        $matching = DB::table('order_payment_submissions')->where('id', $matchingId)->first();
        $over = DB::table('order_payment_submissions')->where('id', $overId)->first();
        $this->assertSame('matches', $matching->amount_status);
        $this->assertSame(55.0, (float) $matching->order_grand_total);
        $this->assertSame('over', $over->amount_status);
        $this->assertSame(5.0, (float) $over->overage_amount);
        $this->assertMysqlColumn('order_payment_submissions', 'amount_status', 'varchar(255)', 'YES');
        $this->assertNull($this->mysqlColumn('order_payment_submissions', 'amount_status')->column_default);
    }

    public function test_down_guards_refuse_to_destroy_purchase_table_or_local_only_barcode(): void
    {
        try {
            $this->migration('2026_10_17_000001_create_purchase_items_table.php')->down();
            $this->fail('Purchase rollback must not drop existing purchase_items.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('سيحذف جدولًا', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('purchase_items'));

        $localId = DB::table('medicines')->insertGetId([
            'trade_name' => 'Local-only rollback fixture',
            'active_ingredient' => 'fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $barcodeId = DB::table('medicine_barcodes')->insertGetId([
            'moh_medicine_id' => null,
            'local_medicine_id' => $localId,
            'barcode' => 'F113-LOCAL-ONLY',
            'source' => 'migration-test',
        ]);

        try {
            $this->migration('2026_10_27_000001_make_moh_medicine_id_nullable_on_medicine_barcodes.php')->down();
            $this->fail('Barcode rollback must refuse to nullify a local-only barcode.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('باركود محلي بلا سجل MOH', $exception->getMessage());
        }
        $this->assertDatabaseHas('medicine_barcodes', ['id' => $barcodeId, 'local_medicine_id' => $localId, 'moh_medicine_id' => null]);
    }

    private function createUserAndOrder(float $total = 20.00, float $deliveryFee = 0.00): array
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'F113 MySQL fixture',
            'password' => bcrypt('test-only'),
            'role' => 'patient',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $userId,
            'total' => $total,
            'delivery_fee' => $deliveryFee,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$userId, $orderId];
    }

    private function createBarcode(): array
    {
        $mohId = DB::table('moh_medicines')->insertGetId([
            'trade_name' => 'F113 MOH fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $localId = DB::table('medicines')->insertGetId([
            'trade_name' => 'F113 local fixture',
            'active_ingredient' => 'fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $barcodeId = DB::table('medicine_barcodes')->insertGetId([
            'moh_medicine_id' => $mohId,
            'local_medicine_id' => $localId,
            'barcode' => 'F113-BARCODE-KEEP',
            'source' => 'migration-test',
        ]);

        return [$mohId, $localId, $barcodeId];
    }

    private function insertPayment(int $orderId, float $amount): int
    {
        return DB::table('order_payment_submissions')->insertGetId([
            'order_id' => $orderId,
            'bank_name' => 'F113 test bank',
            'account_holder' => 'F113 test account',
            'account_number' => '0000000000',
            'amount' => $amount,
            'receipt_path' => 'test/f113.jpg',
            'receipt_original_name' => 'f113.jpg',
            'receipt_mime' => 'image/jpeg',
            'receipt_size' => 1,
            'status' => 'under_review',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function migration(string $file): object
    {
        return require database_path('migrations/'.$file);
    }

    private function mysqlColumn(string $table, string $column): ?object
    {
        return DB::selectOne(
            'SELECT COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default '
            .'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
    }

    private function assertMysqlColumn(string $table, string $column, string $type, string $nullable): void
    {
        $actual = $this->mysqlColumn($table, $column);
        $this->assertNotNull($actual, "Missing {$table}.{$column} in information_schema.");
        $this->assertSame($type, strtolower($actual->column_type));
        $this->assertSame($nullable, $actual->is_nullable);
    }

    private function foreignKey(string $table, string $column): ?object
    {
        return DB::selectOne(
            'SELECT k.CONSTRAINT_NAME AS constraint_name, k.REFERENCED_TABLE_NAME AS target_table, '
            .'k.REFERENCED_COLUMN_NAME AS target_column, r.DELETE_RULE AS delete_rule '
            .'FROM information_schema.KEY_COLUMN_USAGE k '
            .'JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
            .'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME '
            .'WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.COLUMN_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL',
            [$table, $column]
        );
    }

    private function assertForeignKey(string $table, string $column, string $target, string $targetColumn, string $deleteRule): void
    {
        $foreignKey = $this->foreignKey($table, $column);
        $this->assertNotNull($foreignKey, "Missing FK {$table}.{$column}.");
        $this->assertSame($target, $foreignKey->target_table);
        $this->assertSame($targetColumn, $foreignKey->target_column);
        $this->assertSame($deleteRule, strtoupper($foreignKey->delete_rule));
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        return $this->foreignKey($table, $column) !== null;
    }

    private function dropForeignKey(string $table, string $column): void
    {
        $foreignKey = $this->foreignKey($table, $column);
        $this->assertNotNull($foreignKey, "Cannot prepare partial schema: {$table}.{$column} has no FK.");
        if (! preg_match('/^[A-Za-z0-9_]+$/', $foreignKey->constraint_name)) {
            throw new RuntimeException('Unexpected constraint identifier in MySQL metadata.');
        }
        DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$foreignKey->constraint_name}`");
    }

    private function assertIndex(string $table, string $name, array $columns, bool $unique): void
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique FROM information_schema.STATISTICS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
            [$table, $name]
        );
        $this->assertSame($columns, array_map(fn (object $row): string => $row->column_name, $rows));
        $this->assertSame($unique, $rows !== [] && (int) $rows[0]->non_unique === 0);
    }

    private function hasLeadingIndex(string $table, string $column): bool
    {
        return DB::selectOne(
            'SELECT 1 AS found FROM information_schema.STATISTICS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1 LIMIT 1',
            [$table, $column]
        ) !== null;
    }
}
