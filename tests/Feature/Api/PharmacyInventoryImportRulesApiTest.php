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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * قواعد محرّك الاستيراد الجماعي — **منقولة من
 * `tests/Feature/Web/PharmacyInventoryImportTest.php`** (57 اختبارًا حُذفت مع Blade).
 *
 * ── لماذا هذا الملف ضروري؟ ────────────────────────────────────────
 * الاختبار المحذوف لم يكن يعرض صفحات فقط: كان يحمي قواعد عمل حقيقية في
 * `InventoryImportService` — وهي **نفس** المحرّك الذي يخدم الـAPI اليوم:
 *   · لا كتابة قبل commit · لا إنشاء دواء إلا بقرار صريح
 *   · القرارات تُعاد التحقق منها على الخادم
 *   · لا تنفيذ مزدوج ولا بعد انتهاء الجلسة
 *   · `min_stock` الغائب لا يمحو قيمة موجودة
 *   · التكرار يُجمَّع والفائز وحده يُكتب
 *
 * `PharmacyInventoryImportApiTest` يغطي المسار السعيد وحدود الحصة؛ هذا الملف
 * يغطي **القواعد** التي كانت في الاختبار المحذوف.
 */
final class PharmacyInventoryImportRulesApiTest extends TestCase
{
    private array $columns = [
        'trade_name', 'trade_name_ar', 'active_ingredient',
        'price', 'quantity', 'min_stock', 'is_available',
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

    /** @param array<int, array<string, mixed>> $decisions */
    private function decide(InventoryImport $import, array $decisions, array $merges = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/pharmacy/inventory/import/'.$import->uuid.'/decide', [
            'rows' => $decisions,
            'merges' => $merges,
        ]);
    }

    private function commit(InventoryImport $import): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/pharmacy/inventory/import/'.$import->uuid.'/commit');
    }

    // ── الجمع والكمية الصفرية ──────────────────────────────────────

    public function test_two_rows_linked_to_the_same_medicine_are_summed(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 3,
        ]);

        $import = $this->preview([
            ['trade_name' => 'ALPHA DRUG', 'price' => 5, 'quantity' => 10],
            ['trade_name' => 'BETA DRUG', 'price' => 5, 'quantity' => 4],
        ]);

        $this->decide($import, [
            ['row' => 2, 'action' => 'link', 'medicine_id' => $medicine->id],
            ['row' => 3, 'action' => 'link', 'medicine_id' => $medicine->id],
        ])->assertOk();

        $this->commit($import)->assertOk();

        $row = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('medicine_id', $medicine->id)->firstOrFail();

        // 3 (موجود) + 10 + 4
        $this->assertSame(17, (int) $row->quantity);
    }

    public function test_a_zero_quantity_row_keeps_existing_stock_available(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 5,
            'is_available' => true,
        ]);

        $import = $this->preview([
            ['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 0],
        ]);

        $this->commit($import)->assertOk();

        $row = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('medicine_id', $medicine->id)->firstOrFail();

        $this->assertSame(5, (int) $row->quantity, 'صف بكمية صفر لا يمحو المخزون القائم');
        $this->assertTrue((bool) $row->is_available);
    }

    public function test_an_explicit_unavailable_value_is_respected_when_adding_stock(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 5,
            'is_available' => true,
        ]);

        $import = $this->preview([
            ['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 10, 'is_available' => '0'],
        ]);

        $this->commit($import)->assertOk();

        $row = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('medicine_id', $medicine->id)->firstOrFail();

        $this->assertSame(15, (int) $row->quantity);
        $this->assertFalse((bool) $row->is_available, 'قيمة is_available الصريحة تُحترم');
    }

    // ── الحماية من التنفيذ المزدوج والانتهاء ───────────────────────

    public function test_a_second_commit_is_rejected_and_does_not_write_twice(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([['trade_name' => 'PANADOL EXTRA', 'price' => 4, 'quantity' => 6]]);

        $this->commit($import)->assertOk();
        $committedRows = (int) $import->fresh()->committed_rows;

        // المحاولة الثانية: لا تنفيذ، والعدّاد لا يتضاعف
        $this->commit($import);

        $this->assertSame(1, PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->count());
        $this->assertSame($committedRows, (int) $import->fresh()->committed_rows);
        $this->assertSame(InventoryImport::STATUS_COMMITTED, $import->fresh()->status);
    }

    public function test_an_expired_session_cannot_be_committed(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([['trade_name' => 'PANADOL EXTRA', 'price' => 4, 'quantity' => 6]]);
        $import->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->commit($import)->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame(0, PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->count());
        $this->assertSame(InventoryImport::STATUS_PREVIEWED, $import->fresh()->status);
    }

    // ── التحقق من القرارات على الخادم ─────────────────────────────

    public function test_a_link_decision_pointing_to_an_unknown_medicine_is_rejected(): void
    {
        $this->pharmacyUser();
        $import = $this->preview([['trade_name' => 'ZZZ NO SUCH MEDICINE', 'price' => 1, 'quantity' => 2]]);

        $this->decide($import, [
            ['row' => 2, 'action' => 'link', 'medicine_id' => 999999],
        ])->assertStatus(422);
    }

    public function test_a_moh_id_outside_the_row_suggestions_is_rejected(): void
    {
        $this->pharmacyUser();

        $otherMoh = MohMedicine::create([
            'trade_name' => 'UNRELATED',
            'generic_name' => 'unrelated',
            'moh_product_id' => 55555,
        ]);

        $import = $this->preview([['trade_name' => 'ZZZ NO SUCH MEDICINE', 'price' => 1, 'quantity' => 2]]);

        $this->decide($import, [
            ['row' => 2, 'action' => 'create', 'moh_id' => $otherMoh->id],
        ])->assertStatus(422);
    }

    public function test_an_invalid_action_is_rejected(): void
    {
        $this->pharmacyUser();
        $import = $this->preview([['trade_name' => 'ZZZ NO SUCH MEDICINE', 'price' => 1, 'quantity' => 2]]);

        $this->decide($import, [
            ['row' => 2, 'action' => 'delete-everything'],
        ])->assertStatus(422);
    }

    // ── min_stock: لا كسر صامت ────────────────────────────────────

    public function test_a_missing_min_stock_does_not_wipe_the_existing_value(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 1,
            'min_stock' => 7,
        ]);

        // الملف بلا قيمة min_stock لهذا الصف
        $import = $this->preview([['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 30]]);
        $this->commit($import)->assertOk();

        $row = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('medicine_id', $medicine->id)->firstOrFail();

        $this->assertSame(31, (int) $row->quantity);
        $this->assertSame(7, (int) $row->min_stock, 'min_stock الغائب لا يمحو القيمة القائمة');
    }

    public function test_a_present_min_stock_updates_the_value(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 1,
            'min_stock' => 7,
        ]);

        $import = $this->preview([
            ['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 30, 'min_stock' => 12],
        ]);
        $this->commit($import)->assertOk();

        $row = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('medicine_id', $medicine->id)->firstOrFail();

        $this->assertSame(12, (int) $row->min_stock);
    }

    // ── لا إنشاء تلقائي: القرار البشري وحده ───────────────────────

    public function test_a_fuzzy_match_alone_never_creates_a_medicine(): void
    {
        $this->pharmacyUser();

        MohMedicine::create([
            'trade_name' => 'ZANTAC 150MG',
            'generic_name' => 'ranitidine',
            'moh_product_id' => 90001,
        ]);

        $import = $this->preview([['trade_name' => 'Zantac', 'price' => 3, 'quantity' => 5]]);

        $this->assertContains($import->rows()[0]['status'], [
            InventoryImport::ROW_FUZZY,
            InventoryImport::ROW_REVIEW,
        ]);
        $this->assertNotEmpty($import->rows()[0]['suggestions']);
        $this->assertSame(0, Medicine::count());

        $this->commit($import);
        $this->assertSame(0, Medicine::count(), 'المطابقة التقريبية اقتراح لا إنشاء');
    }

    public function test_a_moh_only_row_needs_an_explicit_decision(): void
    {
        $this->pharmacyUser();

        MohMedicine::create([
            'trade_name' => 'SOMEMOHDRUG',
            'generic_name' => 'somegeneric',
            'moh_product_id' => 91000,
        ]);

        $import = $this->preview([['trade_name' => 'SOMEMOHDRUG', 'price' => 3, 'quantity' => 5]]);

        $this->assertSame(InventoryImport::ROW_MOH, $import->rows()[0]['status']);
        $this->assertSame(InventoryImport::DECISION_PENDING, $import->rows()[0]['decision']);
        $this->assertSame(0, Medicine::count());
    }

    public function test_an_ambiguous_fuzzy_match_requires_review(): void
    {
        $this->pharmacyUser();

        MohMedicine::create(['trade_name' => 'AMBIGUOUS DRUG A', 'generic_name' => 'x', 'moh_product_id' => 90003]);
        MohMedicine::create(['trade_name' => 'AMBIGUOUS DRUG B', 'generic_name' => 'y', 'moh_product_id' => 90004]);

        $import = $this->preview([['trade_name' => 'Ambiguous', 'price' => 3, 'quantity' => 5]]);

        $this->assertSame(InventoryImport::ROW_REVIEW, $import->rows()[0]['status']);
        $this->assertGreaterThanOrEqual(2, count($import->rows()[0]['suggestions']));
    }

    // ── التكرار: تجميع وفائز واحد ────────────────────────────────

    public function test_duplicate_rows_are_grouped_and_keep_last_wins(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([
            ['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 10],
            ['trade_name' => 'Panadol extra', 'price' => 6, 'quantity' => 99],
        ]);

        $this->assertSame(2, (int) $import->duplicate_rows);
        $this->assertCount(1, $import->duplicateGroups());

        // قرار الدمج + قرار الفائز مطلوبان معًا
        $this->decide($import, [
            ['row' => 3, 'action' => 'link', 'medicine_id' => $medicine->id],
        ], [
            'm:'.$medicine->id => InventoryImport::MERGE_KEEP_LAST,
        ])->assertOk()->assertJsonPath('data.decisions.ready', true);

        $this->commit($import)->assertOk();

        $row = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('medicine_id', $medicine->id)->firstOrFail();

        $this->assertSame(99, (int) $row->quantity);
        $this->assertSame(1, PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->count());
    }

    public function test_keep_first_wins_with_the_first_row(): void
    {
        [, $pharmacy] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([
            ['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 10],
            ['trade_name' => 'Panadol extra', 'price' => 6, 'quantity' => 99],
        ]);

        $this->decide($import, [
            ['row' => 2, 'action' => 'link', 'medicine_id' => $medicine->id],
        ], [
            'm:'.$medicine->id => InventoryImport::MERGE_KEEP_FIRST,
        ])->assertOk()->assertJsonPath('data.decisions.ready', true);

        $this->commit($import)->assertOk();

        $row = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('medicine_id', $medicine->id)->firstOrFail();

        $this->assertSame(10, (int) $row->quantity);
    }
}
