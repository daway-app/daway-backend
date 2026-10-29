<?php

namespace Tests\Feature\Api\Pharmacy;

use App\Models\Medicine;
use App\Models\MedicineBarcode;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/pharmacy/accounting/barcode/{barcode}
 *
 * الهدف الحقيقي لهذا الملف ليس «200» بل **العزل**: لا يجوز أن يُرجع البحث
 * صنفًا يملكه غيرك. الاختبار الأخير (`test_other_pharmacy_stock_is_never_returned`)
 * هو الأهم — لو سقط، صار بإمكان صيدلية بيع مخزون صيدلية أخرى.
 */
final class AccountingBarcodeLookupTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Pharmacy} */
    private function pharmacyUser(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    private function mohWithBarcode(string $barcode = '6291041500213'): MohMedicine
    {
        $moh = MohMedicine::create([
            'trade_name' => 'BARCODED MED',
            'generic_name' => 'Paracetamol',
            'manufacturer' => 'TestPharma',
            'dosage_form' => 'Tablet',
            'packaging' => '20 tablets',
            'moh_product_id' => random_int(1000, 9999),
        ]);

        MedicineBarcode::create([
            'moh_medicine_id' => $moh->id,
            'barcode' => $barcode,
            'barcode_type' => 'EAN13',
            'source' => 'drugs_api',
            'confidence' => 0.97,
        ]);

        return $moh;
    }

    private function stockRow(
        Pharmacy $pharmacy,
        ?Medicine $medicine = null,
        ?MohMedicine $moh = null,
        float $price = 25.00,
        int $qty = 50,
        bool $available = true
    ): PharmacyMedicine {
        return PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine?->id,
            'moh_medicine_id' => $moh?->id,
            'price' => $price,
            'quantity' => $qty,
            'is_available' => $available,
        ]);
    }

    public function test_returns_pharmacy_stock_row_with_server_price_and_quantity(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $moh = $this->mohWithBarcode();
        $medicine = Medicine::factory()->create(['trade_name' => 'بنادول']);

        // اربط الباركود بالدواء المحلي أيضًا
        MedicineBarcode::where('barcode', '6291041500213')->update(['local_medicine_id' => $medicine->id]);

        $pm = $this->stockRow($pharmacy, $medicine, $moh, price: 27.50, qty: 12);

        Sanctum::actingAs($user);

        $this->getJson('/api/pharmacy/accounting/barcode/6291041500213')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'found')
            ->assertJsonPath('data.pharmacy_medicine_id', $pm->id)
            ->assertJsonPath('data.local_medicine_id', $medicine->id)
            ->assertJsonPath('data.price', 27.5)
            ->assertJsonPath('data.quantity', 12)
            ->assertJsonPath('data.is_available', true)
            ->assertJsonPath('data.is_out_of_stock', false)
            ->assertJsonPath('data.medicine.trade_name', 'بنادول');
    }

    public function test_matches_by_moh_medicine_id_when_local_id_is_absent(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $moh = $this->mohWithBarcode('1112223334445');

        // الباركود في الكتالوج بلا ربط محلي (local_medicine_id = null) —
        // الوضع الشائع لباركودات MOH. المطابقة تحدث عبر moh_medicine_id.
        $this->assertNull(
            MedicineBarcode::where('barcode', '1112223334445')->value('local_medicine_id')
        );

        $medicine = Medicine::factory()->create(['trade_name' => 'منتج محلي']);
        $pm = $this->stockRow($pharmacy, $medicine, $moh, price: 9.25, qty: 3);

        Sanctum::actingAs($user);

        $this->getJson('/api/pharmacy/accounting/barcode/1112223334445')
            ->assertOk()
            ->assertJsonPath('data.status', 'found')
            ->assertJsonPath('data.pharmacy_medicine_id', $pm->id)
            ->assertJsonPath('data.local_medicine_id', $medicine->id)
            ->assertJsonPath('data.price', 9.25);
    }

    public function test_other_pharmacy_stock_is_never_returned(): void
    {
        [$userA, $pharmacyA] = $this->pharmacyUser();
        [, $pharmacyB] = $this->pharmacyUser();

        $moh = $this->mohWithBarcode('5556667778889');
        $medicine = Medicine::factory()->create(['trade_name' => 'دواء الصيدلية ب']);
        MedicineBarcode::where('barcode', '5556667778889')->update(['local_medicine_id' => $medicine->id]);

        // المخزون عند صيدلية أخرى فقط
        $this->stockRow($pharmacyB, $medicine, $moh, price: 15.00, qty: 99);

        Sanctum::actingAs($userA);

        $this->getJson('/api/pharmacy/accounting/barcode/5556667778889')
            ->assertOk()
            ->assertJsonPath('data.status', 'not_in_stock')
            ->assertJsonPath('data.pharmacy_medicine_id', null)
            ->assertJsonPath('data.price', null)
            ->assertJsonPath('data.quantity', null)
            ->assertJsonPath('data.candidates', []);
    }

    public function test_unknown_barcode_returns_200_with_unknown_status(): void
    {
        [$user] = $this->pharmacyUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/pharmacy/accounting/barcode/9998887776665')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'unknown')
            ->assertJsonPath('data.medicine', null)
            ->assertJsonPath('data.pharmacy_medicine_id', null);
    }

    public function test_out_of_stock_row_is_reported_not_hidden(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $moh = $this->mohWithBarcode('1231231231231');
        $medicine = Medicine::factory()->create();
        MedicineBarcode::where('barcode', '1231231231231')->update(['local_medicine_id' => $medicine->id]);

        $pm = $this->stockRow($pharmacy, $medicine, $moh, qty: 0, available: false);

        Sanctum::actingAs($user);

        $this->getJson('/api/pharmacy/accounting/barcode/1231231231231')
            ->assertOk()
            ->assertJsonPath('data.status', 'out_of_stock')
            ->assertJsonPath('data.pharmacy_medicine_id', $pm->id)
            ->assertJsonPath('data.is_out_of_stock', true)
            ->assertJsonPath('data.quantity', 0);
    }

    public function test_low_stock_flag_is_reported(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $moh = $this->mohWithBarcode('3213213213213');
        $medicine = Medicine::factory()->create();
        MedicineBarcode::where('barcode', '3213213213213')->update(['local_medicine_id' => $medicine->id]);

        $this->stockRow($pharmacy, $medicine, $moh, qty: 2);

        Sanctum::actingAs($user);

        $this->getJson('/api/pharmacy/accounting/barcode/3213213213213')
            ->assertOk()
            ->assertJsonPath('data.status', 'found')
            ->assertJsonPath('data.is_low_stock', true);
    }

    public function test_duplicate_stock_rows_are_reported_as_ambiguous_not_guessed(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $moh = $this->mohWithBarcode('7417417417417');

        // الباركود يخص منتج MOH بلا ربط محلي ⇒ المطابقة عبر moh_medicine_id.
        // صيدليتان… لا: صيدلية واحدة تبيع منتجين محليين مختلفين لنفس دواء MOH.
        // هذه حالة واقعية (بديلان) وليست خلل بيانات — لذلك لا نخمّن.
        $first = $this->stockRow($pharmacy, Medicine::factory()->create(), $moh, price: 10.00, qty: 5);
        $second = $this->stockRow($pharmacy, Medicine::factory()->create(), $moh, price: 12.00, qty: 7);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/pharmacy/accounting/barcode/7417417417417')
            ->assertOk()
            ->assertJsonPath('data.status', 'ambiguous')
            ->assertJsonPath('data.pharmacy_medicine_id', null);

        $ids = array_column($response->json('data.candidates'), 'pharmacy_medicine_id');
        sort($ids);
        $expected = [$first->id, $second->id];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function test_invalid_barcode_format_is_rejected_with_422(): void
    {
        [$user] = $this->pharmacyUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/pharmacy/accounting/barcode/abc%20def')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_barcode_with_separators_is_normalized_and_found(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        $moh = $this->mohWithBarcode('6291041500213');
        $medicine = Medicine::factory()->create(['trade_name' => 'بنادول']);
        MedicineBarcode::where('barcode', '6291041500213')->update(['local_medicine_id' => $medicine->id]);

        $pm = $this->stockRow($pharmacy, $medicine, $moh, price: 18.00, qty: 4);

        Sanctum::actingAs($user);

        // الفواصل تُزال بواسطة BarcodeNormalizer (سلوك موجود مسبقًا) ⇒
        // نفس رقم الدواء يُطبَّع إلى `6291041500213` ويجد سطر المخزون.
        $this->getJson('/api/pharmacy/accounting/barcode/629-1041-500-213')
            ->assertOk()
            ->assertJsonPath('data.status', 'found')
            ->assertJsonPath('data.barcode', '6291041500213')
            ->assertJsonPath('data.pharmacy_medicine_id', $pm->id);
    }

    public function test_non_numeric_barcode_is_rejected_with_422(): void
    {
        [$user] = $this->pharmacyUser();
        Sanctum::actingAs($user);

        // حروف فقط ⇒ BarcodeNormalizer::normalize يعيد null.
        $this->getJson('/api/pharmacy/accounting/barcode/abc')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_unsupported_length_barcode_is_rejected_with_422(): void
    {
        [$user] = $this->pharmacyUser();
        Sanctum::actingAs($user);

        // 3 أرقام: طول غير مدعوم (المدعوم 8·12·13·14 فقط).
        $this->getJson('/api/pharmacy/accounting/barcode/123')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_patient_cannot_use_pharmacy_barcode_lookup(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);
        Sanctum::actingAs($patient);

        $this->getJson('/api/pharmacy/accounting/barcode/6291041500213')
            ->assertStatus(403);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/pharmacy/accounting/barcode/6291041500213')
            ->assertStatus(401);
    }
}
