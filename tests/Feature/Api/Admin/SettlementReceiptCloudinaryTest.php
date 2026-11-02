<?php

namespace Tests\Feature\Api\Admin;

use App\Models\PlatformSettlement;
use App\Models\SubOrder;
use App\Services\Settlement\Receipts\CloudinaryClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

/**
 * مسار إيصالات التحويل على Cloudinary (رفع موقّع + تسليم authenticated).
 *
 * كل شيء يعمل بعميل وهمي — لا شبكة ولا مفاتيح حقيقية. هذا يثبت المنطق والصلاحيات
 * وحفظ المعرّفات، لكنه **لا يثبت** أن حساب Cloudinary الحقيقي يقبل النوع
 * `authenticated` أو ملفات PDF؛ ذلك يحتاج اختبار تكامل بمفاتيح حقيقية.
 */
class SettlementReceiptCloudinaryTest extends TestCase
{
    use BuildsDeliveryOrders;

    private FakeCloudinaryClient $cloud;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDeliveryWorld();
        $this->admin->syncRoles(['admin']);

        $this->cloud = new FakeCloudinaryClient;
        $this->app->instance(CloudinaryClient::class, $this->cloud);

        config([
            'services.cloudinary.cloud' => 'test-cloud',
            'services.cloudinary.receipts.api_key' => 'test-key',
            'services.cloudinary.receipts.api_secret' => 'test-secret',
            'services.cloudinary.receipts.driver' => 'cloudinary',
            'services.cloudinary.receipts.folder' => 'daway/settlement-receipts',
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

    private function pay(string $name = 'receipt.pdf', string $mime = 'application/pdf'): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/admin/platform-settlements/pharmacies/'.$this->pharmacyA->id.'/pay', [
                'receipt' => UploadedFile::fake()->create($name, 40, $mime),
                'transfer_reference' => 'CLD-REF-1',
            ]);
    }

    public function test_a_pdf_is_uploaded_signed_as_an_authenticated_raw_asset(): void
    {
        $this->deliveredPaidOrder();

        $response = $this->pay()->assertOk()->assertJsonPath('data.0.receipt_available', true);

        $this->assertCount(1, $this->cloud->uploads);
        $options = $this->cloud->uploads[0]['options'];

        $this->assertSame('authenticated', $options['type']);
        $this->assertSame('raw', $options['resource_type']);
        $this->assertSame('daway/settlement-receipts', $options['folder']);

        $settlement = PlatformSettlement::findOrFail($response->json('data.0.id'));
        $this->assertSame('cloudinary', $settlement->transfer_receipt_driver);
        $this->assertSame($this->cloud->uploads[0]['public_id'], $settlement->transfer_receipt_path);
        $this->assertSame('raw', $settlement->transfer_receipt_meta['resource_type']);
        $this->assertSame('authenticated', $settlement->transfer_receipt_meta['type']);
        $this->assertSame('pdf', $settlement->transfer_receipt_meta['format']);
    }

    public function test_an_image_is_uploaded_as_an_authenticated_image_asset(): void
    {
        $this->deliveredPaidOrder();

        $this->pay('receipt.png', 'image/png')->assertOk();

        $options = $this->cloud->uploads[0]['options'];
        $this->assertSame('image', $options['resource_type']);
        $this->assertSame('authenticated', $options['type']);
    }

    public function test_no_public_url_is_ever_stored_or_returned(): void
    {
        $this->deliveredPaidOrder();

        $response = $this->pay()->assertOk();

        $settlement = PlatformSettlement::findOrFail($response->json('data.0.id'));
        $this->assertStringNotContainsString('res.cloudinary.com', (string) $settlement->transfer_receipt_path);
        $this->assertStringNotContainsString('http', (string) $settlement->transfer_receipt_path);

        $content = $response->getContent();
        $this->assertStringNotContainsString('res.cloudinary.com', $content);
        $this->assertStringNotContainsString('secure_url', $content);
        $this->assertStringNotContainsString('test-secret', $content);
        $this->assertStringNotContainsString('test-key', $content);
    }

    public function test_a_failed_upload_never_records_a_paid_settlement(): void
    {
        $this->deliveredPaidOrder();
        $this->cloud->failUpload = true;

        $this->pay()->assertStatus(500)->assertJsonPath('success', false);

        $this->assertSame(0, PlatformSettlement::count());
        $this->assertSame(0, $this->cloud->destroyedCount());
    }

    public function test_a_settlement_cannot_be_paid_twice(): void
    {
        $this->deliveredPaidOrder();
        $this->pay()->assertOk();

        $this->pay('again.pdf')->assertStatus(422);

        $this->assertSame(1, PlatformSettlement::where('status', PlatformSettlement::STATUS_PAID)->count());
    }

    public function test_the_owning_pharmacy_can_download_and_the_bytes_are_proxied(): void
    {
        $this->deliveredPaidOrder();
        $settlementId = $this->pay()->assertOk()->json('data.0.id');

        Http::fake(['*' => Http::response('%PDF-1.4 fake receipt bytes', 200)]);

        $this->actingAs($this->pharmacyA->user, 'sanctum');
        $response = $this->get('/api/pharmacy/accounting/platform-settlements/'.$settlementId.'/receipt')->assertOk();

        $this->assertStringContainsString('%PDF-1.4', $response->streamedContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_another_pharmacy_cannot_download_the_receipt(): void
    {
        $this->deliveredPaidOrder();
        $settlementId = $this->pay()->assertOk()->json('data.0.id');

        Http::fake(['*' => Http::response('bytes', 200)]);

        $this->actingAs($this->pharmacyB->user, 'sanctum');
        $this->get('/api/pharmacy/accounting/platform-settlements/'.$settlementId.'/receipt')->assertNotFound();
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->deliveredPaidOrder();
        $settlementId = $this->pay()->assertOk()->json('data.0.id');

        // بدون تسجيل دخول
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/pharmacy/accounting/platform-settlements/'.$settlementId.'/receipt')->assertUnauthorized();
    }

    public function test_a_deleted_asset_reports_404(): void
    {
        $this->deliveredPaidOrder();
        $settlementId = $this->pay()->assertOk()->json('data.0.id');

        // الأصل لم يعد موجودًا في Cloudinary
        $this->cloud->assets = [];

        $this->getJson('/api/admin/platform-settlements/'.$settlementId.'/receipt')->assertNotFound();
    }

    public function test_the_admin_can_download_any_receipt(): void
    {
        $this->deliveredPaidOrder();
        $settlementId = $this->pay()->assertOk()->json('data.0.id');

        Http::fake(['*' => Http::response('bytes', 200)]);

        $this->get('/api/admin/platform-settlements/'.$settlementId.'/receipt')->assertOk();
    }

    public function test_an_unsupported_file_type_is_rejected_before_upload(): void
    {
        $this->deliveredPaidOrder();

        $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/admin/platform-settlements/pharmacies/'.$this->pharmacyA->id.'/pay', [
                'receipt' => UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload'),
            ])->assertStatus(422);

        $this->assertCount(0, $this->cloud->uploads);
        $this->assertSame(0, PlatformSettlement::count());
    }

    public function test_an_oversized_receipt_is_rejected(): void
    {
        $this->deliveredPaidOrder();

        $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/admin/platform-settlements/pharmacies/'.$this->pharmacyA->id.'/pay', [
                'receipt' => UploadedFile::fake()->create('huge.pdf', 6000, 'application/pdf'),
            ])->assertStatus(422);

        $this->assertCount(0, $this->cloud->uploads);
    }

    public function test_public_image_uploads_stay_unsigned_even_when_receipt_keys_exist(): void
    {
        // حارس الفصل: مسار الصور العامة يجب ألا يتأثر بإعدادات الإيصالات.
        config(['services.cloudinary.upload_preset' => 'test-preset']);

        Http::fake([
            'api.cloudinary.com/*' => Http::response([
                'secure_url' => 'https://res.cloudinary.com/test-cloud/image/upload/v1/daway/medicines/x.jpg',
            ], 200),
        ]);

        $url = \App\Support\Cloudinary::upload(UploadedFile::fake()->image('med.jpg'), 'medicines');

        $this->assertSame(
            'https://res.cloudinary.com/test-cloud/image/upload/v1/daway/medicines/x.jpg',
            $url
        );

        Http::assertSent(function ($request): bool {
            $body = (string) $request->body();

            return str_contains($request->url(), '/image/upload')
                && str_contains($body, 'upload_preset')
                && ! str_contains($body, 'signature');
        });
    }

    public function test_a_rejected_or_expired_signed_url_returns_502_without_leaking_the_url(): void
    {
        $this->deliveredPaidOrder();
        $settlementId = $this->pay()->assertOk()->json('data.0.id');

        // Cloudinary يرفض الرابط الموقّع بعد انتهاء صلاحيته (403/401).
        Http::fake(['*' => Http::response('expired signature', 403)]);

        $response = $this->actingAs($this->pharmacyA->user, 'sanctum')
            ->getJson('/api/pharmacy/accounting/platform-settlements/'.$settlementId.'/receipt')
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        // لا يُعاد الرابط الداخلي ولا اسم المضيف في أي حال.
        $this->assertStringNotContainsString('res.cloudinary.com', $response->getContent());
        $this->assertStringNotContainsString('signature', $response->getContent());
    }

    public function test_a_file_whose_content_does_not_match_its_extension_is_rejected(): void
    {
        $this->deliveredPaidOrder();

        // `UploadedFile::fake()` ينسخ الـMIME من الامتداد، فلا يصلح لإثبات أن
        // الفحص يعتمد على المحتوى. هنا ملف حقيقي على القرص: الاسم .pdf والمحتوى
        // نص عادي، والـMIME المعلن من العميل مزوّر كـpdf.
        $path = tempnam(sys_get_temp_dir(), 'daway-rcpt').'.pdf';
        file_put_contents($path, 'this is definitely not a pdf');

        $file = new UploadedFile($path, 'receipt.pdf', 'application/pdf', null, true);

        try {
            $this->withHeaders(['Accept' => 'application/json'])
                ->post('/api/admin/platform-settlements/pharmacies/'.$this->pharmacyA->id.'/pay', [
                    'receipt' => $file,
                ])
                ->assertStatus(422);

            $this->assertCount(0, $this->cloud->uploads);
            $this->assertSame(0, PlatformSettlement::count());
        } finally {
            @unlink($path);
        }
    }

    public function test_orphan_cleanup_keeps_referenced_assets_and_removes_old_orphans(): void
    {
        $this->deliveredPaidOrder();
        $settlementId = $this->pay()->assertOk()->json('data.0.id');
        $referenced = PlatformSettlement::findOrFail($settlementId)->transfer_receipt_path;

        $this->cloud->assets = [
            $referenced => ['resource_type' => 'raw', 'created_at' => now()->subDays(10)->toIso8601String()],
            'daway/settlement-receipts/orphan-old' => ['resource_type' => 'raw', 'created_at' => now()->subDays(10)->toIso8601String()],
            'daway/settlement-receipts/orphan-recent' => ['resource_type' => 'raw', 'created_at' => now()->toIso8601String()],
        ];

        $this->artisan('settlements:prune-orphan-receipts', ['--hours' => 24])->assertSuccessful();

        $this->assertContains('daway/settlement-receipts/orphan-old', $this->cloud->destroyed);
        $this->assertNotContains('daway/settlement-receipts/orphan-recent', $this->cloud->destroyed);
        $this->assertNotContains($referenced, $this->cloud->destroyed);
    }
}

