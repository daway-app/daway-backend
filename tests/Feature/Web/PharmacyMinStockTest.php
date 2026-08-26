<?php

namespace Tests\Feature\Web;

use App\Models\Medicine;
use App\Models\Notification;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Tests\TestCase;

class PharmacyMinStockTest extends TestCase
{
    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    public function test_min_stock_is_no_longer_settable_and_uses_fixed_threshold(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();

        $this->actingAs($user);

        $response = $this->post(route('pharmacy.medicines.store'), [
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 50,
            'min_stock' => 5,
            'is_available' => 1,
        ]);

        $response->assertRedirect(route('pharmacy.medicines.index'));

        // الحد الأدنى أصبح ثابتاً (10) — القيمة المرسلة تُتجاهل ولا تُخزن
        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5.00,
            'quantity' => 50,
            'is_available' => 1,
        ]);
        $this->assertDatabaseMissing('pharmacy_medicines', [
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'min_stock' => 5,
        ]);
    }

    public function test_low_stock_notification_created_when_quantity_below_fixed_threshold(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();

        $this->actingAs($user);

        $this->post(route('pharmacy.medicines.store'), [
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 3,
            'is_available' => 1,
        ])->assertRedirect(route('pharmacy.medicines.index'));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'medicine_id' => $medicine->id,
            'type' => 'low_stock',
        ]);
    }

    public function test_out_of_stock_notification(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();

        $this->actingAs($user);

        $this->post(route('pharmacy.medicines.store'), [
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 0,
            'is_available' => 1,
        ])->assertRedirect(route('pharmacy.medicines.index'));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'medicine_id' => $medicine->id,
            'type' => 'out_of_stock',
        ]);
    }

    public function test_no_notification_when_quantity_above_fixed_threshold(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();

        $this->actingAs($user);

        $this->post(route('pharmacy.medicines.store'), [
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 50,
            'is_available' => 1,
        ])->assertRedirect(route('pharmacy.medicines.index'));

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $user->id,
            'medicine_id' => $medicine->id,
            'type' => 'low_stock',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $user->id,
            'medicine_id' => $medicine->id,
            'type' => 'out_of_stock',
        ]);
    }
}
