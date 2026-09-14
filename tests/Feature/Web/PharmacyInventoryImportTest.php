<?php

namespace Tests\Feature\Web;

use App\Contracts\FcmSender;
use App\Models\InventoryImport;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Notification;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\PharmacyMedicineAlias;
use App\Models\User;
use App\Services\Ai\MedicineResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * الاستيراد الجماعي للمخزون — الاختبارات من طرف إلى طرف عبر الواجهة.
 *
 * نثبّت هنا القواعد التي لا يجوز كسرها بصمت:
 *  - لا كتابة على المخزون قبل الـ commit.
 *  - لا إنشاء دواء إلا بقرار صريح.
 *  - القرارات تُعاد التحقق منها على الخادم، ولا يُصدَّق شيء من الواجهة.
 *  - لا تنفيذ مزدوج، ولا تنفيذ بعد انتهاء الجلسة، ولا وصول عابر بين الصيدليات.
 *  - min_stock الغائب لا يمحو قيمة موجودة.
 */
class PharmacyInventoryImportTest extends TestCase
{
    /** @var array<int, string> */
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

        // ملفات الـ Vite تُبنى وقت النشر (Dockerfile: npm run build) — لا داعي
        // لها في الاختبارات، وطلبها يجعلها تعتمد على manifest محلي قديم.
        $this->withoutVite();

        // ملف mapping صغير للاختبار: نمنع مسح ملف 13MB في كل مسار fuzzy
        $this->app->instance(
            MedicineResolver::class,
            (new MedicineResolver)->setMappingPath(base_path('tests/fixtures/inventory_import_mapping.json'))
        );

        // لا اتصال بـ Firebase أثناء الاختبارات
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

    /* =====================================================================
     | مساعدات
     ===================================================================== */

    /** @return array{0: User, 1: Pharmacy} */
    private function pharmacyUser(): array
    {
        $user = User::factory()->pharmacy()->create();
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
    private function preview(array $rows, User $user, string $filename = 'inventory.csv'): InventoryImport
    {
        $this->actingAs($user)
            ->post(route('pharmacy.inventory.import.preview'), [
                'file' => UploadedFile::fake()->createWithContent($filename, $this->csvFrom($rows)),
            ])
            ->assertRedirect();

        return InventoryImport::latest('id')->firstOrFail();
    }

    /** @param array<int, array<string, mixed>> $decisions */
    private function decide(User $user, InventoryImport $import, array $decisions, array $merges = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->postJson(
            route('pharmacy.inventory.import.decide', ['import' => $import->uuid]),
            ['rows' => $decisions, 'merges' => $merges]
        );
    }

    private function commit(User $user, InventoryImport $import): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->post(
            route('pharmacy.inventory.import.commit', ['import' => $import->uuid])
        );
    }

    /* =====================================================================
     | الوصول والصلاحيات
     ===================================================================== */

    public function test_the_import_page_loads_for_a_pharmacy(): void
    {
        [$user] = $this->pharmacyUser();

        $this->actingAs($user)
            ->get(route('pharmacy.inventory.import.index'))
            ->assertOk()
            ->assertSee(__('pharmacy_import.title'));
    }

    public function test_a_patient_cannot_open_the_import_page(): void
    {
        $patient = User::factory()->patient()->create();

        $this->actingAs($patient)
            ->get(route('pharmacy.inventory.import.index'))
            ->assertRedirect(route('login.show'));
    }

