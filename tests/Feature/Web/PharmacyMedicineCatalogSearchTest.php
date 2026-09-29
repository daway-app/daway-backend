<?php

namespace Tests\Feature\Web;

use App\Models\Category;
use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Tests\TestCase;

/**
 * Phase: إعادة تصميم صفحة إضافة الدواء — بحث الكتالوج الموحّد + الإضافة + اليدوي + الأمان.
 * الـendpoint: GET /pharmacy/medicines/catalog-search (يرتبط بـ moh_medicines فقط).
 */
class PharmacyMedicineCatalogSearchTest extends TestCase
{
    private User $user;
    private Pharmacy $pharmacy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategorySeeder::class);

        $this->user = User::factory()->pharmacy()->create();
        $this->pharmacy = Pharmacy::factory()->create(['user_id' => $this->user->id, 'is_active' => true]);
    }

    // ── Catalog: البحث ───────────────────────────────────────────────

    public function test_catalog_search_by_trade_name(): void
    {
        MohMedicine::create(['trade_name' => 'PANADOL 500mg', 'generic_name' => 'PARACETAMOL', 'moh_product_id' => 101]);

        $this->actingAs($this->user)
            ->getJson('/pharmacy/medicines/catalog-search?q=panadol')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.name', 'PANADOL 500mg')
            ->assertJsonPath('items.0.moh_product_id', 101);
    }

    public function test_catalog_search_by_arabic_name(): void
    {
        MohMedicine::create(['trade_name' => 'PANADOL 500mg', 'generic_name' => 'PARACETAMOL', 'moh_product_id' => 102]);
        Medicine::create(['trade_name' => 'PANADOL 500mg', 'trade_name_ar' => 'بنادول', 'active_ingredient' => 'Paracetamol']);

        $this->actingAs($this->user)
            ->getJson('/pharmacy/medicines/catalog-search?q='.urlencode('بنادول'))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.name', 'PANADOL 500mg');
    }

    public function test_catalog_search_by_active_ingredient(): void
    {
        MohMedicine::create(['trade_name' => 'BRUEN 400', 'generic_name' => 'IBUPROFEN', 'moh_product_id' => 103]);

        $this->actingAs($this->user)
            ->getJson('/pharmacy/medicines/catalog-search?q=ibuprofen')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.name', 'BRUEN 400');
    }

    public function test_catalog_search_by_moh_product_id(): void
    {
        MohMedicine::create(['trade_name' => 'GOUTEX', 'generic_name' => 'COLCHICINE', 'moh_product_id' => 4412]);

        $this->actingAs($this->user)
            ->getJson('/pharmacy/medicines/catalog-search?q=4412')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.moh_product_id', 4412);
    }

    public function test_catalog_search_no_results(): void
    {
        MohMedicine::create(['trade_name' => 'PANADOL 500mg', 'generic_name' => 'PARACETAMOL', 'moh_product_id' => 104]);

        $this->actingAs($this->user)
            ->getJson('/pharmacy/medicines/catalog-search?q=zzznonexistent')
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('items', []);
    }

    public function test_catalog_search_short_or_empty_query_returns_empty(): void
    {
        MohMedicine::create(['trade_name' => 'PANADOL 500mg', 'generic_name' => 'PARACETAMOL', 'moh_product_id' => 105]);

        $this->actingAs($this->user)->getJson('/pharmacy/medicines/catalog-search?q=')
            ->assertOk()->assertJsonPath('count', 0)->assertJsonPath('is_complete', false);

        $this->actingAs($this->user)->getJson('/pharmacy/medicines/catalog-search?q=p')
            ->assertOk()->assertJsonPath('count', 0)->assertJsonPath('is_complete', false);
    }

    public function test_catalog_search_results_are_capped_at_twenty(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            MohMedicine::create(['trade_name' => "TESTMED $i", 'generic_name' => 'TESTGEN', 'moh_product_id' => 9000 + $i]);
        }

        $this->actingAs($this->user)
            ->getJson('/pharmacy/medicines/catalog-search?q=testmed')
            ->assertOk()
            ->assertJsonPath('count', 20)
            ->assertJsonCount(20, 'items');
    }

    public function test_catalog_search_reads_from_local_moh_catalog_not_external(): void
    {
        $moh = MohMedicine::create([
            'trade_name' => 'LOCALONLY MED',
            'generic_name' => 'LOCALGEN',
            'manufacturer' => 'GSK',
            'moh_product_id' => 201,
            'moh_drug_id' => 301,
        ]);

        $this->actingAs($this->user)
            ->getJson('/pharmacy/medicines/catalog-search?q=localonly')
            ->assertOk()
            ->assertJsonPath('items.0.moh_medicine_id', $moh->id)
            ->assertJsonPath('items.0.type', 'moh')
            ->assertJsonPath('items.0.moh_product_id', 201)
            ->assertJsonPath('items.0.moh_drug_id', 301)
            ->assertJsonStructure(['items' => [['type', 'id', 'moh_medicine_id', 'moh_product_id', 'moh_drug_id', 'name', 'sub', 'official_price', 'already_added', 'existing_row_id']]]);
    }

    // ── Add: اختيار دواء من الكتالوج ────────────────────────────────

    public function test_add_catalog_medicine_creates_pharmacy_row(): void
    {
        $moh = MohMedicine::create(['trade_name' => 'CATADD MED', 'generic_name' => 'CATGEN', 'moh_product_id' => 501]);

        $this->actingAs($this->user)
            ->post(route('pharmacy.medicines.store'), [
                'moh_medicine_id' => $moh->id,
                'price' => 12.5,
                'quantity' => 40,
                'is_available' => 1,
            ])
            ->assertRedirect(route('pharmacy.medicines.index'));

        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $this->pharmacy->id,
            'moh_medicine_id' => $moh->id,
            'price' => 12.5,
            'quantity' => 40,
            'is_available' => true,
        ]);
        // الجسر المحلي أُنشئ (نفس الاسم التجاري)
        $this->assertSame(1, Medicine::where('trade_name', 'CATADD MED')->count());
    }

    public function test_add_medicine_price_is_required(): void
    {
        $moh = MohMedicine::create(['trade_name' => 'NOPRICE MED', 'generic_name' => 'X', 'moh_product_id' => 502]);

        $this->actingAs($this->user)
            ->post(route('pharmacy.medicines.store'), [
                'moh_medicine_id' => $moh->id,
                'quantity' => 10,
            ])
            ->assertSessionHasErrors('price');

        $this->assertDatabaseCount('pharmacy_medicines', 0);
    }

    public function test_add_medicine_quantity_is_required(): void
    {
        $moh = MohMedicine::create(['trade_name' => 'NOQTY MED', 'generic_name' => 'X', 'moh_product_id' => 503]);

        $this->actingAs($this->user)
            ->post(route('pharmacy.medicines.store'), [
                'moh_medicine_id' => $moh->id,
                'price' => 5,
            ])
            ->assertSessionHasErrors('quantity');

        $this->assertDatabaseCount('pharmacy_medicines', 0);
    }

    public function test_availability_flag_is_persisted(): void
    {
        $moh = MohMedicine::create(['trade_name' => 'UNAVAIL MED', 'generic_name' => 'X', 'moh_product_id' => 504]);

        $this->actingAs($this->user)
            ->post(route('pharmacy.medicines.store'), [
                'moh_medicine_id' => $moh->id,
                'price' => 5,
                'quantity' => 0,
                'is_available' => 0,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $this->pharmacy->id,
            'moh_medicine_id' => $moh->id,
            'is_available' => false,
        ]);
    }

    public function test_duplicate_catalog_medicine_in_same_pharmacy_is_blocked_with_edit_link(): void
    {
        $moh = MohMedicine::create(['trade_name' => 'DUP MED', 'generic_name' => 'X', 'moh_product_id' => 505]);

        $this->actingAs($this->user)->post(route('pharmacy.medicines.store'), [
            'moh_medicine_id' => $moh->id,
            'price' => 5,
            'quantity' => 10,
            'is_available' => 1,
        ])->assertRedirect();

        $this->actingAs($this->user)->post(route('pharmacy.medicines.store'), [
            'moh_medicine_id' => $moh->id,
            'price' => 9,
            'quantity' => 1,
            'is_available' => 1,
        ])->assertSessionHasErrors('medicine_id');

        // لا سطر جديد — الدوبليكت مرفوض
        $this->assertDatabaseCount('pharmacy_medicines', 1);
    }

    public function test_catalog_search_marks_already_added_medicines(): void
    {
        $moh = MohMedicine::create(['trade_name' => 'MARKED MED', 'generic_name' => 'X', 'moh_product_id' => 506]);
        $bridge = Medicine::create(['trade_name' => 'MARKED MED', 'active_ingredient' => 'X']);
        PharmacyMedicine::create([
            'pharmacy_id' => $this->pharmacy->id,
            'medicine_id' => $bridge->id,
            'moh_medicine_id' => $moh->id,
            'price' => 5,
            'quantity' => 10,
            'is_available' => true,
        ]);

        $this->actingAs($this->user)
            ->getJson('/pharmacy/medicines/catalog-search?q=marked')
            ->assertOk()
            ->assertJsonPath('items.0.already_added', true)
            ->assertJsonPath('items.0.existing_row_id', PharmacyMedicine::where('pharmacy_id', $this->pharmacy->id)->first()->id);
    }

    // ── Manual: إضافة يدوية ──────────────────────────────────────────

    public function test_manual_medicine_add_creates_medicine_and_category_link(): void
    {
        $category = Category::where('slug', 'medicines')->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('pharmacy.medicines.store'), [
                'trade_name' => 'MANUAL DRUG',
                'active_ingredient' => 'Mysteryol',
                'category_id' => $category->id,
                'price' => 8,
                'quantity' => 15,
                'is_available' => 1,
            ])
            ->assertRedirect(route('pharmacy.medicines.index'));

        $this->assertDatabaseHas('medicines', ['trade_name' => 'MANUAL DRUG', 'active_ingredient' => 'Mysteryol']);
        $this->assertDatabaseHas('category_medicine_links', [
            'medicine_id' => Medicine::where('trade_name', 'MANUAL DRUG')->value('id'),
            'category_id' => $category->id,
        ]);
        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $this->pharmacy->id,
            'medicine_id' => Medicine::where('trade_name', 'MANUAL DRUG')->value('id'),
            'price' => 8,
        ]);
    }

    public function test_manual_add_requires_trade_name_and_ingredient(): void
    {
        $category = Category::where('slug', 'medicines')->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('pharmacy.medicines.store'), [
                'active_ingredient' => 'X',
                'category_id' => $category->id,
                'price' => 5,
                'quantity' => 5,
            ])
            ->assertSessionHasErrors('trade_name');

        $this->actingAs($this->user)
            ->post(route('pharmacy.medicines.store'), [
                'trade_name' => 'NO ING MED',
                'category_id' => $category->id,
                'price' => 5,
                'quantity' => 5,
            ])
            ->assertSessionHasErrors('active_ingredient');

        $this->assertDatabaseCount('medicines', 0);
    }

    public function test_manual_add_rejects_arabic_trade_name(): void
    {
        $category = Category::where('slug', 'medicines')->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('pharmacy.medicines.store'), [
                'trade_name' => 'دواء عربي',
                'active_ingredient' => 'X',
                'category_id' => $category->id,
                'price' => 5,
                'quantity' => 5,
            ])
            ->assertSessionHasErrors('trade_name');

        $this->assertDatabaseCount('medicines', 0);
    }

    public function test_manual_add_duplicate_trade_name_is_blocked(): void
    {
        $category = Category::where('slug', 'medicines')->firstOrFail();

        $this->actingAs($this->user)->post(route('pharmacy.medicines.store'), [
            'trade_name' => 'ONCE ONLY',
            'active_ingredient' => 'Y',
            'category_id' => $category->id,
            'price' => 5,
            'quantity' => 10,
            'is_available' => 1,
        ])->assertRedirect();

        $this->actingAs($this->user)->post(route('pharmacy.medicines.store'), [
            'trade_name' => 'ONCE ONLY',
            'active_ingredient' => 'Y',
            'category_id' => $category->id,
            'price' => 9,
            'quantity' => 1,
            'is_available' => 1,
        ])->assertSessionHasErrors('medicine_id');

        $this->assertDatabaseCount('medicines', 1);
        $this->assertDatabaseCount('pharmacy_medicines', 1);
    }

    // ── Security ─────────────────────────────────────────────────────

    public function test_catalog_search_requires_authentication(): void
    {
        // غير مصادَق → يُرفض (JSON: 401 بدل redirect)
        $this->getJson('/pharmacy/medicines/catalog-search?q=pan')
            ->assertStatus(401);
    }

    public function test_catalog_search_rejects_non_pharmacy_role(): void
    {
        $patient = User::factory()->patient()->create();

        // مصادَق لكن الدور patient → يُرفض (JSON: 403 بدل redirect)
        $this->actingAs($patient)
            ->getJson('/pharmacy/medicines/catalog-search?q=pan')
            ->assertStatus(403);
    }

    public function test_cannot_affect_another_pharmacy_inventory(): void
    {
        $otherPharmacy = Pharmacy::factory()->create(['user_id' => User::factory()->pharmacy()->create()->id]);
        $moh = MohMedicine::create(['trade_name' => 'OWNED MED', 'generic_name' => 'X', 'moh_product_id' => 601]);

        // صيدلية أخرى تملك الدواء
        $bridge = Medicine::create(['trade_name' => 'OWNED MED', 'active_ingredient' => 'X']);
        PharmacyMedicine::create([
            'pharmacy_id' => $otherPharmacy->id,
            'medicine_id' => $bridge->id,
            'moh_medicine_id' => $moh->id,
            'price' => 5,
            'quantity' => 10,
            'is_available' => true,
        ]);

        // بحث الصيدلية الحالية لا يعرض «مضاف» على صيدلية أخرى
        $this->actingAs($this->user)
            ->getJson('/pharmacy/medicines/catalog-search?q=owned')
            ->assertOk()
            ->assertJsonPath('items.0.already_added', false);

        // تعديل سطر صيدلية أخرى → رفض
        $row = PharmacyMedicine::where('pharmacy_id', $otherPharmacy->id)->first();
        $this->actingAs($this->user)
            ->put(route('pharmacy.medicines.update', $row->id), [
                'price' => 99,
                'quantity' => 99,
                'is_available' => 1,
            ])
            ->assertRedirect(route('pharmacy.medicines.index'));
        $this->assertSame(5.0, (float) $row->fresh()->price);
    }
}
