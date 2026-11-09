<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Order;
use App\Models\PlatformSettlement;
use App\Models\SubOrder;
use App\Services\PlatformSettlementService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

/**
 * Regression for P0.3: `UploadedFile::store()` returns `false` when the write
 * fails (the disk is configured with `throw => false`). The old controller never
 * checked it, so a settlement could be marked "paid" while its transfer receipt
 * was never written — and the receipt was deleted again on the next request.
 */
class SettlementReceiptStorageTest extends TestCase
{
    use BuildsDeliveryOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryWorld();
        $this->admin->syncRoles(['admin']);
    }

    /**
     * ملاحظة: `checkoutTwoPharmacy()` يسجّل الدخول كـpatient داخليًا،
     * لذلك يجب تولي دور الأدمن بعده لا قبله.
     */
    private function deliveredPaidOrder(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $this->markOrderPaid($order);
        $this->subOrderFor($order, $this->pharmacyA)
            ->update(['status' => SubOrder::STATUS_DELIVERED, 'delivered_at' => '2026-10-29 10:00:00']);
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_a_failed_disk_write_never_records_a_paid_settlement(): void
    {
        $this->deliveredPaidOrder();

        // قرص "معطوب" حقيقي: مسار الجذر ملف لا مجلد، فلا يمكن إنشاء مجلد داخله.
        // `store()` ترجع false (القرص مضبوط على throw=false) — نفس سلوك قرص ممتلئ
        // أو للقراءة فقط، لكن دون أي محاكاة.
        $blockedRoot = storage_path('framework/testing/blocked-disk-'.uniqid());
        file_put_contents($blockedRoot, 'not-a-directory');

        config([
            'filesystems.disks.blocked_receipts' => [
                'driver' => 'local',
                'root' => $blockedRoot,
                'throw' => false,
                'report' => false,
            ],
            'filesystems.settlement_receipt_disk' => 'blocked_receipts',
        ]);

        try {
            $this->withHeaders(['Accept' => 'application/json'])
                ->post('/api/admin/platform-settlements/pharmacies/'.$this->pharmacyA->id.'/pay', [
                    'receipt' => UploadedFile::fake()->create('proof.png', 40, 'image/png'),
                ])
                ->assertStatus(500)
                ->assertJsonPath('success', false);

            $this->assertSame(0, PlatformSettlement::count());
            $this->assertDatabaseMissing('platform_settlements', ['status' => PlatformSettlement::STATUS_PAID]);
        } finally {
            @unlink($blockedRoot);
        }
    }

    public function test_an_unconfigured_disk_fails_loudly_instead_of_silently_paying(): void
    {
        $this->deliveredPaidOrder();
        config(['filesystems.settlement_receipt_disk' => 'disk-that-does-not-exist']);

        $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/admin/platform-settlements/pharmacies/'.$this->pharmacyA->id.'/pay', [
                'receipt' => UploadedFile::fake()->create('proof.png', 40, 'image/png'),
            ])
            ->assertStatus(500)
            ->assertJsonPath('success', false);

        $this->assertSame(0, PlatformSettlement::count());
    }

    public function test_a_successful_payment_persists_a_real_receipt_file(): void
    {
        Storage::fake('local');
        $this->deliveredPaidOrder();

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/admin/platform-settlements/pharmacies/'.$this->pharmacyA->id.'/pay', [
                'receipt' => UploadedFile::fake()->create('bank-notice.png', 40, 'image/png'),
                'transfer_reference' => 'BANK-STORAGE-1',
            ])->assertOk()->assertJsonPath('data.0.receipt_available', true);

        $settlement = PlatformSettlement::findOrFail($response->json('data.0.id'));
        $this->assertSame(PlatformSettlement::STATUS_PAID, $settlement->status);
        $this->assertNotEmpty($settlement->transfer_receipt_path);
        $this->assertTrue(Storage::disk('local')->exists($settlement->transfer_receipt_path));

        $this->get('/api/admin/platform-settlements/'.$settlement->id.'/receipt')->assertOk();
    }

    public function test_the_domain_layer_refuses_a_paid_settlement_without_a_stored_receipt(): void
    {
        $this->deliveredPaidOrder();
        $settlement = app(PlatformSettlementService::class)
            ->createDraft($this->pharmacyA, '2026-10-27', '2026-11-02', $this->admin->id);

        $this->expectException(InvalidArgumentException::class);

        app(PlatformSettlementService::class)->markPaid($settlement, 'REF', now(), $this->admin->id, []);
    }

    public function test_a_receipt_that_disappears_after_payment_is_reported_as_missing(): void
    {
        Storage::fake('local');
        $this->deliveredPaidOrder();
        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/admin/platform-settlements/pharmacies/'.$this->pharmacyA->id.'/pay', [
                'receipt' => UploadedFile::fake()->create('gone.png', 40, 'image/png'),
            ])->assertOk();

        $settlement = PlatformSettlement::findOrFail($response->json('data.0.id'));
        Storage::disk('local')->delete($settlement->transfer_receipt_path);

        $this->getJson('/api/admin/platform-settlements/'.$settlement->id.'/receipt')->assertNotFound();
    }

    public function test_the_pharmacy_can_only_download_its_own_receipt(): void
    {
        Storage::fake('local');
        $this->deliveredPaidOrder();
        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/admin/platform-settlements/pharmacies/'.$this->pharmacyA->id.'/pay', [
                'receipt' => UploadedFile::fake()->create('mine.png', 40, 'image/png'),
            ])->assertOk();
        $settlementId = $response->json('data.0.id');

        $this->actingAs($this->pharmacyA->user, 'sanctum');
        $this->get('/api/pharmacy/accounting/platform-settlements/'.$settlementId.'/receipt')->assertOk();

        $this->actingAs($this->pharmacyB->user, 'sanctum');
        $this->get('/api/pharmacy/accounting/platform-settlements/'.$settlementId.'/receipt')->assertNotFound();
    }

    public function test_a_legacy_receipt_without_a_driver_still_reads_from_the_disk(): void
    {
        Storage::fake('local');
        $this->deliveredPaidOrder();
        $settlementId = $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/admin/platform-settlements/pharmacies/'.$this->pharmacyA->id.'/pay', [
                'receipt' => UploadedFile::fake()->create('legacy.png', 40, 'image/png'),
            ])->assertOk()->json('data.0.id');

        // محاكاة صف أُنشئ قبل هجرة driver/meta: القيمة null تعني «قرص».
        $settlement = PlatformSettlement::findOrFail($settlementId);
        $settlement->transfer_receipt_driver = null;
        $settlement->transfer_receipt_meta = null;
        $settlement->save();

        $this->get('/api/admin/platform-settlements/'.$settlementId.'/receipt')->assertOk();
    }

    public function test_order_lifecycle_is_unaffected_when_the_settlement_payment_fails(): void
    {
        $this->deliveredPaidOrder();
        $orderCount = Order::count();
        $subOrderCount = SubOrder::count();
        config(['filesystems.settlement_receipt_disk' => 'disk-that-does-not-exist']);

        $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/admin/platform-settlements/pharmacies/'.$this->pharmacyA->id.'/pay', [
                'receipt' => UploadedFile::fake()->create('proof.png', 40, 'image/png'),
            ])->assertStatus(500);

        $this->assertSame($orderCount, Order::count());
        $this->assertSame($subOrderCount, SubOrder::count());
        $this->assertSame(SubOrder::STATUS_DELIVERED, $this->subOrderFor(Order::first(), $this->pharmacyA)->status);
    }
    public function test_missing_cloudinary_never_affects_fake_disk_financial_payment(): void
    {
        config(['services.cloudinary.receipts.driver' => 'disk', 'services.cloudinary.cloud' => null, 'services.cloudinary.upload_preset' => null]);
        Storage::fake('local');
        \Illuminate\Support\Facades\Http::fake();
        $this->deliveredPaidOrder();
        $this->withHeaders(['Accept' => 'application/json'])->post('/api/admin/platform-settlements/pharmacies/'.$this->pharmacyA->id.'/pay', [
            'receipt' => UploadedFile::fake()->create('receipt.png', 40, 'image/png'),
        ])->assertOk();
        $this->assertSame(PlatformSettlement::STATUS_PAID, PlatformSettlement::firstOrFail()->status);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_readiness_rejects_production_fallback_and_requires_cloudinary_settings(): void
    {
        $this->app['env'] = 'production';
        try {
            foreach (['disk', 'auto', 'unknown'] as $driver) {
                config(['services.cloudinary.receipts.driver' => $driver]);
                $this->artisan('settlements:check-receipt-storage')->assertExitCode(1);
            }
            config(['services.cloudinary.receipts.driver' => 'cloudinary_public', 'services.cloudinary.cloud' => null, 'services.cloudinary.upload_preset' => null]);
            $this->artisan('settlements:check-receipt-storage')->assertExitCode(1);
            config(['services.cloudinary.cloud' => 'example-cloud', 'services.cloudinary.upload_preset' => 'example-preset']);
            \Illuminate\Support\Facades\Http::fake();
            $this->artisan('settlements:check-receipt-storage')->assertExitCode(0);
            \Illuminate\Support\Facades\Http::assertNothingSent();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_phpunit_forces_testing_driver_and_environment(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('disk', config('services.cloudinary.receipts.driver'));
    }

    /**
     * حارس الإنتاج يجب أن يمنع القرص المؤقت لا أن يمنع الوضع الأكثر أمانًا.
     * كان يشترط cloudinary_public حصرًا، فيحجب السائق الموقّع `authenticated`
     * — وهو عكس ترتيب الأمان المطلوب.
     */
    public function test_production_accepts_the_authenticated_driver(): void
    {
        $this->app['env'] = 'production';

        try {
            config([
                'services.cloudinary.cloud' => 'test-cloud',
                'services.cloudinary.receipts.driver' => 'cloudinary',
                'services.cloudinary.receipts.api_key' => 'key',
                'services.cloudinary.receipts.api_secret' => 'secret',
            ]);

            $this->assertSame('cloudinary', app(\App\Services\Settlement\ReceiptStorage::class)->driver());
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
