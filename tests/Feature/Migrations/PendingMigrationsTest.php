<?php

namespace Tests\Feature\Migrations;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase F1 — حِراسة الهجرات المعلّقة.
 *
 * ما تحميه:
 *  1. هجرة `notifications` **idempotent**: إعادة تشغيلها على مخطط فيه الأعمدة
 *     والفهارس لا ترمي "Duplicate column/index" (كانت تفعل قبل التحصين).
 *  2. `amount_status` **بلا default كاذب**: الصف غير المُقيَّم يبقى NULL،
 *     ولا يُدَّعى أنه `matches`.
 *  3. إعادة البناء للصفوف القديمة تعتمد على بيانات الطلب الفعلية
 *     (`total + delivery_fee`) بنفس دقة `PaymentFlowService::compareAmount`.
 */
final class PendingMigrationsTest extends TestCase
{
    /** @return object */
    private function notificationsMigration()
    {
        return require database_path('migrations/2026_10_22_000001_add_order_context_to_notifications_table.php');
    }

    /** @return object */
    private function reconciliationMigration()
    {
        return require database_path('migrations/2026_10_25_000001_add_amount_reconciliation_to_payment_submissions.php');
    }

    private function barcodeMigration()
    {
        return require database_path('migrations/2026_10_27_000001_make_moh_medicine_id_nullable_on_medicine_barcodes.php');
    }

    private function indexNames(string $table): array
    {
        return array_map(
            fn ($index) => $index['name'] ?? '',
            Schema::getIndexes($table)
        );
    }

    public function test_notifications_migration_rerun_is_a_no_op(): void
    {
        // الهجرة نُفِّذت أصلًا عبر RefreshDatabase — إعادة تشغيلها يجب ألا تفشل.
        $this->notificationsMigration()->up();
        $this->notificationsMigration()->up();

        $this->assertTrue(Schema::hasColumn('notifications', 'order_id'));
        $this->assertTrue(Schema::hasColumn('notifications', 'sub_order_id'));
        $this->assertTrue(Schema::hasColumn('notifications', 'dedupe_key'));

        $indexes = $this->indexNames('notifications');

        // فهرس واحد لكل اسم — لا تكرار ولا فشل
        $this->assertSame(1, count(array_keys($indexes, 'notifications_dedupe_key_unique', true)));
        $this->assertSame(1, count(array_keys($indexes, 'notifications_user_read_created_index', true)));
    }

    public function test_notifications_repairs_an_existing_context_column_without_fk_and_preserves_row(): void
    {
        $user = User::factory()->patient()->create();
        $order = Order::unguarded(fn () => Order::create([
            'user_id' => $user->id,
            'total' => 20,
        ]));

        Schema::drop('notifications');
        Schema::create('notifications', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('medicine_id')->nullable();
            $table->string('type', 50);
            $table->string('message');
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->nullable();
            // Partial schema: column and data exist, but its FK does not.
            $table->unsignedBigInteger('order_id')->nullable();
        });

        DB::table('notifications')->insert([
            'user_id' => $user->id,
            'type' => 'order',
            'message' => 'keep this row',
            'order_id' => $order->id,
        ]);