    public function test_another_pharmacy_cannot_open_someone_elses_session(): void
    {
        [$userA] = $this->pharmacyUser();
        [$userB] = $this->pharmacyUser();

        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);
        $import = $this->preview([['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 2]], $userA);

        $this->actingAs($userB)
            ->get(route('pharmacy.inventory.import.show', ['import' => $import->uuid]))
            ->assertNotFound();

        // ولا يستطيع تنفيذها
        $this->commit($userB, $import)->assertNotFound();
    }

    /* =====================================================================
     | المعاينة لا تكتب شيئاً
     ===================================================================== */

    public function test_preview_never_writes_to_inventory_or_catalog(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $this->preview([
            ['trade_name' => 'PANADOL EXTRA', 'price' => 9.5, 'quantity' => 12],
            ['trade_name' => 'TOTALLY UNKNOWN DRUG', 'price' => 3, 'quantity' => 4],
        ], $user);

        $this->assertSame(0, PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->count());
        $this->assertSame(1, Medicine::count());
        $this->assertSame(0, PharmacyMedicineAlias::count());
    }

    public function test_preview_records_a_server_side_payload(): void
    {
        [$user] = $this->pharmacyUser();
        Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([['trade_name' => 'PANADOL EXTRA', 'price' => 9.5, 'quantity' => 12]], $user);

        $this->assertSame(InventoryImport::STATUS_PREVIEWED, $import->status);
        $this->assertSame(1, (int) $import->total_rows);
        $this->assertSame(1, (int) $import->matched_rows);
        $this->assertCount(1, $import->rows());
        $this->assertSame(12, $import->rows()[0]['input']['quantity']);
    }

    /* =====================================================================
     | التنفيذ
     ===================================================================== */

    public function test_commit_writes_the_absolute_quantity_not_a_sum(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 3,
            'price' => 1,
        ]);

        $import = $this->preview([['trade_name' => 'PANADOL EXTRA', 'price' => 9.5, 'quantity' => 10]], $user);

        $this->commit($user, $import)->assertRedirect();

        $row = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)
            ->where('medicine_id', $medicine->id)
            ->firstOrFail();

