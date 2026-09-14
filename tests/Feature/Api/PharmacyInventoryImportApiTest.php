<?php

namespace Tests\Feature\Api;

use App\Contracts\FcmSender;
use App\Models\InventoryImport;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Notification;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use App\Services\Ai\MedicineResolver;
use App\Support\InventoryImportThrottle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * الاستيراد الجماعي عبر الـ API — مسار الموبايل (Flutter).
 *
 * المهم هنا: نفس محرّك الويب، فلا اختلاف في القواعد بين القناتين.
 * والاختبارات تثبّت أن نقاط الاستيراد الجديدة لا تمسّ شكل أي نقطة قائمة.
 */
class PharmacyInventoryImportApiTest extends TestCase
{
    private array $columns = [
        'trade_name',
        'trade_name_ar',
        'active_ingredient',
        'price',
        'quantity',
        'min_stock',
        'is_available',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->app->instance(
            MedicineResolver::class,
            (new MedicineResolver)->setMappingPath(base_path('tests/fixtures/inventory_import_mapping.json'))
        );

        $this->app->instance(FcmSender::class, new class implements FcmSender
        {
            public function enabled(): bool
            {
                return false;
            }

            public function sendToUser(User $user, string $title, string $body, array $data = []): void {}

            public function fromNotification(Notification $notification): void {}
        });
    }

