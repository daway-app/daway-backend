<?php

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * الهجرة 2026_11_01_000003 تضيف عمودَي بيانات استرجاع الإيصال.
 *
 * المطلوب إثباته: الأعمدة تُضاف، وrollback يعيد الشكل السابق **دون** المساس
 * بأعمدة الإيصال الأصلية (path/name/mime/size) — أي لا فقدان معلومات.
 */
class PlatformSettlementReceiptColumnsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path(
            'migrations/2026_11_01_000003_add_receipt_driver_and_meta_to_platform_settlements.php'
        );
    }

    public function test_the_migration_adds_the_cloudinary_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('platform_settlements', 'transfer_receipt_driver'));
        $this->assertTrue(Schema::hasColumn('platform_settlements', 'transfer_receipt_meta'));
    }

    public function test_rollback_removes_only_the_new_columns_and_keeps_the_receipt_data(): void
    {
        $migration = $this->migration();
        $migration->down();

        $this->assertFalse(Schema::hasColumn('platform_settlements', 'transfer_receipt_driver'));
        $this->assertFalse(Schema::hasColumn('platform_settlements', 'transfer_receipt_meta'));

        // الأعمدة الأصلية للإيصال تبقى — لا فقدان معلومات عند التراجع.
        foreach (['transfer_receipt_path', 'transfer_receipt_original_name', 'transfer_receipt_mime', 'transfer_receipt_size'] as $column) {
            $this->assertTrue(Schema::hasColumn('platform_settlements', $column), $column);
        }

        // إعادة التطبيق تعمل بعد التراجع (idempotent عمليًا).
        $migration->up();
        $this->assertTrue(Schema::hasColumn('platform_settlements', 'transfer_receipt_driver'));
        $this->assertTrue(Schema::hasColumn('platform_settlements', 'transfer_receipt_meta'));
    }

    public function test_legacy_rows_with_null_columns_remain_readable(): void
    {
        // صف قديم قبل الهجرة: العمودان null، والقراءة يجب أن تعمل.
        $id = \Illuminate\Support\Facades\DB::table('platform_settlements')->insertGetId([
            'pharmacy_name' => 'صيدلية قديمة',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency' => 'ILS',
            'orders_total' => 100,
            'adjustments_total' => 0,
            'net_total' => 100,
            'status' => 'paid',
            'transfer_receipt_path' => 'platform-settlement-receipts/legacy.pdf',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = \App\Models\PlatformSettlement::findOrFail($id);

        $this->assertNull($row->transfer_receipt_driver);
        $this->assertNull($row->transfer_receipt_meta);
        $this->assertSame('platform-settlement-receipts/legacy.pdf', $row->transfer_receipt_path);
    }
}