        // الكمية قيمة مطلقة: 10 وليست 3+10
        $this->assertSame(10, (int) $row->quantity);
        $this->assertSame('9.50', $row->price);
        $this->assertTrue((bool) $row->is_available);
        $this->assertSame(1, PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->count());
    }

    public function test_commit_creates_a_new_inventory_row_when_none_exists(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([['trade_name' => 'PANADOL EXTRA', 'price' => 4, 'quantity' => 6]], $user);
        $this->commit($user, $import)->assertRedirect();

        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 6,
        ]);
    }

    public function test_a_second_commit_is_rejected_and_does_not_write_twice(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([['trade_name' => 'PANADOL EXTRA', 'price' => 4, 'quantity' => 6]], $user);

        $this->commit($user, $import)->assertRedirect();

        $committedRows = (int) $import->fresh()->committed_rows;

        // المحاولة الثانية: لا تُنفَّذ، والعدّاد لا يتضاعف
        $this->commit($user, $import)->assertRedirect();

        $this->assertSame(1, PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->count());
        $this->assertSame($committedRows, (int) $import->fresh()->committed_rows);
        $this->assertSame(InventoryImport::STATUS_COMMITTED, $import->fresh()->status);
    }

    public function test_an_expired_session_cannot_be_committed(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([['trade_name' => 'PANADOL EXTRA', 'price' => 4, 'quantity' => 6]], $user);

        $import->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->commit($user, $import)->assertRedirect();

        $this->assertSame(0, PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->count());
        $this->assertSame(InventoryImport::STATUS_PREVIEWED, $import->fresh()->status);
    }

    public function test_commit_is_blocked_while_rows_are_still_pending(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();

        // اسم غير معروف تماماً ⇒ بانتظار قرار
        $import = $this->preview([['trade_name' => 'ZZZ NO SUCH MEDICINE', 'price' => 1, 'quantity' => 2]], $user);

        $this->assertSame(InventoryImport::STATUS_PREVIEWED, $import->fresh()->status);

        $this->commit($user, $import)->assertRedirect();

        $this->assertSame(0, PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->count());
        $this->assertSame(InventoryImport::STATUS_PREVIEWED, $import->fresh()->status);
    }

    /* =====================================================================
     | التحقق من القرارات على الخادم
     ===================================================================== */

    public function test_a_link_decision_pointing_to_an_unknown_medicine_is_rejected(): void
    {
        [$user] = $this->pharmacyUser();

        $import = $this->preview([['trade_name' => 'ZZZ NO SUCH MEDICINE', 'price' => 1, 'quantity' => 2]], $user);

        $this->decide($user, $import, [
            ['row' => 2, 'action' => 'link', 'medicine_id' => 999999],
        ])->assertStatus(422);

        $this->assertSame(
            InventoryImport::DECISION_PENDING,
            $import->fresh()->rows()[0]['decision']
        );
    }

    public function test_a_moh_id_that_is_not_one_of_the_row_suggestions_is_rejected(): void
    {
        [$user] = $this->pharmacyUser();

        $otherMoh = MohMedicine::create([
            'trade_name' => 'UNRELATED',
            'generic_name' => 'unrelated',
            'moh_product_id' => 55555,
        ]);

        $import = $this->preview([['trade_name' => 'ZZZ NO SUCH MEDICINE', 'price' => 1, 'quantity' => 2]], $user);

        $this->decide($user, $import, [
            ['row' => 2, 'action' => 'create', 'moh_id' => $otherMoh->id],
        ])->assertStatus(422);
    }

    public function test_an_invalid_action_is_rejected(): void
    {
        [$user] = $this->pharmacyUser();
        $import = $this->preview([['trade_name' => 'ZZZ NO SUCH MEDICINE', 'price' => 1, 'quantity' => 2]], $user);

        $this->decide($user, $import, [
            ['row' => 2, 'action' => 'delete-everything'],
        ])->assertStatus(422);
    }

    public function test_decisions_mark_the_session_ready_when_nothing_is_pending(): void
    {
        [$user] = $this->pharmacyUser();
        Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([
            ['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 1],
            ['trade_name' => 'ZZZ NO SUCH MEDICINE', 'price' => 1, 'quantity' => 2],
        ], $user);

        $this->decide($user, $import, [
            ['row' => 3, 'action' => 'skip'],
        ])->assertOk()->assertJson(['success' => true, 'ready' => true]);

        $this->assertSame(InventoryImport::STATUS_READY, $import->fresh()->status);
    }

    /* =====================================================================
     | min_stock — لا كسر صامت
     ===================================================================== */

    public function test_a_missing_min_stock_does_not_wipe_the_existing_value(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 1,
            'min_stock' => 7,
        ]);

        // الملف بلا عمود min_stock أصلاً
        $import = $this->preview([['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 30]], $user);
        $this->commit($user, $import)->assertRedirect();

        $row = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->where('medicine_id', $medicine->id)->firstOrFail();

        $this->assertSame(30, (int) $row->quantity);
        $this->assertSame(7, (int) $row->min_stock);
    }

    public function test_a_present_min_stock_updates_the_value(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 1,
            'min_stock' => 7,
        ]);

        $import = $this->preview([
            ['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 30, 'min_stock' => 4],
        ], $user);

        $this->commit($user, $import)->assertRedirect();

        $row = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->where('medicine_id', $medicine->id)->firstOrFail();

        $this->assertSame(4, (int) $row->min_stock);
    }

    /* =====================================================================
     | منع تلويث الكتالوج
     ===================================================================== */

    public function test_an_unmatched_row_is_not_turned_into_a_new_medicine_automatically(): void
    {
        [$user] = $this->pharmacyUser();

        $import = $this->preview([['trade_name' => 'ZZZ NO SUCH MEDICINE', 'price' => 1, 'quantity' => 2]], $user);

        $this->decide($user, $import, [['row' => 2, 'action' => 'skip']])->assertOk();
        $this->commit($user, $import)->assertRedirect();

        $this->assertSame(0, Medicine::count());
    }

    public function test_an_explicit_create_decision_creates_the_medicine(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();

        $import = $this->preview([
            [
                'trade_name' => 'BRAND NEW DRUG',
                'trade_name_ar' => 'دواء جديد',
                'active_ingredient' => 'newine',
                'price' => 12,
                'quantity' => 8,
            ],
        ], $user);

        $this->decide($user, $import, [['row' => 2, 'action' => 'create']])->assertOk();
        $this->commit($user, $import)->assertRedirect();

        $medicine = Medicine::where('trade_name', 'BRAND NEW DRUG')->firstOrFail();

        $this->assertSame('newine', $medicine->active_ingredient);
        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 8,
        ]);
        $this->assertSame(1, (int) $import->fresh()->created_medicines);
    }

    public function test_a_create_decision_without_an_active_ingredient_is_rejected(): void
    {
        [$user] = $this->pharmacyUser();

        $import = $this->preview([['trade_name' => 'NO INGREDIENT DRUG', 'price' => 1, 'quantity' => 2]], $user);

        $this->decide($user, $import, [['row' => 2, 'action' => 'create']])->assertStatus(422);

        $this->assertSame(0, Medicine::count());
    }

    public function test_creating_a_medicine_that_already_exists_reuses_it(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $existing = Medicine::factory()->create([
            'trade_name' => 'BRAND NEW DRUG',
            'active_ingredient' => 'newine',
        ]);

        // الصف غير مطابق لأن الاسم العربي لا يطابق شيئاً، لكن الاسم الإنجليزي موجود فعلاً
        $import = $this->preview([
            [
                'trade_name' => 'BRAND NEW DRUG',
                'trade_name_ar' => 'اسم عربي مختلف تماما',
                'active_ingredient' => 'newine',
                'price' => 12,
                'quantity' => 8,
            ],
        ], $user);

        // الاسم الإنجليزي يطابق الكتالوج ⇒ لا يحتاج قراراً
        $this->assertSame(InventoryImport::ROW_EXACT_EN, $import->rows()[0]['status']);

        $this->commit($user, $import)->assertRedirect();

        $this->assertSame(1, Medicine::count());
        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $existing->id,
        ]);
        $this->assertSame(0, (int) $import->fresh()->created_medicines);
    }

    /* =====================================================================
     | مسار الاقتراح (fuzzy) وكتالوج الوزارة
     ===================================================================== */

    public function test_a_fuzzy_match_alone_never_creates_a_medicine(): void
    {
        [$user] = $this->pharmacyUser();

        MohMedicine::create([
            'trade_name' => 'ZANTAC 150MG',
            'generic_name' => 'ranitidine',
            'moh_product_id' => 90001,
        ]);

        $import = $this->preview([['trade_name' => 'Zantac', 'price' => 3, 'quantity' => 5]], $user);

        // مطابقة تقريبية ⇒ اقتراح فقط، والقرار بيد الصيدلي
        $this->assertContains($import->rows()[0]['status'], [
            InventoryImport::ROW_FUZZY,
            InventoryImport::ROW_REVIEW,
        ]);
        $this->assertNotEmpty($import->rows()[0]['suggestions']);
        $this->assertSame(0, Medicine::count());

        $this->commit($user, $import)->assertRedirect();
        $this->assertSame(0, Medicine::count());
    }

    public function test_a_fuzzy_row_can_be_created_from_an_moh_suggestion(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();

        $moh = MohMedicine::create([
            'trade_name' => 'ZANTAC 150MG',
            'generic_name' => 'ranitidine',
            'moh_product_id' => 90001,
            'official_price' => 11.00,
        ]);

        $import = $this->preview([['trade_name' => 'Zantac', 'price' => 11, 'quantity' => 5]], $user);

        $this->decide($user, $import, [
            ['row' => 2, 'action' => 'create', 'moh_id' => $moh->id],
        ])->assertOk();

        $this->commit($user, $import)->assertRedirect();

        $medicine = Medicine::where('trade_name', 'ZANTAC 150MG')->firstOrFail();
        $this->assertSame('ranitidine', $medicine->active_ingredient);
        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
        ]);
    }

    public function test_a_moh_only_row_needs_an_explicit_decision(): void
    {
        [$user] = $this->pharmacyUser();

        MohMedicine::create([
            'trade_name' => 'SOMEMOHDRUG',
            'generic_name' => 'somegeneric',
            'moh_product_id' => 91000,
        ]);

        $import = $this->preview([['trade_name' => 'SOMEMOHDRUG', 'price' => 3, 'quantity' => 5]], $user);

        $this->assertSame(InventoryImport::ROW_MOH, $import->rows()[0]['status']);
        $this->assertSame(InventoryImport::DECISION_PENDING, $import->rows()[0]['decision']);
        $this->assertSame(0, Medicine::count());
    }

    public function test_an_ambiguous_fuzzy_match_requires_review(): void
    {
        [$user] = $this->pharmacyUser();

        MohMedicine::create(['trade_name' => 'AMBIGUOUS DRUG A', 'generic_name' => 'x', 'moh_product_id' => 90003]);
        MohMedicine::create(['trade_name' => 'AMBIGUOUS DRUG B', 'generic_name' => 'y', 'moh_product_id' => 90004]);

        $import = $this->preview([['trade_name' => 'Ambiguous', 'price' => 3, 'quantity' => 5]], $user);

        $this->assertSame(InventoryImport::ROW_REVIEW, $import->rows()[0]['status']);
        $this->assertGreaterThanOrEqual(2, count($import->rows()[0]['suggestions']));
    }

    /* =====================================================================
     | التكرار
     ===================================================================== */

    public function test_duplicate_rows_are_grouped_and_only_the_winner_is_written(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([
            ['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 10],
            ['trade_name' => 'Panadol extra', 'price' => 6, 'quantity' => 99],
        ], $user);

        $this->assertSame(2, (int) $import->duplicate_rows);
        $this->assertCount(1, $import->duplicateGroups());

        // قرار الدمج + قرار الفائز (السطر 3 مع keep_last) — الاثنان مطلوبان
        $this->decide($user, $import, [
            ['row' => 3, 'action' => 'link', 'medicine_id' => $medicine->id],
        ], [
            'm:'.$medicine->id => InventoryImport::MERGE_KEEP_LAST,
        ])->assertOk()->assertJson(['success' => true, 'ready' => true]);

        $this->commit($user, $import)->assertRedirect();

        $row = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->where('medicine_id', $medicine->id)->firstOrFail();

        $this->assertSame(99, (int) $row->quantity);
        $this->assertSame(1, PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->count());
    }

    public function test_keep_first_wins_with_the_first_row(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([
            ['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 10],
            ['trade_name' => 'Panadol extra', 'price' => 6, 'quantity' => 99],
        ], $user);

        $this->decide($user, $import, [
            ['row' => 2, 'action' => 'link', 'medicine_id' => $medicine->id],
        ], [
            'm:'.$medicine->id => InventoryImport::MERGE_KEEP_FIRST,
        ])->assertOk()->assertJson(['success' => true, 'ready' => true]);

        $this->commit($user, $import)->assertRedirect();

        $row = PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->where('medicine_id', $medicine->id)->firstOrFail();

        $this->assertSame(10, (int) $row->quantity);
    }

    public function test_a_non_winning_duplicate_row_does_not_need_its_own_decision(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([
            ['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 10],
            ['trade_name' => 'Panadol extra', 'price' => 6, 'quantity' => 99],
        ], $user);

        // السطر 2 ليس الفائز مع keep_last ⇒ قراره غير مطلوب
        $response = $this->decide($user, $import, [
            ['row' => 3, 'action' => 'link', 'medicine_id' => $medicine->id],
        ], [
            'm:'.$medicine->id => InventoryImport::MERGE_KEEP_LAST,
        ]);

        $response->assertOk()->assertJson(['success' => true, 'ready' => true]);

        $this->assertSame(0, $response->json('decisions.pending'));
        $this->assertSame(1, $response->json('decisions.to_link'));
        $this->assertSame(1, $response->json('decisions.to_skip'));
    }

    public function test_skip_all_writes_nothing_for_the_group(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([
            ['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 10],
            ['trade_name' => 'Panadol extra', 'price' => 6, 'quantity' => 99],
        ], $user);

        $this->decide($user, $import, [], [
            'm:'.$medicine->id => InventoryImport::MERGE_SKIP_ALL,
        ])->assertOk();

        $this->commit($user, $import)->assertRedirect();

        $this->assertSame(0, PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->count());
    }

    public function test_a_duplicate_group_blocks_the_commit_until_a_merge_decision_exists(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([
            ['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 10],
            ['trade_name' => 'Panadol extra', 'price' => 6, 'quantity' => 99],
        ], $user);

        $this->commit($user, $import)->assertRedirect();

        $this->assertSame(0, PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->count());
    }

    /* =====================================================================
     | المرادفات
     ===================================================================== */

    public function test_a_manual_link_learns_a_pharmacy_scoped_alias(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([['trade_name' => 'BANADOL', 'price' => 5, 'quantity' => 4]], $user);

        $this->decide($user, $import, [
            ['row' => 2, 'action' => 'link', 'medicine_id' => $medicine->id],
        ])->assertOk();

        $this->commit($user, $import)->assertRedirect();

        $this->assertDatabaseHas('pharmacy_medicine_aliases', [
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'alias' => 'banadol',
        ]);
    }

    public function test_a_learned_alias_is_used_on_the_next_import(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        PharmacyMedicineAlias::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'alias' => 'banadol',
        ]);

        $import = $this->preview([['trade_name' => 'BANADOL', 'price' => 5, 'quantity' => 4]], $user);

        $this->assertSame(InventoryImport::ROW_ALIAS, $import->rows()[0]['status']);
        $this->assertSame($medicine->id, $import->rows()[0]['medicine_id']);
    }

    public function test_an_automatic_match_does_not_learn_an_alias(): void
    {
        [$user] = $this->pharmacyUser();
        Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 4]], $user);

        $this->commit($user, $import)->assertRedirect();

        $this->assertSame(0, PharmacyMedicineAlias::count());
    }

    public function test_a_fuzzy_match_does_not_learn_an_alias(): void
    {
        [$user] = $this->pharmacyUser();
        Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        // "Panadol" يطابق تقريبياً "PANADOL EXTRA" عبر الـ mapping
        $import = $this->preview([['trade_name' => 'Panadol', 'price' => 5, 'quantity' => 4]], $user);

        $this->assertSame(InventoryImport::ROW_FUZZY, $import->rows()[0]['status']);

        $this->decide($user, $import, [['row' => 2, 'action' => 'skip']])->assertOk();
        $this->commit($user, $import)->assertRedirect();

        $this->assertSame(0, PharmacyMedicineAlias::count());
    }

    public function test_an_alias_of_another_pharmacy_is_not_used(): void
    {
        [$userA, $pharmacyA] = $this->pharmacyUser();
        [, $pharmacyB] = $this->pharmacyUser();

        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        PharmacyMedicineAlias::create([
            'pharmacy_id' => $pharmacyB->id,
            'medicine_id' => $medicine->id,
            'alias' => 'banadol',
        ]);

        $import = $this->preview([['trade_name' => 'BANADOL', 'price' => 5, 'quantity' => 4]], $userA);

        $this->assertNotSame(InventoryImport::ROW_ALIAS, $import->rows()[0]['status']);
    }

    /* =====================================================================
     | الأسطر غير الصالحة
     ===================================================================== */

    public function test_invalid_rows_are_reported_and_never_written(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([
            ['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 4],
            ['trade_name' => 'BROKEN ROW', 'price' => 5, 'quantity' => -3],
        ], $user);

        $this->assertSame(1, (int) $import->error_rows);
        $this->assertSame(InventoryImport::ROW_INVALID, $import->rows()[1]['status']);

        $this->commit($user, $import)->assertRedirect();

        // السطر الصالح يُكتب، والتالف يُتجاهل
        $this->assertSame(1, PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->count());
    }

    public function test_a_row_with_errors_cannot_be_used_to_create_a_medicine(): void
    {
        [$user] = $this->pharmacyUser();

        $import = $this->preview([
            ['trade_name' => 'BROKEN ROW', 'active_ingredient' => 'x', 'price' => 5, 'quantity' => -3],
        ], $user);

        $this->decide($user, $import, [['row' => 2, 'action' => 'create']])->assertStatus(422);

        $this->assertSame(0, Medicine::count());
    }

    /* =====================================================================
     | الآثار الجانبية بعد التنفيذ
     ===================================================================== */

    public function test_exactly_one_aggregated_notification_is_created(): void
    {
        [$user] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([
            ['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 40],
            ['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 40],
        ], $user);

        $this->decide($user, $import, [
            ['row' => 3, 'action' => 'link', 'medicine_id' => $medicine->id],
        ], ['m:'.$medicine->id => InventoryImport::MERGE_KEEP_LAST])->assertOk();

        $this->commit($user, $import)->assertRedirect();

        $this->assertSame(1, Notification::where('user_id', $user->id)->where('type', 'inventory_import')->count());
    }

    public function test_a_failing_notification_provider_does_not_fail_the_import(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        // مزوّد إشعارات يرمي استثناءً — الاستيراد يجب أن ينجح رغم ذلك
        $this->app->instance(FcmSender::class, new class implements FcmSender
        {
            public function enabled(): bool
            {
                return true;
            }

            public function sendToUser(User $user, string $title, string $body, array $data = []): void
            {
                throw new \RuntimeException('push provider down');
            }

            public function fromNotification(Notification $notification): void
            {
                throw new \RuntimeException('push provider down');
            }
        });

        $import = $this->preview([['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 4]], $user);

        $this->commit($user, $import)->assertRedirect();

        $this->assertSame(InventoryImport::STATUS_COMMITTED, $import->fresh()->status);
        $this->assertSame(1, PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->count());
    }

    public function test_the_temporary_file_is_deleted_after_commit(): void
    {
        [$user] = $this->pharmacyUser();
        Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 4]], $user);

        $path = $import->file_path;
        $this->assertTrue(Storage::disk('local')->exists($path));

        $this->commit($user, $import)->assertRedirect();

        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    public function test_cancelling_a_session_writes_nothing_and_marks_it_expired(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        $import = $this->preview([['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 4]], $user);

        $this->actingAs($user)
            ->post(route('pharmacy.inventory.import.cancel', ['import' => $import->uuid]))
            ->assertRedirect(route('pharmacy.inventory.import.index'));

        $this->assertSame(InventoryImport::STATUS_EXPIRED, $import->fresh()->status);
        $this->assertSame(0, PharmacyMedicine::where('pharmacy_id', $pharmacy->id)->count());
    }

    /* =====================================================================
     | التنزيلات
     | ===================================================================== */

    public function test_the_empty_template_can_be_downloaded(): void
    {
        [$user] = $this->pharmacyUser();

        $this->actingAs($user)
            ->get(route('pharmacy.inventory.import.template', ['mode' => 'empty']))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_the_current_inventory_template_can_be_downloaded(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $medicine = Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 17,
            'min_stock' => 3,
        ]);

        $this->actingAs($user)
            ->get(route('pharmacy.inventory.import.template', ['mode' => 'current']))
            ->assertOk();
    }

    public function test_the_error_report_can_be_downloaded(): void
    {
        [$user] = $this->pharmacyUser();

        $import = $this->preview([
            ['trade_name' => 'BROKEN ROW', 'price' => 5, 'quantity' => -3],
            ['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 4],
        ], $user);

        $this->actingAs($user)
            ->get(route('pharmacy.inventory.import.errors', ['import' => $import->uuid]))
            ->assertOk();
    }

    public function test_the_error_report_neutralizes_formula_injection(): void
    {
        [$user] = $this->pharmacyUser();

        // اسم يبدأ بـ = : صيغة تُنفَّذ لو فُتح الملف في Excel بلا حماية
        $import = $this->preview([
            ['trade_name' => '=cmd|calc!A1', 'price' => 5, 'quantity' => -3],
        ], $user);

        $export = \App\Exports\InventoryErrorsExport::fromImport($import->fresh());
        $rows = $export->array();

        $this->assertNotEmpty($rows);

        // العمود الأول بعد رقم السطر هو trade_name
        $this->assertSame("'=cmd|calc!A1", $rows[0][1]);
    }

    /* =====================================================================
     | حد المعدل
     ===================================================================== */

    public function test_the_import_throttle_is_keyed_by_user_not_ip(): void
    {
        [$userA] = $this->pharmacyUser();
        [$userB] = $this->pharmacyUser();

        config(['inventory_import.rate_limit_per_hour' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($userA)
                ->get(route('pharmacy.inventory.import.index'))
                ->assertOk();
        }

        // المستخدم نفسه تجاوز الحد
        $this->actingAs($userA)
            ->get(route('pharmacy.inventory.import.index'))
            ->assertStatus(429);

        // مستخدم آخر من نفس الـ IP لا يتأثر — المفتاح ليس IP
        $this->actingAs($userB)
            ->get(route('pharmacy.inventory.import.index'))
            ->assertOk();
    }

    public function test_the_import_throttle_does_not_consume_the_general_writes_limiter(): void
    {
        [$user] = $this->pharmacyUser();
        Medicine::factory()->create(['trade_name' => 'PANADOL EXTRA']);

        config(['inventory_import.rate_limit_per_hour' => 50]);

        // استهلاك كامل لحد الاستيراد لا يجب أن يمسّ 'writes'
        for ($i = 0; $i < 25; $i++) {
            $this->preview([['trade_name' => 'PANADOL EXTRA', 'price' => 5, 'quantity' => 4]], $user);
        }

        $this->actingAs($user)
            ->get(route('pharmacy.inventory.import.index'))
            ->assertOk();
    }
}
