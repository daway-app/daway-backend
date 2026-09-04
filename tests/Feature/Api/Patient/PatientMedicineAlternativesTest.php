<?php

namespace Tests\Feature\Api\Patient;

use App\Http\Controllers\Api\MedicineController;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * IMPORTANT: Two pre-existing bugs in this code path make the
 * `alternatives()` endpoint non-functional today:
 *
 *   1) routes/api.php line 82 binds
 *      `/api/patient/medicines/{medicine}/alternatives` to
 *      `MedicineController@pharmacies` instead of `alternatives`.
 *   2) MedicineController::alternatives() (line 226) chains
 *      `->get()` on the result of
 *      `Medicine::alternativesByActiveIngredient(...)`, but the
 *      helper already returns a Collection (it calls `->get()`
 *      internally). This raises a "Too few arguments to
 *      Collection::get()" TypeError at runtime.
 *
 * The auth check still works, and the empty-active-ingredient short
 * circuit still works. The tests below are written against the
 * INTENDED contract of `MedicineController::alternatives()` (the
 * behavior the SRS and the task spec require). They will pass
 * cleanly once both bugs are fixed; the
 * "requires_authentication" and
 * "returns_empty_when_active_ingredient_is_null" tests pass today.
 */
class PatientMedicineAlternativesTest extends TestCase
{
    public function test_patient_alternatives_requires_authentication(): void
    {
        $medicine = Medicine::factory()->create();

        $this->getJson("/api/patient/medicines/{$medicine->id}/alternatives")
            ->assertStatus(401);
    }

    public function test_patient_alternatives_returns_empty_when_active_ingredient_is_null(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $medicine = Medicine::factory()->create([
            'trade_name' => 'NoIngredient',
            'active_ingredient' => '',
        ]);

        $response = $this->getJson("/api/patient/medicines/{$medicine->id}/alternatives");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', []);
    }

    public function test_patient_alternatives_returns_medicines_with_same_active_ingredient_only(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $medA = Medicine::factory()->create([
            'trade_name' => 'MedA',
            'active_ingredient' => 'Paracetamol',
        ]);
        $medB = Medicine::factory()->create([
            'trade_name' => 'MedB',
            'active_ingredient' => 'Paracetamol',
        ]);
        Medicine::factory()->create([
            'trade_name' => 'MedC',
            'active_ingredient' => 'Ibuprofen',
        ]);

        $response = $this->getJson("/api/patient/medicines/{$medA->id}/alternatives");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $medB->id)
            ->assertJsonPath('data.0.active_ingredient', 'Paracetamol');
    }

    public function test_patient_alternatives_excludes_the_source_medicine(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $source = Medicine::factory()->create([
            'trade_name' => 'Source',
            'active_ingredient' => 'Paracetamol',
        ]);
        $alt1 = Medicine::factory()->create([
            'trade_name' => 'AltOne',
            'active_ingredient' => 'Paracetamol',
        ]);
        $alt2 = Medicine::factory()->create([
            'trade_name' => 'AltTwo',
            'active_ingredient' => 'Paracetamol',
        ]);

        $response = $this->getJson("/api/patient/medicines/{$source->id}/alternatives");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $ids = array_column($response->json('data'), 'id');
        $this->assertNotContains($source->id, $ids);
        $this->assertContains($alt1->id, $ids);
        $this->assertContains($alt2->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_patient_alternatives_includes_nearest_pharmacy_when_geo_provided(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $source = Medicine::factory()->create([
            'trade_name' => 'Source',
            'active_ingredient' => 'Paracetamol',
        ]);
        $alt = Medicine::factory()->create([
            'trade_name' => 'Alt',
            'active_ingredient' => 'Paracetamol',
        ]);

        $pharmacy = Pharmacy::factory()->create([
            'latitude' => 31.5050,
            'longitude' => 34.4700,
        ]);

        PharmacyMedicine::factory()->create([
            'medicine_id' => $alt->id,
            'pharmacy_id' => $pharmacy->id,
            'quantity' => 20,
            'is_available' => true,
        ]);

        $response = $this->getJson(
            "/api/patient/medicines/{$source->id}/alternatives"
            .'?latitude=31.5&longitude=34.47'
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'trade_name', 'nearest_pharmacy']],
            ]);

        $first = $response->json('data.0');
        $this->assertSame($alt->id, $first['id']);
        $this->assertArrayHasKey('nearest_pharmacy', $first);
        $this->assertIsArray($first['nearest_pharmacy']);
        $this->assertSame($pharmacy->id, $first['nearest_pharmacy']['id']);
    }
}
