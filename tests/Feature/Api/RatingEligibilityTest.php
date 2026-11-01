<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\Rating;
use App\Models\SubOrder;
use App\Models\User;
use Tests\Feature\Api\Concerns\BuildsDeliveryOrders;
use Tests\TestCase;

class RatingEligibilityTest extends TestCase
{
    use BuildsDeliveryOrders;

    public function test_only_owner_of_completed_parent_and_pharmacy_slice_can_rate(): void
    {
        $this->bootDeliveryWorld();
        $order = $this->checkoutTwoPharmacy();
        $this->postJson('/api/ratings', ['pharmacy_id' => $this->pharmacyA->id, 'stars_rating' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors('pharmacy_id');
        // Completing just one pharmacy must not expose ratings early.
        $a = $this->subOrderFor($order, $this->pharmacyA);
        $a->update(['status' => SubOrder::STATUS_DELIVERED]);
        $this->postJson('/api/ratings', ['pharmacy_id' => $this->pharmacyA->id, 'stars_rating' => 5])
            ->assertUnprocessable();
        $order->update(['status' => Order::STATUS_DELIVERED, 'delivered_at' => now()]);
        // A corrupt/partially fulfilled parent is not enough to grant eligibility.
        $this->postJson('/api/ratings', ['pharmacy_id' => $this->pharmacyA->id, 'stars_rating' => 5])
            ->assertUnprocessable();
        $this->actingAs(User::factory()->patient()->create(), 'sanctum');
        $this->postJson('/api/ratings', ['pharmacy_id' => $this->pharmacyA->id, 'stars_rating' => 5])
            ->assertUnprocessable();
        $this->actingAs($this->patient, 'sanctum');
        $this->postJson('/api/ratings', ['pharmacy_id' => $this->pharmacyB->id, 'stars_rating' => 5])
            ->assertUnprocessable();
        $this->postJson('/api/ratings', ['pharmacy_id' => Pharmacy::factory()->create()->id, 'stars_rating' => 5])
            ->assertUnprocessable();
        $this->subOrderFor($order, $this->pharmacyB)->update(['status' => SubOrder::STATUS_DELIVERED]);
        foreach ([$this->pharmacyA, $this->pharmacyB] as $pharmacy) {
            $this->postJson('/api/ratings', ['pharmacy_id' => $pharmacy->id, 'stars_rating' => 5])->assertCreated();
            $this->postJson('/api/ratings', ['pharmacy_id' => $pharmacy->id, 'stars_rating' => 1])->assertUnprocessable();
        }
        $this->assertDatabaseCount('ratings', 2);
    }

    public function test_cancelled_slice_does_not_qualify_and_non_patient_cannot_rate(): void
    {
        $this->bootDeliveryWorld();
        $order = $this->checkoutTwoPharmacy();
        $order->update(['status' => Order::STATUS_DELIVERED]);
        $this->subOrderFor($order, $this->pharmacyA)->update(['status' => SubOrder::STATUS_CANCELLED]);
        $this->postJson('/api/ratings', ['pharmacy_id' => $this->pharmacyA->id, 'stars_rating' => 5])->assertUnprocessable();
        $this->actingAs($this->admin, 'sanctum');
        $this->postJson('/api/ratings', ['pharmacy_id' => $this->pharmacyA->id, 'stars_rating' => 5])->assertForbidden();
    }

    public function test_legacy_delivered_order_qualifies_without_creating_a_new_rating_rule(): void
    {
        $patient = User::factory()->patient()->create();
        $pharmacy = Pharmacy::factory()->create();
        Order::create(['user_id' => $patient->id, 'pharmacy_id' => $pharmacy->id,
            'subtotal' => 10, 'discount' => 0, 'total' => 10, 'status' => Order::STATUS_DELIVERED]);
        $this->actingAs($patient, 'sanctum');
        $this->postJson('/api/ratings', ['pharmacy_id' => $pharmacy->id, 'stars_rating' => 4])->assertCreated();
    }

    public function test_average_tracks_create_edit_delete_and_public_read(): void
    {
        $pharmacy = Pharmacy::factory()->create();
        $patient = User::factory()->patient()->create();
        $other = User::factory()->patient()->create();
        Rating::create(['user_id' => $other->id, 'pharmacy_id' => $pharmacy->id, 'stars_rating' => 3, 'created_at' => now()]);
        $rating = Rating::create(['user_id' => $patient->id, 'pharmacy_id' => $pharmacy->id, 'stars_rating' => 5, 'created_at' => now()]);
        $this->getJson("/api/pharmacies/{$pharmacy->id}")->assertOk()->assertJsonPath('data.avg_rating', 4);
        $this->actingAs($patient, 'sanctum');
        $this->putJson("/api/ratings/{$rating->id}", ['stars_rating' => 1])->assertOk();
        $this->getJson("/api/pharmacies/{$pharmacy->id}")->assertOk()->assertJsonPath('data.avg_rating', 2);
        $this->deleteJson("/api/ratings/{$rating->id}")->assertOk();
        $this->getJson("/api/pharmacies/{$pharmacy->id}")->assertOk()->assertJsonPath('data.avg_rating', 3);
        Rating::where('user_id', $other->id)->firstOrFail()->delete();
        $this->assertSame(0.0, (float) $pharmacy->fresh()->avg_rating);
    }
}
