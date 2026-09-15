<?php

namespace Tests\Feature\Api\Patient;

use App\Models\MohMedicine;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * نقطة «أين أجده؟» من الأقسام: GET /api/moh-medicines/{id}/pharmacies
 * ترتيب الصيدليات من الأقرب حسب موقع المستخدم (Haversine) —
 * والحقول عقد SRS نفسه (price, availability_status, distance_km, ...)
 */
final class MohPharmaciesApiTest extends TestCase
{
    use RefreshDatabase;

    private MohMedicine $moh;

    private Medicine $local;

    private const USER_LAT = 32.0000;

    private const USER_LNG = 34.9000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->moh = MohMedicine::create([
            'trade_name' => 'PANADOL ADVANCE',
            'generic_name' => 'Paracetamol',
            'moh_product_id' => 97701,
        ]);
        $this->local = Medicine::create([
            'trade_name' => 'PANADOL ADVANCE',
            'active_ingredient' => 'Paracetamol',
            'is_available' => true,
        ]);
    }

    private function pharmacyWithStock(string $name, float $lat, float $lng): Pharmacy
    {
        $pharmacy = Pharmacy::factory()->create([
            'pharmacy_name' => $name,
            'latitude' => $lat,
            'longitude' => $lng,
            'is_active' => true,
        ]);

        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $this->local->id,
            'price' => 10,
            'quantity' => 10,
            'is_available' => true,
        ]);

        return $pharmacy;
    }

    public function test_returns_pharmacies_sorted_by_distance(): void
    {
        // صيدليات بتنازلاتمسافات مختلفة — يجب أن ترتب الأقرب أولاً
        $this->pharmacyWithStock('فار Video', self::USER_LAT + 0.10, self::USER_LNG + 0.10);
        $this->pharmacyWithStock('قريب أول', self::USER_LAT + 0.0001, self::USER_LNG + 0.0001);
        $this->pharmacyWithStock('قريب ثان', self::USER_LAT + 0.005, self::USER_LNG + 0.005);

        $response = $this->getJson(
            "/api/moh-medicines/{$this->moh->id}/pharmacies?latitude=".self::USER_LAT.'&longitude='.self::USER_LNG.'&radius_km=50'
        )->assertOk();

        $this->assertSame($this->moh->id, $response->json('data.moh_medicine.id'));
        $this->assertSame($this->local->id, $response->json('data.medicine_id'));

        $rows = $response->json('data.pharmacies');
        $this->assertCount(3, $rows);

        // كل مسافة أقل أو تساوي التالية (مرتبة صعودياً)
        $values = array_column($rows, 'distance_km');
        $sorted = $values;
        sort($sorted);
        $this->assertSame($sorted, $values);

        // الصيدلية القريبة الأولى
        $this->assertSame('قريب أول', $rows[0]['name']);
        $this->assertFalse($response->json('data.requires_location', false));
    }

    public function test_radius_filter_removes_far_pharmacies(): void
    {
        $this->pharmacyWithStock('قريب', self::USER_LAT + 0.001, self::USER_LNG + 0.001);   //~0.15 km
        $this->pharmacyWithStock('بعيد جدا', self::USER_LAT + 0.5,  self::USER_LNG + 0.5);  //~75 km

        $response = $this->getJson(
            "/api/moh-medicines/{$this->moh->id}/pharmacies?latitude=".self::USER_LAT.'&longitude='.self::USER_LNG.'&radius_km=20'
        )->assertOk();

        $names = array_column($response->json('data.pharmacies'), 'name');
        $this->assertContains('قريب', $names);
        $this->assertNotContains('بعيد جدا', $names);
    }

    public function test_no_local_match_uses_fallback_with_medicine_id_null(): void
    {
        $mohNoLocal = MohMedicine::create([
            'trade_name' => 'NO LOCAL MATCH MEDICINE',
            'moh_product_id' => 99000,
        ]);

        $this->getJson("/api/moh-medicines/{$mohNoLocal->id}/pharmacies")
            ->assertOk()
            ->assertJsonPath('data.medicine_id', null);
    }

    public function test_unknown_moh_medicine_returns_404(): void
    {
        $this->getJson('/api/moh-medicines/999999/pharmacies')
            ->assertStatus(404);
    }

    public function test_without_location_returns_open_list_unsorted(): void
    {
        $this->pharmacyWithStock('A', 32.01, 34.91);
        $this->pharmacyWithStock('B', 31.90, 34.80);

        $response = $this->getJson("/api/moh-medicines/{$this->moh->id}/pharmacies")
            ->assertOk();

        // بلا موقع لا مسافة ولا ترتيب جغرافي — نجاح مع قائمة كاملة
        $this->assertCount(2, $response->json('data.pharmacies'));
    }
}