    /** @return array{0: User, 1: Pharmacy} */
    private function pharmacyUser(): array
    {
        $user = User::factory()->pharmacy()->create();
        Sanctum::actingAs($user);

        $pharmacy = Pharmacy::factory()->create([
            'user_id' => $user->id,
            'profile_completed_at' => now(),
        ]);

        return [$user, $pharmacy];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function csvFrom(array $rows): string
    {
        $out = implode(',', $this->columns)."\n";

        foreach ($rows as $row) {
            $line = [];

            foreach ($this->columns as $column) {
                $line[] = $row[$column] ?? '';
            }

            $out .= implode(',', $line)."\n";
        }

        return $out;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function preview(array $rows): InventoryImport
    {
        $this->post('/api/pharmacy/inventory/import', [
            'file' => UploadedFile::fake()->createWithContent('inventory.csv', $this->csvFrom($rows)),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('success', true);

        return InventoryImport::latest('id')->firstOrFail();
    }

    public function test_the_empty_template_downloads_over_the_api(): void
    {
        $this->pharmacyUser();

        $this->get('/api/pharmacy/inventory/import/template?mode=empty', ['Accept' => 'application/json'])
            ->assertOk();
    }

    public function test_preview_returns_the_summary_without_writing(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 9]]);

        $this->assertSame(InventoryImport::STATUS_PREVIEWED, $import->status);
        $this->assertSame(0, PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->count());
        $this->assertSame(1, Medicine::count());
    }

    public function test_show_returns_the_session_rows(): void
    {
        $this->pharmacyUser();
        Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 9]]);

        $this->getJson('/api/pharmacy/inventory/import/'.$import->uuid)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', InventoryImport::STATUS_PREVIEWED)
            ->assertJsonCount(1, 'data.rows');
    }

    public function test_decide_and_commit_write_the_inventory(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 9]]);

        $this->postJson('/api/pharmacy/inventory/import/'.$import->uuid.'/decide', [
            'rows' => [['row' => 2, 'action' => 'link', 'medicine_id' => $medicine->id]],
        ])->assertOk()->assertJsonPath('data.decisions.ready', true);

        $this->postJson('/api/pharmacy/inventory/import/'.$import->uuid.'/commit')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.import.status', InventoryImport::STATUS_COMMITTED);

        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 9,
        ]);
    }

    public function test_commit_through_the_api_rejects_a_pending_session(): void
    {
        [, $pharmacy] = $this->pharmacyUser();

        $import = $this->preview([['trade_name' => 'ZZZ NO SUCH MEDICINE', 'price' => 5, 'quantity' => 9]]);

        $this->postJson('/api/pharmacy/inventory/import/'.$import->uuid.'/commit')
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->count());
    }

    public function test_a_create_decision_from_an_moh_suggestion_works_over_the_api(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();

        $moh = MohMedicine::create([
            'trade_name' => 'ZANTAC 150MG',
            'generic_name' => 'ranitidine',
            'moh_product_id' => 90001,
        ]);

        $import = $this->preview([['trade_name' => 'Zantac', 'price' => 3, 'quantity' => 5]]);

        $this->postJson('/api/pharmacy/inventory/import/'.$import->uuid.'/decide', [
            'rows' => [['row' => 2, 'action' => 'create', 'moh_id' => $moh->id]],
        ])->assertOk();

        $this->postJson('/api/pharmacy/inventory/import/'.$import->uuid.'/commit')->assertOk();

        $medicine = Medicine::where('trade_name', 'ZANTAC 150MG')->firstOrFail();
        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
        ]);
    }

    public function test_another_pharmacy_cannot_read_someone_elses_session_over_the_api(): void
    {
        $this->pharmacyUser();
        Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);
        $import = $this->preview([['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 9]]);

        // صيدلية أخرى
        $other = User::factory()->pharmacy()->create();
        Sanctum::actingAs($other);
        Pharmacy::factory()->create(['user_id' => $other->id, 'profile_completed_at' => now()]);

        $this->getJson('/api/pharmacy/inventory/import/'.$import->uuid)->assertNotFound();
        $this->postJson('/api/pharmacy/inventory/import/'.$import->uuid.'/commit')->assertNotFound();
    }

    public function test_the_cancel_endpoint_marks_the_session_expired(): void
    {
        $this->pharmacyUser();
        Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);
        $import = $this->preview([['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 9]]);

        $this->postJson('/api/pharmacy/inventory/import/'.$import->uuid.'/cancel')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(InventoryImport::STATUS_EXPIRED, $import->fresh()->status);
    }

    public function test_a_patient_token_is_rejected(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $this->getJson('/api/pharmacy/inventory/import/template')->assertForbidden();
    }

    public function test_the_existing_inventory_endpoints_are_unchanged(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 3,
        ]);

        // الشكل القديم كما هو — لم نغيّر أي عقد قائم
        $this->getJson('/api/pharmacy/inventory')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data', 'pagination' => ['total', 'per_page', 'current_page', 'last_page']]);
    }

    /* =====================================================================
     | حد المعدل (HTTP 429) عبر الـ API
     |
     | العقد مع Flutter: 429 دائماً JSON فيه `retry_after` بالثواني، حتى
     | يعرف التطبيق كم ينتظر بدل أن يعرض «فشل غير معروف».
     ===================================================================== */

    public function test_the_import_limit_returns_json_with_retry_after_over_the_api(): void
    {
        config(['inventory_import.rate_limit_per_hour' => 1]);

        // التطبيق يطلب العربية عبر Accept-Language كما في الإنتاج
        $this->withHeaders(['Accept-Language' => 'ar']);

        [$user] = $this->pharmacyUser();

        $rows = [['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 3]];

        // الأول ينجح ويستهلك الحصة
        $this->post('/api/pharmacy/inventory/import', [
            'file' => UploadedFile::fake()->createWithContent('inventory.csv', $this->csvFrom($rows)),
        ], ['Accept' => 'application/json'])->assertOk();

        $blocked = $this->post('/api/pharmacy/inventory/import', [
            'file' => UploadedFile::fake()->createWithContent('inventory.csv', $this->csvFrom($rows)),
        ], ['Accept' => 'application/json']);

        $blocked->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('retry_after', fn ($value) => (int) $value > 0)
            ->assertJsonStructure(['success', 'message', 'retry_after']);

        $this->assertStringContainsString('تجاوزت الحد المسموح', (string) $blocked->json('message'));

        // الترويسات موجودة لمن يقرأ الترويسات بدل الجسم
        $blocked->assertHeader('Retry-After');

        $this->assertSame(0, InventoryImportThrottle::remaining($user->fresh()));
    }

    public function test_api_reads_do_not_consume_the_import_quota(): void
    {
        config(['inventory_import.rate_limit_per_hour' => 2]);

        [$user] = $this->pharmacyUser();
        Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 3]]);

        // قراءات متكررة: قالب + جلسة + تقرير أخطاء — 8 دورات
        for ($i = 0; $i < 8; $i++) {
            $this->getJson('/api/pharmacy/inventory/import/template')->assertOk();
            $this->getJson('/api/pharmacy/inventory/import/'.$import->uuid)->assertOk();
            $this->getJson('/api/pharmacy/inventory/import/'.$import->uuid.'/errors')->assertOk();
        }

        // الرفع الواحد فقط هو ما استُهلك من الحصة
        $this->assertSame(1, InventoryImportThrottle::used($user->fresh()));
    }

    public function test_exhausting_the_import_quota_leaves_the_writes_limiter_untouched(): void
    {
        config(['inventory_import.rate_limit_per_hour' => 1]);

        [$user, $pharmacy] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $stock = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 3,
        ]);

        $rows = [['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 4]];

        $this->preview($rows);

        // الحصة استُهلكت
        $this->post('/api/pharmacy/inventory/import', [
            'file' => UploadedFile::fake()->createWithContent('inventory.csv', $this->csvFrom($rows)),
        ], ['Accept' => 'application/json'])->assertStatus(429);

        // ونقطة كتابة قديمة (throttle:writes) تعمل عادي — العزل سلوكي لا نظري
        $this->putJson('/api/pharmacy/inventory/'.$stock->id, ['quantity' => 11])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(11, (int) $stock->fresh()->quantity);

        // 'writes' استُهلك مرة واحدة فقط — الكتابة أعلاه. كل حركة الاستيراد
        // (الرفع الناجح + المحجوب) لم تلمس دلو 'writes' إطلاقاً.
        $this->assertSame(1, RateLimiter::attempts(md5('writes'.$user->id)));
    }
}
