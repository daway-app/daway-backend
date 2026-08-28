<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PharmacyMedicineApiTest extends TestCase
{
    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    public function test_pharmacy_can_list_own_medicines(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicineA = Medicine::factory()->create();
        $medicineB = Medicine::factory()->create();

        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicineA->id,
            'price' => 5,
            'quantity' => 20,
            'is_available' => true,
        ]);
        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicineB->id,
            'price' => 7,
            'quantity' => 3,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/pharmacy/medicines');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [[
                    'id', 'medicine_id', 'pharmacy_id', 'price', 'quantity', 'min_stock',
                    'is_available', 'is_low_stock', 'is_out_of_stock', 'medicine' => ['id', 'trade_name'],
                ]],
                'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
            ])
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_patient_cannot_list_pharmacy_medicines(): void
    {
        $patient = User::factory()->patient()->create();

        Sanctum::actingAs($patient);

        $this->getJson('/api/pharmacy/medicines')->assertForbidden();
    }

    public function test_pharmacy_can_create_medicine(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/pharmacy/medicines', [
            'medicine_id' => $medicine->id,
            'quantity' => 10,
            'price' => 12.50,
            'is_available' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.quantity', 10)
            // الحد الأدنى ثابت (10) — القيمة المرسلة تُتجاهل
            ->assertJsonPath('data.min_stock', PharmacyMedicine::LOW_STOCK_THRESHOLD)
            ->assertJsonPath('data.price', (float) 12.50)
            ->assertJsonPath('data.medicine.id', $medicine->id);

        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 10,
            'price' => 12.50,
        ]);
    }

    public function test_duplicate_medicine_returns_422(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();

        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/medicines', [
            'medicine_id' => $medicine->id,
            'quantity' => 5,
            'price' => 6,
            'is_available' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('medicine_id');
    }

    public function test_update_enriches_catalog_with_trade_name_and_active_ingredient(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        // دواء بدون اسم عربي ومادة فعالة فارغة
        $medicine = Medicine::factory()->create([
            'trade_name' => 'Brufen 400',
            'trade_name_ar' => null,
            'active_ingredient' => '',
        ]);

        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/pharmacy/medicines/{$pm->id}", [
            'medicine_id' => $medicine->id,
            'trade_name' => 'Brufen 400',         // موجود — لا تغيير
            'trade_name_ar' => 'بروفين 400',      // فارغ — يُملأ
            'active_ingredient' => 'Ibuprofen',  // فارغ — يُملأ
            'price' => 7.5,
            'quantity' => 12,
        ]);

        $response->assertOk();

        $medicine->refresh();
        $this->assertSame('Brufen 400', $medicine->trade_name);
        $this->assertSame('بروفين 400', $medicine->trade_name_ar);
        $this->assertSame('Ibuprofen', $medicine->active_ingredient);
        $this->assertSame(7.5, (float) $pm->fresh()->price);
    }

    public function test_update_does_not_overwrite_existing_catalog_values(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        $medicine = Medicine::factory()->create([
            'trade_name' => 'Adol 500',
            'trade_name_ar' => 'أدول',
            'active_ingredient' => 'Paracetamol',
        ]);

        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        $this->putJson("/api/pharmacy/medicines/{$pm->id}", [
            'medicine_id' => $medicine->id,
            'trade_name' => 'هدم للكتالوج',
            'trade_name_ar' => 'اسم خاطئ',
            'active_ingredient' => 'سيء',
            'price' => 6,
            'quantity' => 11,
        ])->assertOk();

        $medicine->refresh();
        // القيم الصحيحة في الكتالوج لم تتغير
        $this->assertSame('Adol 500', $medicine->trade_name);
        $this->assertSame('أدول', $medicine->trade_name_ar);
        $this->assertSame('Paracetamol', $medicine->active_ingredient);
    }

    public function test_update_rejects_arabic_characters_in_trade_name(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();
        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
        ]);

        Sanctum::actingAs($user);

        $this->putJson("/api/pharmacy/medicines/{$pm->id}", [
            'medicine_id' => $medicine->id,
            'trade_name' => 'بنادول',  // عربي في حقل إنجليزي
            'price' => 5,
            'quantity' => 10,
        ])->assertStatus(422)
          ->assertJsonValidationErrors('trade_name');
    }

    public function test_pharmacy_can_update_medicine(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();

        $pharmacyMedicine = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/pharmacy/medicines/{$pharmacyMedicine->id}", [
            'medicine_id' => $medicine->id,
            'quantity' => 20,
            'price' => 6,
            'is_available' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.quantity', 20)
            ->assertJsonPath('data.min_stock', PharmacyMedicine::LOW_STOCK_THRESHOLD);

        $this->assertDatabaseHas('pharmacy_medicines', [
            'id' => $pharmacyMedicine->id,
            'quantity' => 20,
        ]);
    }

    public function test_pharmacy_can_delete_medicine(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();

        $pharmacyMedicine = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/pharmacy/medicines/{$pharmacyMedicine->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('pharmacy_medicines', [
            'id' => $pharmacyMedicine->id,
        ]);
    }

    public function test_pharmacy_cannot_modify_another_pharmacys_medicine(): void
    {
        [$userA, $pharmacyA] = $this->pharmacyUserWithPharmacy();
        [$userB] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();

        $pharmacyMedicineA = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacyA->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 7,
            'is_available' => true,
        ]);

        Sanctum::actingAs($userB);

        $this->putJson("/api/pharmacy/medicines/{$pharmacyMedicineA->id}", [
            'medicine_id' => $medicine->id,
            'quantity' => 99,
            'price' => 1,
            'is_available' => true,
        ])->assertNotFound();

        $this->assertDatabaseHas('pharmacy_medicines', [
            'id' => $pharmacyMedicineA->id,
            'quantity' => 7,
            'price' => 5,
        ]);
    }

    public function test_pharmacy_search_returns_catalog(): void
    {
        [$user] = $this->pharmacyUserWithPharmacy();

        Medicine::factory()->create(['trade_name' => 'Panadol', 'active_ingredient' => 'Paracetamol']);
        MohMedicine::create([
            'trade_name' => 'Panadol Extra',
            'generic_name' => 'Paracetamol',
            'manufacturer' => 'GSK',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/pharmacy/medicines/search?q=Pan');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['medicines', 'moh_catalog'],
            ])
            ->assertJsonPath('data.medicines.0.name', 'Panadol')
            ->assertJsonPath('data.moh_catalog.0.name', 'Panadol Extra');
    }

    public function test_alternatives_by_active_ingredient(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        $medicineA = Medicine::factory()->create(['trade_name' => 'Panadol', 'active_ingredient' => 'Paracetamol']);
        $medicineB = Medicine::factory()->create(['trade_name' => 'Paracetol', 'active_ingredient' => 'Paracetamol']);
        $medicineC = Medicine::factory()->create(['trade_name' => 'Brufen', 'active_ingredient' => 'Ibuprofen']);

        $pharmacyMedicine = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicineA->id,
            'price' => 5,
            'quantity' => 10,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/pharmacy/medicines/{$pharmacyMedicine->id}/alternatives");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $medicineB->id)
            ->assertJsonPath('data.0.active_ingredient', 'Paracetamol');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($medicineC->id, $ids);
    }

    public function test_pharmacy_can_add_medicine_by_name_without_id(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        Medicine::factory()->create(['trade_name' => 'Panadol', 'active_ingredient' => 'Paracetamol']);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/pharmacy/medicines/by-name', [
            'trade_name' => 'Panadol',
            'active_ingredient' => 'Paracetamol',
            'price' => 8,
            'quantity' => 15,
            'is_available' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.medicine.trade_name', 'Panadol');

        // لا يُنشئ دواء جديداً — يعيد استخدام الكتالوج العام
        $this->assertSame(1, Medicine::where('trade_name', 'Panadol')->count());
        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $pharmacy->id,
            'quantity' => 15,
            'price' => 8,
        ]);
    }

    public function test_add_medicine_by_name_resolves_moh_catalog_entry(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        MohMedicine::create([
            'trade_name' => 'Adol Extra',
            'generic_name' => 'Paracetamol',
            'manufacturer' => 'GSK',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/pharmacy/medicines/by-name', [
            'trade_name' => 'Adol Extra',
            'active_ingredient' => 'Paracetamol',
            'price' => 10,
            'quantity' => 5,
        ]);

        $response->assertStatus(201);

        $medicine = Medicine::where('trade_name', 'Adol Extra')->first();
        $this->assertNotNull($medicine);
        $this->assertSame('Paracetamol', $medicine->active_ingredient);
        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
        ]);
    }

    public function test_add_medicine_by_unknown_name_creates_new_catalog_entry(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/pharmacy/medicines/by-name', [
            'trade_name' => 'Brand New Drug',
            'active_ingredient' => 'Mysteryol',
            'price' => 20,
            'quantity' => 7,
        ]);

        $response->assertStatus(201);

        $medicine = Medicine::where('trade_name', 'Brand New Drug')->first();
        $this->assertNotNull($medicine);
        $this->assertSame('Mysteryol', $medicine->active_ingredient);
        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'quantity' => 7,
        ]);
    }

    public function test_duplicate_medicine_by_name_returns_422(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create(['trade_name' => 'Duplix']);

        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/medicines/by-name', [
            'trade_name' => 'Duplix',
            'active_ingredient' => 'Paracetamol',
            'price' => 6,
            'quantity' => 3,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('trade_name');
    }

    public function test_patient_cannot_add_medicine_by_name(): void
    {
        $patient = User::factory()->patient()->create();

        Sanctum::actingAs($patient);

        $this->postJson('/api/pharmacy/medicines/by-name', [
            'trade_name' => 'Panadol',
            'price' => 8,
            'quantity' => 15,
        ])->assertForbidden();
    }

    public function test_add_medicine_requires_english_name(): void
    {
        [$user] = $this->pharmacyUserWithPharmacy();

        Sanctum::actingAs($user);

        // اسم عربي في الحقل الإلزامي → يُرفض
        $this->postJson('/api/pharmacy/medicines/by-name', [
            'trade_name' => 'بنادول اكسترا',
            'price' => 8,
            'quantity' => 15,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('trade_name');

        // حقل الاسم العربي يجب أن يكون عربياً عند إرساله
        $this->postJson('/api/pharmacy/medicines/by-name', [
            'trade_name' => 'Panadol',
            'trade_name_ar' => 'not arabic',
            'active_ingredient' => 'Paracetamol',
            'price' => 8,
            'quantity' => 15,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('trade_name_ar');
    }

    public function test_add_by_name_requires_active_ingredient(): void
    {
        [$user] = $this->pharmacyUserWithPharmacy();

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/medicines/by-name', [
            'trade_name' => 'Panadol',
            'price' => 8,
            'quantity' => 15,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('active_ingredient');
    }

    public function test_add_medicine_with_optional_arabic_name_persists_it(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/pharmacy/medicines/by-name', [
            'trade_name' => 'Panadol Advance',
            'trade_name_ar' => 'بنادول أدفانس',
            'active_ingredient' => 'Paracetamol',
            'price' => 9,
            'quantity' => 12,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.medicine.trade_name_ar', 'بنادول أدفانس');

        $this->assertDatabaseHas('medicines', [
            'trade_name' => 'Panadol Advance',
            'trade_name_ar' => 'بنادول أدفانس',
        ]);
    }

    public function test_add_medicine_resolves_existing_entry_by_arabic_name(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $existing = Medicine::factory()->create([
            'trade_name' => 'Panadol XR',
            'trade_name_ar' => 'بنادول ممتد',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/pharmacy/medicines/by-name', [
            'trade_name' => 'Brand Unknown EN',
            'trade_name_ar' => 'بنادول ممتد',
            'active_ingredient' => 'Paracetamol',
            'price' => 11,
            'quantity' => 6,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.medicine.id', $existing->id);

        // لم يُنشأ دواء جديد — تمت المطابقة بالاسم العربي
        $this->assertSame(1, Medicine::where('trade_name_ar', 'بنادول ممتد')->count());
        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $existing->id,
        ]);
    }

    public function test_search_matches_arabic_name_and_returns_it(): void
    {
        [$user] = $this->pharmacyUserWithPharmacy();
        Medicine::factory()->create([
            'trade_name' => 'Panadol',
            'trade_name_ar' => 'بنادول',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/pharmacy/medicines/search?q='.urlencode('بنادول'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.medicines.0.name', 'Panadol')
            ->assertJsonPath('data.medicines.0.name_ar', 'بنادول');
    }

    public function test_add_by_name_enriches_existing_medicine_with_active_ingredient(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        // دواء موجود بالكتالوج بدون مادة فعالة (سلسلة فارغة — العمود NOT NULL)
        $existing = Medicine::factory()->create([
            'trade_name' => 'Brufen 400',
            'active_ingredient' => '',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/pharmacy/medicines/by-name', [
            'trade_name' => 'Brufen 400',
            'active_ingredient' => 'Ibuprofen',
            'price' => 7.5,
            'quantity' => 10,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.medicine.id', $existing->id);

        // المادة الفعالة أثرت الدواء الموجود الفارغ بدل تجاهلها
        $this->assertSame('Ibuprofen', $existing->fresh()->active_ingredient);
        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $existing->id,
            'price' => 7.5,
        ]);
    }

    public function test_add_by_name_does_not_overwrite_existing_active_ingredient(): void
    {
        [$user] = $this->pharmacyUserWithPharmacy();

        $existing = Medicine::factory()->create([
            'trade_name' => 'Adol 500',
            'active_ingredient' => 'Paracetamol',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/medicines/by-name', [
            'trade_name' => 'Adol 500',
            'active_ingredient' => 'مادة خاطئة',
            'price' => 5,
            'quantity' => 3,
        ])->assertStatus(201);

        // لا نمسح بيانات الكتالوج الصحيحة بقيم جديدة
        $this->assertSame('Paracetamol', $existing->fresh()->active_ingredient);
    }
}
