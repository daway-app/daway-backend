<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\Notification;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * C7: منع إشعارات low-stock المكررة.
 * عندما تُحدّث الكمية بحيث تبقى في نطاق low-stock مرتين متتاليتين،
 * يجب إنشاء إشعار واحد فقط (الأول) وتُجدّد created_at للباقي.
 */
class LowStockNotificationDedupTest extends TestCase
{
    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    public function test_repeated_low_stock_updates_create_single_notification(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();

        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 5,  // <= LOW_STOCK_THRESHOLD (10)
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        // 3 تحديثات متتالية تبقى في نطاق low-stock
        $this->putJson("/api/pharmacy/inventory/{$pm->id}", ['quantity' => 7])->assertOk();
        $this->putJson("/api/pharmacy/inventory/{$pm->id}", ['quantity' => 8])->assertOk();
        $this->putJson("/api/pharmacy/inventory/{$pm->id}", ['quantity' => 6])->assertOk();

        // C7: يجب أن يوجد إشعار واحد فقط (لأن كلها low_stock لنفس user+medicine).
        $this->assertSame(1, Notification::where('user_id', $user->id)
            ->where('medicine_id', $medicine->id)
            ->where('type', 'low_stock')
            ->count());
    }

    public function test_out_of_stock_then_back_to_low_stock_creates_two_separate_notifications(): void
    {
        // C7: التحول بين out_of_stock و low_stock يُنشئ إشعارين منفصلين (نوع مختلف).
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $medicine = Medicine::factory()->create();

        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => 0,  // out_of_stock
            'is_available' => true,
        ]);

        Sanctum::actingAs($user);

        $this->putJson("/api/pharmacy/inventory/{$pm->id}", ['quantity' => 0])->assertOk();
        $this->putJson("/api/pharmacy/inventory/{$pm->id}", ['quantity' => 3])->assertOk();

        $this->assertSame(1, Notification::where('user_id', $user->id)
            ->where('type', 'out_of_stock')
            ->count());
        $this->assertSame(1, Notification::where('user_id', $user->id)
            ->where('type', 'low_stock')
            ->count());
    }
}
