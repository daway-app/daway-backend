<?php

namespace Tests\Feature\Api;

use App\Models\Pharmacy;
use App\Models\Rating;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RatingApiTest extends TestCase
{
    public function test_patient_can_create_rating_and_pharmacy_gets_notification(): void
    {
        $patient = User::factory()->patient()->create();
        $pharmacyUser = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $pharmacyUser->id]);

        Sanctum::actingAs($patient);

        $response = $this->postJson('/api/ratings', [
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 5,
            'comment' => 'ممتاز',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.stars_rating', 5)
            ->assertJsonPath('data.comment', 'ممتاز');

        $this->assertDatabaseHas('ratings', [
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 5,
            'comment' => 'ممتاز',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $pharmacyUser->id,
            'type' => 'new_rating',
        ]);
    }

    public function test_rating_rejects_inactive_pharmacy(): void
    {
        $patient = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->inactive()->create();

        Sanctum::actingAs($patient);

        $this->postJson('/api/ratings', [
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 4,
        ])->assertForbidden();
    }

    public function test_rating_validates_stars_range(): void
    {
        $patient = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create();

        Sanctum::actingAs($patient);

        $this->postJson('/api/ratings', [
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 0,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('stars_rating');

        $this->postJson('/api/ratings', [
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 6,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('stars_rating');
    }

    public function test_can_list_ratings_by_pharmacy(): void
    {
        $patientA = User::factory()->patient()->create();
        $patientB = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create();

        // C2: تقييم واحد لكل (user, pharmacy) — نستخدم patientَين مختلفَين.
        Rating::create([
            'user_id' => $patientA->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 5,
            'created_at' => now(),
        ]);
        Rating::create([
            'user_id' => $patientB->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 4,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($patientA);

        $response = $this->getJson("/api/ratings?pharmacy_id={$pharmacy->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_pharmacy_owner_can_view_own_ratings(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $patientA = User::factory()->patient()->create();
        $patientB = User::factory()->patient()->create();

        // C2: نفس المنطق — patientَين مختلفَين.
        Rating::create([
            'user_id' => $patientA->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 5,
            'created_at' => now(),
        ]);
        Rating::create([
            'user_id' => $patientB->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 3,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/pharmacy/ratings');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_patient_cannot_rate_same_pharmacy_twice(): void
    {
        // C2: تقييم ثاني لنفس (user, pharmacy) يجب أن يُرفض.
        $patient = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create(['is_active' => true]);

        Sanctum::actingAs($patient);

        $this->postJson('/api/ratings', [
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 5,
        ])->assertStatus(201);

        $this->postJson('/api/ratings', [
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 1,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('pharmacy_id');
    }

    public function test_two_different_patients_can_rate_same_pharmacy(): void
    {
        // C2: patientَان مختلفان يستطيعان تقييم نفس الصيدلية.
        $patientA = User::factory()->patient()->create();
        $patientB = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create(['is_active' => true]);

        Sanctum::actingAs($patientA);
        $this->postJson('/api/ratings', [
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 5,
        ])->assertStatus(201);

        Sanctum::actingAs($patientB);
        $this->postJson('/api/ratings', [
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 3,
        ])->assertStatus(201);

        $this->assertSame(2, Rating::where('pharmacy_id', $pharmacy->id)->count());
    }

    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }
}
