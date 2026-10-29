<?php

namespace Tests\Feature\Api\Pharmacy;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * بديل API لـ`tests/Feature/Web/PharmacyMinStockTest.php`.
 *
 * الاختبار الأصلي يستخدم `route('pharmacy.medicines.store')` فيموت مع حذف
 * مسارات الويب — لكنه يحمي **سلوكًا مقصودًا** في العقد: `min_stock` لم يعد
 * قابلًا للضبط، والحدّ ثابت (`PharmacyMedicine::LOW_STOCK_THRESHOLD` = 10)،
 * وإشعارات `low_stock`/`out_of_stock` تُنشأ عند الإضافة.
 *
 * المسار الحدثي نفسه يخدم الويب وAPI: كلاهما ينادي
 * `LowStockNotifier::notifyIfLowStock()` بعد الحفظ، فالتغطية هنا مكافئة.
 */
final class MinStockApiBehaviorTest extends TestCase
{
    /** @return array{0:User,1:Pharmacy} */
    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    private function addMedicine(Pharmacy $pharmacy, Medicine $medicine, int $quantity, array $extra = []): void
    {
        $response = $this->postJson('/api/pharmacy/medicines', array_merge([
            'medicine_id' => $medicine->id,
            'price' => 5,
            'quantity' => $quantity,
            'is_available' => 1,
        ], $extra));

        $response->assertCreated();
    }

    public function test_min_stock_is_ignored_and_not_stored(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        Sanctum::actingAs($user);
        $medicine = Medicine::factory()->create();

        // العميل يرسل min_stock — يجب أن يُتجاهل تمامًا (الحدّ ثابت 10).
        $this->addMedicine($pharmacy, $medicine, 50, ['min_stock' => 5]);

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

    public function test_low_stock_notification_below_fixed_threshold(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        Sanctum::actingAs($user);
        $medicine = Medicine::factory()->create();

        // 3 ≤ 10 ⇒ low_stock (وليس نفادًا).
        $this->addMedicine($pharmacy, $medicine, 3);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'medicine_id' => $medicine->id,
            'type' => 'low_stock',
        ]);
    }

    public function test_out_of_stock_notification_at_zero_quantity(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        Sanctum::actingAs($user);
        $medicine = Medicine::factory()->create();

        $this->addMedicine($pharmacy, $medicine, 0);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'medicine_id' => $medicine->id,
            'type' => 'out_of_stock',
        ]);
    }

    public function test_no_notification_above_fixed_threshold(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        Sanctum::actingAs($user);
        $medicine = Medicine::factory()->create();

        // 50 > 10 ⇒ لا إشعار.
        $this->addMedicine($pharmacy, $medicine, 50);

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
