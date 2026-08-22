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
        $pharmacy = Pharmacy::factory()->create(['user_id' => $pharmacyUser->id, 'is_active' => true]);

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
        $pharmacy = Pharmacy::factory()->create(['is_active' => false]);

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
        $patient = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create();

        Rating::create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 5,
            'created_at' => now(),
        ]);
        Rating::create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 4,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($patient);

        $response = $this->getJson("/api/ratings?pharmacy_id={$pharmacy->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_pharmacy_owner_can_view_own_ratings(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $patient = User::factory()->patient()->create();

        Rating::create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 5,
            'created_at' => now(),
        ]);
        Rating::create([
            'user_id' => $patient->id,
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

    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }
}
