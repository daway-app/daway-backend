<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PriceComparisonTest extends TestCase
{
    use RefreshDatabase;

    private function makeMedicine(string $name = 'TEST MED'): Medicine
    {
        return Medicine::factory()->create(['trade_name' => $name]);
    }

    private function makePharmacy(array $attrs = []): Pharmacy
    {
        return Pharmacy::factory()->create($attrs + ['is_active' => true]);
    }

    private function makePharmacyMedicine(Medicine $med, Pharmacy $ph, array $attrs = []): PharmacyMedicine
    {
        return PharmacyMedicine::factory()->create(array_merge([
            'medicine_id' => $med->id,
            'pharmacy_id' => $ph->id,
            'is_available' => true,
        ], $attrs));
    }

    public function test_medicine_not_found_returns_404(): void
    {
        $this->getJson('/api/medicines/999999/pharmacies')->assertStatus(404);
    }

    public function test_medicine_with_no_pharmacies_returns_empty_data(): void
    {
        $med = $this->makeMedicine();

        $response = $this->getJson("/api/medicines/{$med->id}/pharmacies");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }

    public function test_all_pharmacies_inactive_returns_out_of_stock_status(): void
    {
        $med = $this->makeMedicine();
        $inactivePh1 = $this->makePharmacy(['is_active' => false]);
        $inactivePh2 = $this->makePharmacy(['is_active' => false]);

        $this->makePharmacyMedicine($med, $inactivePh1, ['quantity' => 10, 'price' => 10.00]);
        $this->makePharmacyMedicine($med, $inactivePh2, ['quantity' => 10, 'price' => 10.00]);

        $response = $this->getJson("/api/medicines/{$med->id}/pharmacies");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $statuses = collect($response->json('data'))->pluck('availability_status')->all();
        $this->assertSame(['out_of_stock', 'out_of_stock'], $statuses);
    }

    public function test_multiple_pharmacies_sorted_by_distance(): void
    {
        $med = $this->makeMedicine();

        $near = $this->makePharmacy(['latitude' => 31.5, 'longitude' => 34.47]);
        $far = $this->makePharmacy(['latitude' => 31.3, 'longitude' => 34.2]);

        $this->makePharmacyMedicine($med, $near, ['price' => 15.00, 'quantity' => 10]);
        $this->makePharmacyMedicine($med, $far, ['price' => 20.00, 'quantity' => 10]);

        $response = $this->getJson(
            "/api/medicines/{$med->id}/pharmacies?latitude=31.5&longitude=34.47&radius_km=50"
        );

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.pharmacy_id', $near->id)
            ->assertJsonPath('data.1.pharmacy_id', $far->id);
    }

    public function test_distance_is_null_when_no_coordinates(): void
    {
        $med = $this->makeMedicine();
        $ph = $this->makePharmacy(['latitude' => null, 'longitude' => null]);

        $this->makePharmacyMedicine($med, $ph, ['price' => 10.00, 'quantity' => 5]);

        $response = $this->getJson("/api/medicines/{$med->id}/pharmacies?latitude=31.5&longitude=34.47");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.distance_km', null);
    }

    public function test_distance_is_null_when_pharmacy_has_no_coords(): void
    {
        $med = $this->makeMedicine();
        $ph = $this->makePharmacy(['latitude' => null, 'longitude' => null]);

        $this->makePharmacyMedicine($med, $ph, ['price' => 10.00, 'quantity' => 5]);

        $response = $this->getJson(
            "/api/medicines/{$med->id}/pharmacies?latitude=31.5&longitude=34.47"
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.0.distance_km', null)
            ->assertJsonPath('data.0.latitude', null)
            ->assertJsonPath('data.0.longitude', null);
    }

    public function test_price_comes_from_database_not_client(): void
    {
        $med = $this->makeMedicine();
        $ph = $this->makePharmacy();

        $pm = $this->makePharmacyMedicine($med, $ph, ['price' => 42.50, 'quantity' => 10]);

        $response = $this->getJson("/api/medicines/{$med->id}/pharmacies");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.price', (float) $pm->price);
    }

    public function test_radius_filters_far_pharmacies(): void
    {
        $med = $this->makeMedicine();

        $near = $this->makePharmacy(['latitude' => 31.5, 'longitude' => 34.47, 'pharmacy_name' => 'Near']);
        $far = $this->makePharmacy(['latitude' => 31.3, 'longitude' => 34.2, 'pharmacy_name' => 'Far']);

        $this->makePharmacyMedicine($med, $near, ['price' => 10.00, 'quantity' => 10]);
        $this->makePharmacyMedicine($med, $far, ['price' => 20.00, 'quantity' => 10]);

        $response = $this->getJson(
            "/api/medicines/{$med->id}/pharmacies?latitude=31.5&longitude=34.47&radius_km=5"
        );

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pharmacy_id', $near->id);
    }

    public function test_no_unrelated_medicine_results(): void
    {
        $medA = $this->makeMedicine('MED A');
        $medB = $this->makeMedicine('MED B');

        $ph = $this->makePharmacy();
        $this->makePharmacyMedicine($medA, $ph, ['price' => 10.00, 'quantity' => 10]);
        $this->makePharmacyMedicine($medB, $ph, ['price' => 20.00, 'quantity' => 10]);

        $response = $this->getJson("/api/medicines/{$medA->id}/pharmacies");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pharmacy_id', $ph->id);

        $this->assertCount(
            1,
            collect($response->json('data'))->filter(
                fn ($row) => (int) $row['quantity'] > 0
            )
        );
    }

    public function test_zero_price_is_returned_correctly(): void
    {
        $med = $this->makeMedicine();
        $ph = $this->makePharmacy();

        $pm = $this->makePharmacyMedicine($med, $ph, ['price' => 0.00, 'quantity' => 5]);

        $response = $this->getJson("/api/medicines/{$med->id}/pharmacies");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->assertSame(0.0, (float) $response->json('data.0.price'));
    }

    public function test_low_stock_status_for_quantity_at_or_below_threshold(): void
    {
        $med = $this->makeMedicine();
        $ph = $this->makePharmacy();

        $this->makePharmacyMedicine($med, $ph, ['price' => 10.00, 'quantity' => 5]);

        $response = $this->getJson("/api/medicines/{$med->id}/pharmacies");

        $response->assertStatus(200)
            ->assertJsonPath('data.0.availability_status', 'low_stock');
    }

    public function test_available_status_for_high_quantity(): void
    {
        $med = $this->makeMedicine();
        $ph = $this->makePharmacy();

        $this->makePharmacyMedicine($med, $ph, ['price' => 10.00, 'quantity' => 50]);

        $response = $this->getJson("/api/medicines/{$med->id}/pharmacies");

        $response->assertStatus(200)
            ->assertJsonPath('data.0.availability_status', 'available');
    }

    public function test_patient_availability_requires_auth(): void
    {
        $med = $this->makeMedicine();

        $this->getJson("/api/patient/medicines/{$med->id}/availability")->assertStatus(401);
    }

    public function test_patient_availability_works_with_authenticated_patient(): void
    {
        $patient = User::factory()->patient()->create();
        $med = $this->makeMedicine();
        $ph = $this->makePharmacy();

        $this->makePharmacyMedicine($med, $ph, ['price' => 10.00, 'quantity' => 20]);

        Sanctum::actingAs($patient);

        $this->getJson("/api/patient/medicines/{$med->id}/availability")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_inventory_row_with_is_available_false_is_excluded(): void
    {
        $med = $this->makeMedicine();
        $ph = $this->makePharmacy();

        $this->makePharmacyMedicine($med, $ph, ['price' => 10.00, 'quantity' => 20, 'is_available' => false]);

        $response = $this->getJson("/api/medicines/{$med->id}/pharmacies");

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_quantity_zero_is_excluded(): void
    {
        $med = $this->makeMedicine();
        $ph = $this->makePharmacy();

        $this->makePharmacyMedicine($med, $ph, ['price' => 10.00, 'quantity' => 0]);

        $response = $this->getJson("/api/medicines/{$med->id}/pharmacies");

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_moh_pharmacies_endpoint_uses_correct_moh_medicine(): void
    {
        $moh = \App\Models\MohMedicine::create([
            'trade_name' => 'UNIQUE_MOH_MED_1',
            'moh_product_id' => 99001,
        ]);

        $med = Medicine::factory()->create(['trade_name' => 'UNIQUE_MOH_MED_1']);
        $ph = $this->makePharmacy();

        $pm = PharmacyMedicine::factory()->create([
            'medicine_id' => $med->id,
            'pharmacy_id' => $ph->id,
            'moh_medicine_id' => $moh->id,
            'is_available' => true,
            'quantity' => 10,
            'price' => 25.00,
        ]);

        $response = $this->getJson("/api/moh-medicines/{$moh->id}/pharmacies");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.medicine_id', $med->id)
            ->assertJsonCount(1, 'data.pharmacies')
            ->assertJsonPath('data.pharmacies.0.pharmacy_id', $ph->id);

        $this->assertSame((float) $pm->price, (float) $response->json('data.pharmacies.0.price'));
    }

    public function test_moh_pharmacies_unknown_id_returns_404(): void
    {
        $this->getJson('/api/moh-medicines/999999/pharmacies')->assertStatus(404);
    }
}
