<?php

namespace Tests\Feature\Api\Patient;

use App\Models\Favorite;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\Rating;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FavoriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_favorites_requires_authentication(): void
    {
        $this->getJson('/api/patient/favorites/medicines')->assertStatus(401);
        $this->postJson('/api/patient/favorites/medicines/1')->assertStatus(401);
        $this->deleteJson('/api/patient/favorites/medicines/1')->assertStatus(401);
    }

    public function test_favorites_rejects_non_patient_users(): void
    {
        $pharmacyUser = User::factory()->pharmacy()->create();
        Sanctum::actingAs($pharmacyUser);

        $this->getJson('/api/patient/favorites/medicines')->assertStatus(403);
    }

    public function test_add_medicine_to_favorites_succeeds_and_prevents_duplicate(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();
        Sanctum::actingAs($patient);

        $first = $this->postJson("/api/patient/favorites/medicines/{$medicine->id}");
        $first->assertStatus(201);
        $this->assertDatabaseHas('favorites', [
            'user_id' => $patient->id,
            'favoritable_id' => $medicine->id,
            'favoritable_type' => Medicine::class,
        ]);
        $this->assertSame(1, Favorite::count());

        $second = $this->postJson("/api/patient/favorites/medicines/{$medicine->id}");
        $second->assertStatus(200);
        $this->assertSame(1, Favorite::count(), 'Duplicate POST must not create a second row.');
    }

    public function test_remove_medicine_from_favorites_succeeds(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();
        Sanctum::actingAs($patient);

        $this->postJson("/api/patient/favorites/medicines/{$medicine->id}")->assertStatus(201);
        $this->assertSame(1, Favorite::count());

        $this->deleteJson("/api/patient/favorites/medicines/{$medicine->id}")->assertOk();
        $this->assertSame(0, Favorite::count());
    }

    public function test_favorite_isolation_between_patients_idor(): void
    {
        $patientA = User::factory()->patient()->create();
        $patientB = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();

        Sanctum::actingAs($patientA);
        $this->postJson("/api/patient/favorites/medicines/{$medicine->id}")->assertStatus(201);

        Sanctum::actingAs($patientB);
        $this->deleteJson("/api/patient/favorites/medicines/{$medicine->id}")->assertOk();

        $this->assertSame(1, Favorite::where('user_id', $patientA->id)->count(),
            'Patient A\'s favorite must remain intact.');
    }

    public function test_list_medicine_favorites_returns_only_my_favorites(): void
    {
        $patientA = User::factory()->patient()->create();
        $patientB = User::factory()->patient()->create();
        $m1 = Medicine::factory()->create();
        $m2 = Medicine::factory()->create();
        $m3 = Medicine::factory()->create();

        Sanctum::actingAs($patientA);
        $this->postJson("/api/patient/favorites/medicines/{$m1->id}")->assertStatus(201);
        $this->postJson("/api/patient/favorites/medicines/{$m2->id}")->assertStatus(201);

        Sanctum::actingAs($patientB);
        $this->postJson("/api/patient/favorites/medicines/{$m3->id}")->assertStatus(201);

        Sanctum::actingAs($patientA);
        $aIds = $this->getJson('/api/patient/favorites/medicines')
            ->assertOk()
            ->json('data.*.favoritable_id');
        $this->assertEqualsCanonicalizing([$m1->id, $m2->id], $aIds);

        Sanctum::actingAs($patientB);
        $bIds = $this->getJson('/api/patient/favorites/medicines')
            ->assertOk()
            ->json('data.*.favoritable_id');
        $this->assertEquals([$m3->id], $bIds);
    }

    public function test_add_pharmacy_to_favorites_and_list(): void
    {
        $patient = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create();

        Sanctum::actingAs($patient);
        $this->postJson("/api/patient/favorites/pharmacies/{$pharmacy->id}")->assertStatus(201);
        $this->assertSame(1, Favorite::where('favoritable_type', Pharmacy::class)->count());

        $ids = $this->getJson('/api/patient/favorites/pharmacies')
            ->assertOk()
            ->json('data.*.favoritable_id');
        $this->assertEquals([$pharmacy->id], $ids);

        $this->deleteJson("/api/patient/favorites/pharmacies/{$pharmacy->id}")->assertOk();
        $this->assertSame(0, Favorite::count());
    }

    protected function createFavoriteWithMedicine(User $patient, Medicine $medicine): Favorite
    {
        return Favorite::create([
            'user_id' => $patient->id,
            'favoritable_type' => Medicine::class,
            'favoritable_id' => $medicine->id,
            'created_at' => now(),
        ]);
    }

    public function test_favorite_list_returns_enriched_medicine_fields(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create([
            'trade_name' => 'Panadol',
            'trade_name_ar' => 'بانادول',
            'active_ingredient' => 'Paracetamol',
        ]);
        $this->createFavoriteWithMedicine($patient, $medicine);

        Sanctum::actingAs($patient);

        $response = $this->getJson('/api/patient/favorites/medicines');

        $response->assertOk()
            ->assertJsonPath('data.0.medicine_id', $medicine->id)
            ->assertJsonPath('data.0.trade_name', 'Panadol')
            ->assertJsonPath('data.0.trade_name_ar', 'بانادول')
            ->assertJsonPath('data.0.active_ingredient', 'Paracetamol')
            ->assertJsonPath('data.0.is_available', false)
            ->assertJsonPath('data.0.availability_status', 'unavailable')
            ->assertJsonPath('data.0.pharmacies_count', 0)
            ->assertJsonPath('data.0.min_price', null);
    }

    public function test_favorite_list_returns_available_medicine(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create(['active_ingredient' => 'IBUPROFEN']);
        $pharmacy1 = Pharmacy::factory()->create(['is_active' => true]);
        $pharmacy2 = Pharmacy::factory()->create(['is_active' => true]);
        $inactivePharmacy = Pharmacy::factory()->create(['is_active' => false]);

        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy1->id,
            'medicine_id' => $medicine->id,
            'price' => 10.00,
            'quantity' => 5,
            'is_available' => true,
        ]);
        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy2->id,
            'medicine_id' => $medicine->id,
            'price' => 8.00,
            'quantity' => 3,
            'is_available' => true,
        ]);
        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $inactivePharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5.00,
            'quantity' => 10,
            'is_available' => true,
        ]);

        $this->createFavoriteWithMedicine($patient, $medicine);

        Sanctum::actingAs($patient);

        $response = $this->getJson('/api/patient/favorites/medicines');

        $response->assertOk()
            ->assertJsonPath('data.0.is_available', true)
            ->assertJsonPath('data.0.availability_status', 'available')
            ->assertJsonPath('data.0.pharmacies_count', 2)
            ->assertJsonPath('data.0.min_price', 8);
    }

    public function test_favorite_list_unavailable_medicine_shows_correct_status(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();
        $pharmacy = Pharmacy::factory()->create(['is_active' => true]);

        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 10.00,
            'quantity' => 0,
            'is_available' => true,
        ]);

        $this->createFavoriteWithMedicine($patient, $medicine);

        Sanctum::actingAs($patient);

        $response = $this->getJson('/api/patient/favorites/medicines');

        $response->assertOk()
            ->assertJsonPath('data.0.is_available', false)
            ->assertJsonPath('data.0.availability_status', 'unavailable')
            ->assertJsonPath('data.0.pharmacies_count', 0)
            ->assertJsonPath('data.0.min_price', null);
    }

    public function test_favorite_list_excludes_inactive_pharmacies(): void
    {
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();
        $activePharmacy = Pharmacy::factory()->create(['is_active' => true]);
        $inactivePharmacy = Pharmacy::factory()->create(['is_active' => false]);

        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $activePharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5.00,
            'quantity' => 5,
            'is_available' => true,
        ]);
        PharmacyMedicine::factory()->create([
            'pharmacy_id' => $inactivePharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 1.00,
            'quantity' => 10,
            'is_available' => true,
        ]);

        $this->createFavoriteWithMedicine($patient, $medicine);

        Sanctum::actingAs($patient);

        $response = $this->getJson('/api/patient/favorites/medicines');

        $response->assertOk()
            ->assertJsonPath('data.0.pharmacies_count', 1)
            ->assertJsonPath('data.0.min_price', 5);
    }

    public function test_empty_favorites_list(): void
    {
        $patient = User::factory()->patient()->create();

        Sanctum::actingAs($patient);

        $response = $this->getJson('/api/patient/favorites/medicines');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }

    public function test_favorites_list_returns_only_own_medicine_favorites(): void
    {
        $patientA = User::factory()->patient()->create();
        $patientB = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();

        Sanctum::actingAs($patientA);
        $this->postJson("/api/patient/favorites/medicines/{$medicine->id}")->assertStatus(201);

        Sanctum::actingAs($patientB);
        $response = $this->getJson('/api/patient/favorites/medicines');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_favorite_list_handles_missing_medicine(): void
    {
        $patient = User::factory()->patient()->create();
        Favorite::create([
            'user_id' => $patient->id,
            'favoritable_type' => Medicine::class,
            'favoritable_id' => 999999,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($patient);

        $response = $this->getJson('/api/patient/favorites/medicines');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.medicine_id', null)
            ->assertJsonPath('data.0.trade_name', null);
    }

    public function test_pharmacy_index_includes_user_rating_for_authenticated_user(): void
    {
        $patient = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create(['is_active' => true, 'avg_rating' => 0.00]);

        Rating::create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 5,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($patient);

        $response = $this->getJson('/api/pharmacies');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $pharmacy->id)
            ->assertJsonPath('data.0.user_rating', 5);
    }

    public function test_pharmacy_index_omits_user_rating_without_auth(): void
    {
        $patient = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create(['is_active' => true]);

        Rating::create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 4,
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/pharmacies');

        $response->assertOk()
            ->assertJsonMissing(['user_rating']);
    }
}
