<?php

namespace Tests\Feature\Api\Patient;

use App\Models\Favorite;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
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
}