        $this->notificationsMigration()->up();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'message' => 'keep this row',
            'order_id' => $order->id,
        ]);
        $orderForeignKey = collect(Schema::getForeignKeys('notifications'))
            ->first(fn (array $key): bool => ($key['columns'] ?? []) === ['order_id']);
        $this->assertNotNull($orderForeignKey);
        $this->assertSame('orders', $orderForeignKey['foreign_table']);
        $this->assertSame(['id'], $orderForeignKey['foreign_columns']);
        $this->assertSame('set null', strtolower($orderForeignKey['on_delete']));
    }

    public function test_duplicate_notification_dedupe_keys_fail_without_deleting_rows(): void
    {
        $user = User::factory()->patient()->create();
        Schema::table('notifications', fn ($table) => $table->dropUnique('notifications_dedupe_key_unique'));

        foreach (['first retained', 'second retained'] as $message) {
            DB::table('notifications')->insert([
                'user_id' => $user->id,
                'type' => 'test',
                'message' => $message,
                'dedupe_key' => 'duplicate-key',
            ]);
        }

        try {
            $this->notificationsMigration()->up();
            $this->fail('Expected a diagnostic failure for duplicate dedupe keys.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('dedupe_key مكررة', $exception->getMessage());
        }

        $this->assertSame(2, DB::table('notifications')->where('dedupe_key', 'duplicate-key')->count());
    }

    public function test_nullable_barcode_column_without_fk_is_repaired_without_changing_local_link_or_barcode(): void
    {
        $mohId = DB::table('moh_medicines')->insertGetId([
            'trade_name' => 'Migration recovery fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::drop('medicine_barcodes');
        Schema::create('medicine_barcodes', function ($table): void {
            $table->id();
            // Partial schema: nullable column exists but has no FK.
            $table->unsignedBigInteger('moh_medicine_id')->nullable();
            $table->unsignedBigInteger('local_medicine_id')->nullable();
            $table->string('barcode', 32);
        });
        $id = DB::table('medicine_barcodes')->insertGetId([
            'moh_medicine_id' => $mohId,
            'local_medicine_id' => 987654,
            'barcode' => 'LOCAL-KEEP-001',
        ]);

        $this->barcodeMigration()->up();

        $this->assertDatabaseHas('medicine_barcodes', [
            'id' => $id,
            'moh_medicine_id' => $mohId,
            'local_medicine_id' => 987654,
            'barcode' => 'LOCAL-KEEP-001',
        ]);
        $foreignKey = collect(Schema::getForeignKeys('medicine_barcodes'))
            ->first(fn (array $key): bool => ($key['columns'] ?? []) === ['moh_medicine_id']);
        $this->assertNotNull($foreignKey);
        $this->assertSame('moh_medicines', $foreignKey['foreign_table']);
        $this->assertSame(['id'], $foreignKey['foreign_columns']);
        $this->assertSame('cascade', strtolower($foreignKey['on_delete']));
    }

    public function test_amount_status_is_null_for_a_row_that_was_never_evaluated(): void
    {
        $user = User::factory()->patient()->create();
        $order = Order::unguarded(fn () => Order::create([
            'user_id' => $user->id,
            'total' => 100,
            'delivery_fee' => 15,
        ]));

        $id = $this->insertSubmission($order->id, 100.00);

        $row = DB::table('order_payment_submissions')->where('id', $id)->first();

        // 🔴 لا `matches` تلقائيًا: الصف لم يُقيَّم بعد.
        $this->assertNull($row->amount_status);
        $this->assertNull($row->order_grand_total);
        $this->assertSame(0.0, (float) $row->overage_amount);
    }

    public function test_legacy_rows_are_backfilled_from_real_order_totals(): void
    {
        $user = User::factory()->patient()->create();
        $order = Order::unguarded(fn () => Order::create([
            'user_id' => $user->id,
            'total' => 100,
            'delivery_fee' => 15,
        ]));

        $under = $this->insertSubmission($order->id, 100.00);   // أقل بـ15
        $over = $this->insertSubmission($order->id, 120.00);    // أكثر بـ5
        $exact = $this->insertSubmission($order->id, 115.00);   // مطابق

        $this->reconciliationMigration()->up();

        $underRow = DB::table('order_payment_submissions')->where('id', $under)->first();
        $overRow = DB::table('order_payment_submissions')->where('id', $over)->first();
        $exactRow = DB::table('order_payment_submissions')->where('id', $exact)->first();

        $this->assertSame(115.0, (float) $underRow->order_grand_total);
        $this->assertSame('under', $underRow->amount_status);
        $this->assertSame(0.0, (float) $underRow->overage_amount);

        $this->assertSame('over', $overRow->amount_status);
        $this->assertSame(5.0, (float) $overRow->overage_amount);

        $this->assertSame('matches', $exactRow->amount_status);
        $this->assertSame(0.0, (float) $exactRow->overage_amount);
    }

    /**
     * لماذا لا يوجد اختبار لـ«صف بلا طلب»؟
     *
     * لأن المخطط يمنعه: `order_id` مفتاح أجنبي `cascadeOnDelete` على `orders`.
     * أي إرسال دفع بلا طلب مستحيل، وحذف الطلب يحذف إرسالاته. لذلك فرع
     * `$order === null` داخل الهجرة **دفاعي فقط** (سباق قراءة أثناء حذف)،
     * ولا يمكن الوصول إليه ببيانات مشروعة — وهذا ما يثبته الاختبار أدناه.
     */
    public function test_a_submission_cannot_reference_a_missing_order(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->insertSubmission(999999, 50.00);
    }

    public function test_rerunning_the_reconciliation_migration_does_not_rewrite_evaluated_rows(): void
    {
        $user = User::factory()->patient()->create();
        $order = Order::unguarded(fn () => Order::create([
            'user_id' => $user->id,
            'total' => 100,
            'delivery_fee' => 15,
        ]));

        $id = $this->insertSubmission($order->id, 115.00);

        $this->reconciliationMigration()->up();

        // تغيير إجمالي الطلب لاحقًا يجب ألا يُعيد كتابة صف سبق تقييمه
        $order->forceFill(['total' => 200])->save();

        $this->reconciliationMigration()->up();

        $row = DB::table('order_payment_submissions')->where('id', $id)->first();

        $this->assertSame(115.0, (float) $row->order_grand_total, 'الصف المُقيَّم لا يُعاد بناؤه');
        $this->assertSame('matches', $row->amount_status);
    }

    private function insertSubmission(int $orderId, float $amount): int
    {
        return DB::table('order_payment_submissions')->insertGetId([
            'order_id' => $orderId,
            'bank_name' => 'بنك الاختبار',
            'account_holder' => 'دواوي',
            'account_number' => '0123456789',
            'amount' => $amount,
            'receipt_path' => 'receipts/test.jpg',
            'receipt_original_name' => 'test.jpg',
            'receipt_mime' => 'image/jpeg',
            'receipt_size' => 100,
            'status' => 'under_review',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
