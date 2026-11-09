<?php

namespace Tests\Feature\Api\Admin;

use App\Models\PlatformSettlement;
use App\Models\SubOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

/**
 * الوضع المعتمد: رفع الإيصالات **عام غير موقّع** عبر CLOUDINARY_UPLOAD_PRESET.
 *
 * هذا الوضع لا يحتاج API Key/Secret، لكنه **لا يحمي الملف**: أي شخص يملك
 * الرابط يفتحه. الاختبارات تثبت:
 *  · أن الرفع غير موقّع فعلًا (بلا signature ولا api_key)؛
 *  · أن الـAPI لا يُرجع الرابط أبدًا، وأن التنزيل يمر عبر Laravel بعد فحص الصلاحية؛
 *  · أن الصور فقط مدعومة و PDF مرفوض.
 *
 * ما لا يثبته: أن حساب Cloudinary الحقيقي سيقبل الرفع — هذا يحتاج اختبارًا حقيقيًا.
 */
class SettlementReceiptPublicCloudinaryTest extends TestCase
{
    use BuildsDeliveryOrders;

    private const CLOUD = 'test-cloud';
    private const PRESET = 'daway_medicines';
    private const URL = 'https://res.cloudinary.com/test-cloud/image/upload/v1/daway/settlement-receipts/r1.png';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryWorld();
        $this->admin->syncRoles(['admin']);

