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

    public function test_patient_can_view_own_rating(): void
    {
        $patient = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create();
        $rating = Rating::create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 4,
            'comment' => 'Good',
            'created_at' => now(),
        ]);

        Sanctum::actingAs($patient);

        $response = $this->getJson("/api/ratings/{$rating->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.stars_rating', 4);
    }

    public function test_patient_can_update_own_rating(): void
    {
        $patient = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create();
        $rating = Rating::create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 3,
            'comment' => 'Okay',
            'created_at' => now(),
        ]);

        Sanctum::actingAs($patient);

        $response = $this->putJson("/api/ratings/{$rating->id}", [
            'stars_rating' => 5,
            'comment' => 'Excellent',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.stars_rating', 5)
            ->assertJsonPath('data.comment', 'Excellent');

        $this->assertDatabaseHas('ratings', [
            'id' => $rating->id,
            'stars_rating' => 5,
            'comment' => 'Excellent',
        ]);
    }

    public function test_patient_can_delete_own_rating(): void
    {
        $patient = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create();
        $rating = Rating::create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 3,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($patient);

        $this->deleteJson("/api/ratings/{$rating->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('ratings', ['id' => $rating->id]);
    }

    public function test_patient_cannot_view_others_rating(): void
    {
        $patientA = User::factory()->patient()->create();
        $patientB = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create();
        $ratingA = Rating::create([
            'user_id' => $patientA->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 4,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($patientB);

        $this->getJson("/api/ratings/{$ratingA->id}")->assertNotFound();
    }

    public function test_patient_cannot_update_others_rating(): void
    {
        $patientA = User::factory()->patient()->create();
        $patientB = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create();
        $ratingA = Rating::create([
            'user_id' => $patientA->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 4,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($patientB);

        $this->putJson("/api/ratings/{$ratingA->id}", [
            'stars_rating' => 1,
            'comment' => 'Hacked',
        ])->assertNotFound();

        $this->assertDatabaseHas('ratings', [
            'id' => $ratingA->id,
            'stars_rating' => 4,
        ]);
    }

    public function test_patient_cannot_delete_others_rating(): void
    {
        $patientA = User::factory()->patient()->create();
        $patientB = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create();
        $ratingA = Rating::create([
            'user_id' => $patientA->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 4,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($patientB);

        $this->deleteJson("/api/ratings/{$ratingA->id}")->assertNotFound();

        $this->assertDatabaseHas('ratings', ['id' => $ratingA->id]);
    }

    public function test_update_validates_stars_range(): void
    {
        $patient = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create();
        $rating = Rating::create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 3,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($patient);

        $this->putJson("/api/ratings/{$rating->id}", [
            'stars_rating' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors('stars_rating');

        $this->putJson("/api/ratings/{$rating->id}", [
            'stars_rating' => 6,
        ])->assertStatus(422)->assertJsonValidationErrors('stars_rating');
    }

    public function test_rating_requires_authentication(): void
    {
        $pharmacy = Pharmacy::factory()->create();

        $this->postJson('/api/ratings', [
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 5,
        ])->assertUnauthorized();
    }

    public function test_nonexistent_pharmacy_rejected(): void
    {
        $patient = User::factory()->patient()->create();

        Sanctum::actingAs($patient);

        $this->postJson('/api/ratings', [
            'pharmacy_id' => 999999,
            'stars_rating' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors('pharmacy_id');
    }

    public function test_nonexistent_rating_rejected(): void
    {
        $patient = User::factory()->patient()->create();

        Sanctum::actingAs($patient);

        $this->getJson('/api/ratings/999999')->assertNotFound();
        $this->putJson('/api/ratings/999999', ['stars_rating' => 4])->assertNotFound();
        $this->deleteJson('/api/ratings/999999')->assertNotFound();
    }

    public function test_pharmacy_api_returns_user_rating(): void
    {
        $patient = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create([
            'is_active' => true,
            'avg_rating' => 4.00,
        ]);

        Rating::create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 3,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($patient);

        $response = $this->getJson("/api/pharmacies/{$pharmacy->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $pharmacy->id)
            ->assertJsonPath('data.user_rating', 3);
    }

    public function test_pharmacy_api_returns_null_user_rating_without_auth(): void
    {
        $patient = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create([
            'is_active' => true,
            'avg_rating' => 4.00,
        ]);

        Rating::create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 5,
            'created_at' => now(),
        ]);

        $response = $this->getJson("/api/pharmacies/{$pharmacy->id}");

        $response->assertOk()
            ->assertJsonMissing(['user_rating']);
    }

    public function test_pharmacy_api_show_returns_user_rating(): void
    {
        $patient = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create([
            'is_active' => true,
        ]);

        Rating::create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 5,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($patient);

        $response = $this->getJson("/api/pharmacies/{$pharmacy->id}");

        $response->assertOk()
            ->assertJsonPath('data.user_rating', 5);
    }

    public function test_delete_rating_updates_avg_rating(): void
    {
        $patientA = User::factory()->patient()->create();
        $patientB = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create(['is_active' => true]);

        Rating::create([
            'user_id' => $patientA->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 5,
            'created_at' => now(),
        ]);
        $ratingB = Rating::create([
            'user_id' => $patientB->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 3,
            'created_at' => now(),
        ]);

        $pharmacy->forceFill(['avg_rating' => 4.00])->save();

        Sanctum::actingAs($patientB);
        $this->deleteJson("/api/ratings/{$ratingB->id}")->assertOk();

        $pharmacy->refresh();
        $this->assertSame(5.0, (float) $pharmacy->avg_rating);
    }

    public function test_existing_pharmacy_api_still_returns_avg_rating(): void
    {
        $patient = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create([
            'is_active' => true,
        ]);

        Rating::create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 5,
            'created_at' => now(),
        ]);

        $response = $this->getJson("/api/pharmacies/{$pharmacy->id}");

        $response->assertOk()
            ->assertJsonPath('data.avg_rating', 5);
    }

    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }
}
