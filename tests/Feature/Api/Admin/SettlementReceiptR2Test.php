<?php

namespace Tests\Feature\Api\Admin;

use App\Models\PlatformSettlement;
use App\Models\SubOrder;
use App\Services\Settlement\Receipts\DiskReceipts;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

/**
 * تخزين إشعارات التحويل على Cloudflare R2 (S3-compatible).
 *
 * الاختبارات تستخدم `Storage::fake('r2')` فلا تحتاج مفاتيح حقيقية ولا شبكة —
 * التبديل بين الأقراص يحدث بمتغير بيئة واحد، وهذا ما نتحقق منه هنا.
 */
class SettlementReceiptR2Test extends TestCase
{
    use BuildsDeliveryOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryWorld();
        $this->admin->syncRoles(['admin']);
    }

    private function deliveredPaidOrder(): void
    {
        $order = $this->checkoutTwoPharmacy();
        $this->markOrderPaid($order);
        $this->subOrderFor($order, $this->pharmacyA)
            ->update(['status' => SubOrder::STATUS_DELIVERED, 'delivered_at' => '2026-10-29 10:00:00']);
        $this->actingAs($this->admin, 'sanctum');
    }

    private function pay(string $name = 'r2-proof.pdf'): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/admin/platform-settlements/pharmacies/'.$this->pharmacyA->id.'/pay', [
                'receipt' => UploadedFile::fake()->create($name, 40, 'image/png'),
                'transfer_reference' => 'R2-REF-1',
            ]);
    }

    public function test_the_s3_driver_is_installed_so_r2_can_be_enabled_by_env_only(): void
    {
        // الحارس: بدون league/flysystem-aws-s3-v3 يفشل Storage::disk('r2') وقت
        // التشغيل فقط — أي بعد النشر وبعد أول تحويل مالي.
        //
        // ملاحظة: لا نُنشئ عميل S3 هنا. بناء العميل يُحمّل AWS SDK + aws-crt
        // ويُسقط عملية PHP على هذه البيئة عند التشغيل داخل الحزمة الكاملة
        // (Premature end of PHP process) — وهو نفس نمط الانهيار المعروف في
        // CategorySyncApiTest. وجود الكلاس + صحة إعداد القرص يكفي كحارس.
        $this->assertTrue(
            class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class),
            'composer require league/flysystem-aws-s3-v3 is required to use the R2 disk'
        );
        $this->assertTrue(class_exists(\Aws\S3\S3Client::class));

        $disk = config('filesystems.disks.r2');

        $this->assertSame('s3', $disk['driver']);
        $this->assertSame('private', $disk['visibility']);
        $this->assertTrue($disk['throw']);
        $this->assertArrayNotHasKey('url', $disk);
    }

    public function test_the_default_receipt_disk_stays_local(): void
    {
        $this->assertSame('local', app(DiskReceipts::class)->diskName());
    }

    public function test_the_r2_disk_is_configured_private_and_s3_compatible(): void
    {
        $disk = config('filesystems.disks.r2');

        $this->assertSame('s3', $disk['driver']);
        $this->assertSame('private', $disk['visibility']);
        $this->assertTrue($disk['throw'], 'throw must be true so a failed write raises instead of returning false');
        $this->assertSame('auto', $disk['region']);
        $this->assertArrayNotHasKey('url', $disk, 'R2 must not expose a public URL');
    }

    public function test_switching_the_env_disk_to_r2_stores_the_receipt_there(): void
    {
        Storage::fake('local');
        Storage::fake('r2');
        config(['filesystems.settlement_receipt_disk' => 'r2']);
        $this->deliveredPaidOrder();

        $response = $this->pay()->assertOk()->assertJsonPath('data.0.receipt_available', true);

        $settlement = PlatformSettlement::findOrFail($response->json('data.0.id'));
        $this->assertNotEmpty($settlement->transfer_receipt_path);
        Storage::disk('r2')->assertExists($settlement->transfer_receipt_path);
        Storage::disk('local')->assertMissing($settlement->transfer_receipt_path);
    }

    public function test_a_public_disk_is_refused_before_anything_is_recorded(): void
    {
        config(['filesystems.settlement_receipt_disk' => 'public']);
        $this->deliveredPaidOrder();

        $this->pay()->assertStatus(500)->assertJsonPath('success', false);

        $this->assertSame(0, PlatformSettlement::count());
    }

    public function test_an_unconfigured_disk_is_refused_before_anything_is_recorded(): void
    {
        config(['filesystems.settlement_receipt_disk' => 'no-such-disk']);
        $this->deliveredPaidOrder();

        $this->pay()->assertStatus(500)->assertJsonPath('success', false);

        $this->assertSame(0, PlatformSettlement::count());
    }

    public function test_download_works_from_the_configured_disk_and_is_scoped_to_the_owner(): void
    {
        Storage::fake('r2');
        config(['filesystems.settlement_receipt_disk' => 'r2']);
        $this->deliveredPaidOrder();
        $settlementId = $this->pay()->assertOk()->json('data.0.id');

        $this->get('/api/admin/platform-settlements/'.$settlementId.'/receipt')->assertOk();

        $this->actingAs($this->pharmacyA->user, 'sanctum');
        $this->get('/api/pharmacy/accounting/platform-settlements/'.$settlementId.'/receipt')->assertOk();

        $this->actingAs($this->pharmacyB->user, 'sanctum');
        $this->get('/api/pharmacy/accounting/platform-settlements/'.$settlementId.'/receipt')->assertNotFound();
    }

    public function test_a_missing_object_reports_404_instead_of_leaking_a_url(): void
    {
        Storage::fake('r2');
        config(['filesystems.settlement_receipt_disk' => 'r2']);
        $this->deliveredPaidOrder();
        $settlementId = $this->pay()->assertOk()->json('data.0.id');

        $settlement = PlatformSettlement::findOrFail($settlementId);
        Storage::disk('r2')->delete($settlement->transfer_receipt_path);

        $this->getJson('/api/admin/platform-settlements/'.$settlementId.'/receipt')
            ->assertNotFound()
            ->assertJsonMissingPath('url');
    }

    public function test_orphan_receipts_are_pruned_but_referenced_and_recent_files_survive(): void
    {
        Storage::fake('local');
        $this->deliveredPaidOrder();
        $settlementId = $this->pay()->assertOk()->json('data.0.id');

        $disk = Storage::disk('local');
        $root = storage_path('framework/testing/disks/local');

        $referenced = PlatformSettlement::findOrFail($settlementId)->transfer_receipt_path;
        $this->assertTrue($disk->exists($referenced));

        // ملف يتيم قديم (فشلت معاملته قبل أكثر من ساعتين).
        $oldOrphan = DiskReceipts::DIRECTORY.'/orphan-old.pdf';
        $disk->put($oldOrphan, 'x');
        touch($root.'/'.$oldOrphan, time() - 7200);

        // ملف يتيم حديث — قد يكون رفعًا جاريًا، يجب ألا يُحذف.
        $recentOrphan = DiskReceipts::DIRECTORY.'/orphan-recent.pdf';
        $disk->put($recentOrphan, 'x');

        $this->artisan('settlements:prune-orphan-receipts', ['--hours' => 1])->assertSuccessful();

        $disk->assertMissing($oldOrphan);
        $disk->assertExists($recentOrphan);
        $disk->assertExists($referenced);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $root = storage_path('framework/testing/disks/local');

        $orphan = DiskReceipts::DIRECTORY.'/orphan-dry.pdf';
        $disk->put($orphan, 'x');
        touch($root.'/'.$orphan, time() - 7200);

        $this->artisan('settlements:prune-orphan-receipts', ['--hours' => 1, '--dry-run' => true])->assertSuccessful();

        $disk->assertExists($orphan);
    }
}