        config([
            'services.cloudinary.cloud' => self::CLOUD,
            'services.cloudinary.upload_preset' => self::PRESET,
            'services.cloudinary.receipts.driver' => 'cloudinary_public',
            'services.cloudinary.receipts.folder' => 'daway/settlement-receipts',
            // لا مفاتيح في هذا الوضع
            'services.cloudinary.receipts.api_key' => null,
            'services.cloudinary.receipts.api_secret' => null,
        ]);
    }

    private function deliveredPaidOrder(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $this->markOrderPaid($order);
        $this->subOrderFor($order, $this->pharmacyA)
            ->update(['status' => SubOrder::STATUS_DELIVERED, 'delivered_at' => '2026-10-29 10:00:00']);
        $this->actingAs($this->admin, 'sanctum');
    }

    /**
     * نداء fake واحد فقط لكل اختبار: Laravel لا يستبدل الـstubs عند نداء ثانٍ،
     * فأول نمط مطابق يفوز — ولذلك نوحّد الرفع والجلب هنا.
     */
    private function fakeCloudinary(bool $remoteImageAvailable = true): void
    {
        Http::fake([
            'api.cloudinary.com/*' => Http::response([
                'public_id' => 'daway/settlement-receipts/r1',
                'secure_url' => self::URL,
                'resource_type' => 'image',
                'type' => 'upload',
                'format' => 'png',
                'bytes' => 2048,
            ], 200),
            'res.cloudinary.com/*' => $remoteImageAvailable
                ? Http::response('IMAGE_BYTES', 200)
                : Http::response('', 404),
        ]);
    }

    private function pay(string $name = 'receipt.png', string $mime = 'image/png'): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/admin/platform-settlements/pharmacies/'.$this->pharmacyA->id.'/pay', [
                'receipt' => UploadedFile::fake()->create($name, 40, $mime),
                'transfer_reference' => 'PUB-1',
            ]);
    }

    public function test_the_upload_is_unsigned_and_uses_the_existing_preset(): void
    {
        $this->fakeCloudinary();
        $this->deliveredPaidOrder();

        $this->pay()->assertOk()->assertJsonPath('data.0.receipt_available', true);

        Http::assertSent(function ($request): bool {
            $body = (string) $request->body();

            return str_contains($request->url(), '/image/upload')
                && str_contains($body, 'upload_preset')
                && str_contains($body, self::PRESET)
                && ! str_contains($body, 'signature')
                && ! str_contains($body, 'api_key')
                && str_contains($body, 'daway/settlement-receipts');
        });
    }

    public function test_the_receipt_is_stored_as_a_public_asset_with_its_reference(): void
    {
        $this->fakeCloudinary();
        $this->deliveredPaidOrder();

        $id = $this->pay()->assertOk()->json('data.0.id');
        $row = PlatformSettlement::findOrFail($id);

        $this->assertSame('cloudinary_public', $row->transfer_receipt_driver);
        $this->assertSame('daway/settlement-receipts/r1', $row->transfer_receipt_path);
        $this->assertSame('public', $row->transfer_receipt_meta['delivery']);
        $this->assertSame('daway/settlement-receipts/r1', $row->transfer_receipt_meta['public_id']);
        $this->assertSame(self::URL, $row->transfer_receipt_meta['url']);
    }

    public function test_no_storage_url_is_exposed_in_any_api_response(): void
    {
        $this->fakeCloudinary();
        $this->deliveredPaidOrder();

        $payResponse = $this->pay()->assertOk();
        $listResponse = $this->getJson('/api/admin/platform-settlements')->assertOk();

        foreach ([$payResponse->getContent(), $listResponse->getContent()] as $content) {
            $this->assertStringNotContainsString('res.cloudinary.com', $content);
            $this->assertStringNotContainsString('secure_url', $content);
            $this->assertStringNotContainsString(self::PRESET, $content);
        }
    }

    public function test_pdf_is_rejected_in_the_public_mode(): void
    {
        $this->fakeCloudinary();
        $this->deliveredPaidOrder();

        $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/admin/platform-settlements/pharmacies/'.$this->pharmacyA->id.'/pay', [
                'receipt' => UploadedFile::fake()->create('receipt.pdf', 40, 'application/pdf'),
            ])->assertStatus(422);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.cloudinary.com'));
        $this->assertSame(0, PlatformSettlement::count());
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        $this->fakeCloudinary();
        $this->deliveredPaidOrder();

        $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/admin/platform-settlements/pharmacies/'.$this->pharmacyA->id.'/pay', [
                'receipt' => UploadedFile::fake()->create('payload.zip', 10, 'application/zip'),
            ])->assertStatus(422);

        $this->assertSame(0, PlatformSettlement::count());
    }

    public function test_a_rejected_upload_never_records_a_payment(): void
    {
        Http::fake(['api.cloudinary.com/*' => Http::response(['error' => ['message' => 'preset rejected']], 400)]);
        $this->deliveredPaidOrder();

        $this->pay()->assertStatus(500)->assertJsonPath('success', false);

        $this->assertSame(0, PlatformSettlement::count());
    }

    public function test_the_owning_pharmacy_downloads_through_laravel_and_the_url_stays_hidden(): void
    {
        $this->fakeCloudinary();
        $this->deliveredPaidOrder();
        $id = $this->pay()->assertOk()->json('data.0.id');

        $this->actingAs($this->pharmacyA->user, 'sanctum');
        $response = $this->get('/api/pharmacy/accounting/platform-settlements/'.$id.'/receipt')->assertOk();

        $this->assertStringContainsString('IMAGE_BYTES', $response->streamedContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_another_pharmacy_cannot_download_the_receipt(): void
    {
        $this->fakeCloudinary();
        $this->deliveredPaidOrder();
        $id = $this->pay()->assertOk()->json('data.0.id');

        $this->actingAs($this->pharmacyB->user, 'sanctum');
        $this->get('/api/pharmacy/accounting/platform-settlements/'.$id.'/receipt')->assertNotFound();
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->fakeCloudinary();
        $this->deliveredPaidOrder();
        $id = $this->pay()->assertOk()->json('data.0.id');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/pharmacy/accounting/platform-settlements/'.$id.'/receipt')->assertUnauthorized();
    }

    public function test_a_missing_remote_image_reports_404(): void
    {
        // الصورة اختفت من Cloudinary: فحص الوجود يرجع 404.
        $this->fakeCloudinary(remoteImageAvailable: false);
        $this->deliveredPaidOrder();
        $id = $this->pay()->assertOk()->json('data.0.id');

        $this->getJson('/api/admin/platform-settlements/'.$id.'/receipt')->assertNotFound();
    }

    public function test_the_orphan_command_reports_that_public_assets_cannot_be_deleted(): void
    {
        $this->fakeCloudinary();

        $this->artisan('settlements:prune-orphan-receipts', ['--hours' => 24])
            ->expectsOutputToContain('cloudinary_public')
            ->assertSuccessful();
    }
    public function test_timeout_never_records_a_payment(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));
        $this->deliveredPaidOrder();
        $this->pay()->assertStatus(500);
        $this->assertSame(0, PlatformSettlement::count());
    }

    public function test_incomplete_upload_response_never_records_a_payment(): void
    {
        Http::fake(['api.cloudinary.com/*' => Http::response(['public_id' => 'incomplete'], 200)]);
        $this->deliveredPaidOrder();
        $this->pay()->assertStatus(500);
        $this->assertSame(0, PlatformSettlement::count());
    }

    public function test_production_missing_configuration_never_records_a_payment(): void
    {
        $this->deliveredPaidOrder();
        $this->app['env'] = 'production';
        config(['services.cloudinary.cloud' => null, 'services.cloudinary.upload_preset' => null]);
        Http::fake();
        try {
            $this->pay()->assertStatus(500);
            $this->assertSame(0, PlatformSettlement::count());
            Http::assertNothingSent();
        } finally {
            $this->app['env'] = 'testing';
        }
    }
    public function test_non_https_upload_response_never_records_a_payment(): void
    {
        Http::fake(['api.cloudinary.com/*' => Http::response(['public_id' => 'receipt', 'secure_url' => 'http://invalid.example/receipt'], 200)]);
        $this->deliveredPaidOrder();
        $this->pay()->assertStatus(500);
        $this->assertSame(0, PlatformSettlement::count());
    }
}