/** عميل Cloudinary وهمي بالكامل — لا شبكة ولا مفاتيح. */
class FakeCloudinaryClient implements CloudinaryClient
{
    /** @var list<array{path: string, options: array<string, mixed>, public_id: string}> */
    public array $uploads = [];

    /** @var list<string> */
    public array $destroyed = [];

    /** @var array<string, array<string, mixed>> */
    public array $assets = [];

    public bool $failUpload = false;

    private int $counter = 0;

    public function configured(): bool
    {
        return true;
    }

    public function upload(string $localPath, array $options): array
    {
        if ($this->failUpload) {
            throw new RuntimeException('synthetic upload failure');
        }

        $this->counter++;
        $publicId = 'daway/settlement-receipts/receipt-'.$this->counter;

        $this->uploads[] = ['path' => $localPath, 'options' => $options, 'public_id' => $publicId];
        $this->assets[$publicId] = ['resource_type' => $options['resource_type'], 'created_at' => now()->toIso8601String()];

        return [
            'public_id' => $publicId,
            'resource_type' => $options['resource_type'],
            'type' => $options['type'],
            'format' => $options['resource_type'] === 'raw' ? 'pdf' : 'png',
            'bytes' => 1234,
        ];
    }

    public function assetExists(string $publicId, array $options): bool
    {
        return array_key_exists($publicId, $this->assets);
    }

    public function signedDownloadUrl(string $publicId, string $format, array $options): string
    {
        return 'https://res.cloudinary.com/test-cloud/'.$publicId.'?signature=synthetic';
    }

    public function destroy(string $publicId, array $options): void
    {
        $this->destroyed[] = $publicId;
        unset($this->assets[$publicId]);
    }

    public function assets(array $options): array
    {
        $resources = [];
        foreach ($this->assets as $publicId => $meta) {
            $resources[] = [
                'public_id' => $publicId,
                'resource_type' => $meta['resource_type'] ?? 'raw',
                'type' => 'authenticated',
                'created_at' => $meta['created_at'] ?? now()->toIso8601String(),
            ];
        }

        return ['resources' => $resources];
    }

    public function destroyedCount(): int
    {
        return count($this->destroyed);
    }
}
