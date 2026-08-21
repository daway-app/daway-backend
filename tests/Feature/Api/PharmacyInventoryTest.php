<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Tests\TestCase;

class PharmacyInventoryTest extends TestCase
{
    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    public function test_pharmacy_can_add_medicine_to_inventory(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();

        $this->actingAs($user);

        $response = $this->post(route('pharmacy.medicines.store'), [
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
            'is_available' => 1,
        ]);

        $response->assertRedirect(route('pharmacy.medicines.index'));

        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5.00,
            'quantity' => 10,
            'is_available' => 1,
        ]);
    }

    public function test_pharmacy_cannot_add_duplicate_medicine(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();

        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 10,
            'is_available' => true,
        ]);

        $this->actingAs($user);

        $this->post(route('pharmacy.medicines.store'), [
            'medicine_id' => $medicine->id,
            'price' => 4,
            'quantity' => 3,
            'is_available' => 1,
        ])->assertRedirect()
            ->assertSessionHasErrors('medicine_id');

        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5.00,
            'quantity' => 10,
        ]);
    }

    public function test_pharmacy_can_update_inventory(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $pharmacyMedicine = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
            'quantity' => 5,
            'price' => 8,
        ]);

        $this->actingAs($user);

        $this->put(route('pharmacy.medicines.update', $pharmacyMedicine), [
            'price' => 9,
            'quantity' => 25,
            'is_available' => 1,
        ])->assertRedirect(route('pharmacy.medicines.index'));

        $this->assertDatabaseHas('pharmacy_medicines', [
            'id' => $pharmacyMedicine->id,
            'price' => 9.00,
            'quantity' => 25,
            'is_available' => 1,
        ]);
    }

    public function test_pharmacy_can_delete_inventory(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $pharmacyMedicine = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacy->id,
        ]);

        $this->actingAs($user);

        $this->delete(route('pharmacy.medicines.destroy', $pharmacyMedicine))
            ->assertRedirect(route('pharmacy.medicines.index'));

        $this->assertDatabaseMissing('pharmacy_medicines', [
            'id' => $pharmacyMedicine->id,
        ]);
    }

    public function test_pharmacy_cannot_modify_another_pharmacys_inventory(): void
    {
        [, $pharmacyA] = $this->pharmacyUserWithPharmacy();
        [$userB, $pharmacyB] = $this->pharmacyUserWithPharmacy();

        $this->assertNotSame($pharmacyA->id, $pharmacyB->id);

        $pharmacyMedicine = PharmacyMedicine::factory()->create([
            'pharmacy_id' => $pharmacyA->id,
            'quantity' => 7,
            'price' => 3,
        ]);

        $this->actingAs($userB);

        $this->put(route('pharmacy.medicines.update', $pharmacyMedicine), [
            'price' => 1,
            'quantity' => 99,
            'is_available' => 1,
        ])->assertRedirect()
            ->assertSessionHas('error');

        $this->delete(route('pharmacy.medicines.destroy', $pharmacyMedicine))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('pharmacy_medicines', [
            'id' => $pharmacyMedicine->id,
            'quantity' => 7,
            'price' => 3.00,
        ]);
    }
}