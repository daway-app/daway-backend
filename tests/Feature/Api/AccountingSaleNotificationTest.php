<?php

namespace Tests\Feature\Api;

use App\Contracts\FcmSender;
use App\Models\Medicine;
use App\Models\Notification;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * M-2.5 — إشعارات المخزون بعد **نجاح** عملية البيع المحاسبية.
 *
 * الفجوة التي يعالجها: `AccountingLedger::recordSale` كان يخصم المخزون عبر
 * query builder (**يتجاوز أحداث Eloquent**) ولا يستدعي `LowStockNotifier`
 * ⇒ بيع يفرّغ المخزون كان يمرّ **بصمت** بلا إشعار لصاحب الصيدلية.
 *
 * العقود التي يُثبتها هذا الملف:
 *
 *   1. **الإشعار بعد الـcommit فقط.** أي إشعار داخل المعاملة كان قد يُرسَل
 *      عن بيع تراجعت — لذلك المولّد يُستدعى بعد عودة `DB::transaction`.
 *   2. **فشل الإشعار لا يُسقط البيع.** الفاتورة وخصم المخزون يبقيان محفوظين
 *      حتى لو رمى مزوّد الإشعارات استثناءً.
 *   3. **لا تكرار.** نفس `upsertNotification` (C7) يمنع الصفوف والـpush
 *      المكررين لاستدعاءات متتالية على نفس الدواء.
 *   4. **`cancelSale` بلا إشعار.** زيادة الكمية لا تُنشئ low/out حسب عقد
 *      `LowStockNotifier`، ولا يوجد انتقال `is_available` من البيع/الإلغاء
 *      ⇒ لا `medicine_available` مدين به (مُوثَّق في M-2.5).
 *
 * العزل: FCM بلا credentials ⇒ `fromNotification` no-op (بلا شبكة).
 */
class AccountingSaleNotificationTest extends TestCase
{
    /** @return array{0:User,1:Pharmacy} */
    private function pharmacyUser(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    /** @return array{0:PharmacyMedicine,1:Medicine} */
    private function stock(Pharmacy $pharmacy, string $name, int $qty): array
    {
        $medicine = Medicine::factory()->create(['trade_name' => $name]);

        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 25.00,
            'quantity' => $qty,
            'is_available' => true,
        ]);

        return [$pm, $medicine];
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return \Illuminate\Testing\TestResponse
     */
    private function sell(User $user, array $items)
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson('/api/pharmacy/accounting/sales', [
                'payment_method' => 'cash',
                'items' => $items,
            ]);
    }

    private function notificationsFor(User $user): int
    {
        return Notification::where('user_id', $user->id)->count();
    }

    // ══════════════════════════════════════════════════════════════════
    // 1) النفاد الكامل
    // ══════════════════════════════════════════════════════════════════

    public function test_a_sale_that_empties_the_stock_creates_an_out_of_stock_notification(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, 'Panadol', 2);

        $this->sell($user, [[
            'pharmacy_medicine_id' => $pm->id,
            'medicine_name' => 'Panadol',
            'unit_price' => 25.00,
            'quantity' => 2,
        ]])->assertCreated();

        $this->assertSame(0, (int) $pm->fresh()->quantity);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'medicine_id' => $pm->medicine_id,
            'type' => 'out_of_stock',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // 2) النقص
    // ══════════════════════════════════════════════════════════════════

    public function test_a_sale_that_drops_below_the_threshold_creates_a_low_stock_notification(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, 'Panadol', 12);

        $this->sell($user, [[
            'pharmacy_medicine_id' => $pm->id,
            'medicine_name' => 'Panadol',
            'unit_price' => 25.00,
            'quantity' => 7,
        ]])->assertCreated();

        $this->assertSame(5, (int) $pm->fresh()->quantity);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'medicine_id' => $pm->medicine_id,
            'type' => 'low_stock',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // 3) الفشل — لا إشعار عن بيع تراجعت
    // ══════════════════════════════════════════════════════════════════

    public function test_a_failed_sale_creates_no_notification(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, 'Panadol', 2);

        // المخزون 2 والطلب 5 ⇒ فشل الخصم ⇒ rollback
        $this->sell($user, [[
            'pharmacy_medicine_id' => $pm->id,
            'medicine_name' => 'Panadol',
            'unit_price' => 25.00,
            'quantity' => 5,
        ]])->assertStatus(422)
            ->assertJsonValidationErrors(['items']);

        $this->assertSame(2, (int) $pm->fresh()->quantity);
        $this->assertSame(0, $this->notificationsFor($user));
    }

    // ══════════════════════════════════════════════════════════════════
    // 4) التفريد
    // ══════════════════════════════════════════════════════════════════

    public function test_repeated_sales_do_not_duplicate_notifications(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, 'Panadol', 10);

        $item = fn (int $qty) => [[
            'pharmacy_medicine_id' => $pm->id,
            'medicine_name' => 'Panadol',
            'unit_price' => 25.00,
            'quantity' => $qty,
        ]];

        $this->sell($user, $item(3))->assertCreated();  // 10 → 7 (low)
        $this->sell($user, $item(2))->assertCreated();  // 7 → 5 (low)

        $this->assertSame(
            1,
            $this->notificationsFor($user),
            'C7: بيعان متتاليان في نطاق low_stock ⇒ إشعار واحد فقط.'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // 5) الإلغاء
    // ══════════════════════════════════════════════════════════════════

    public function test_cancelling_a_sale_creates_no_notification(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, 'Panadol', 10);

        $create = $this->sell($user, [[
            'pharmacy_medicine_id' => $pm->id,
            'medicine_name' => 'Panadol',
            'unit_price' => 25.00,
            'quantity' => 8,
        ]])->assertCreated();

        // البيع أنشأ إشعار نفاد (10 → 2)
        $this->assertSame(1, $this->notificationsFor($user));

        $number = $create->json('data.number');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pharmacy/accounting/sales/{$number}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', \App\Models\Sale::STATUS_CANCELLED);

        // زيادة الكمية (10 → 8) لا تُنشئ إشعارًا — عقد `LowStockNotifier`
        $this->assertSame(
            1,
            $this->notificationsFor($user),
            'إلغاء الفاتورة (زيادة الكمية) لا يُنشئ إشعارًا حسب عقد `LowStockNotifier`.'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // 6) فشل الإشعار لا يُسقط البيع
    // ══════════════════════════════════════════════════════════════════

    public function test_a_notification_failure_does_not_fail_the_sale(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();
        [$pm] = $this->stock($pharmacy, 'Panadol', 10);

        // مزوّد إشعارات يرمي استثناءً ⇒ `notifyForMany` يعزله لكل صنف
        $this->mock(FcmSender::class, function ($mock) {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('sendToUser')->andThrow(new \RuntimeException('fcm down'));
            $mock->shouldReceive('fromNotification')->andThrow(new \RuntimeException('fcm down'));
        });

        $response = $this->sell($user, [[
            'pharmacy_medicine_id' => $pm->id,
            'medicine_name' => 'Panadol',
            'unit_price' => 25.00,
            'quantity' => 8,
        ]]);

        // 🔴 الفاتورة وخصم المخزون يبقيان محفوظين رغم فشل الإشعار
        $response->assertCreated()->assertJsonPath('success', true);

        $this->assertSame(2, (int) $pm->fresh()->quantity);
        $this->assertDatabaseHas('sales', [
            'pharmacy_id' => $pm->pharmacy_id,
            'status' => \App\Models\Sale::STATUS_PAID,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // 7) بيع متعدد الأصناف
    // ══════════════════════════════════════════════════════════════════

    public function test_a_multi_item_sale_notifies_each_low_item(): void
    {
        [$user] = $this->pharmacyUser();
        $pharmacy = $this->pharmacyFor($user);
        [$pmA] = $this->stock($pharmacy, 'Panadol', 2);
        [$pmB] = $this->stock($pharmacy, 'Brufen', 9);

        $this->sell($user, [
            [
                'pharmacy_medicine_id' => $pmA->id,
                'medicine_name' => 'Panadol',
                'unit_price' => 10.00,
                'quantity' => 2,   // ⇒ 0 ⇒ out_of_stock
            ],
            [
                'pharmacy_medicine_id' => $pmB->id,
                'medicine_name' => 'Brufen',
                'unit_price' => 20.00,
                'quantity' => 2,   // ⇒ 7 ⇒ low_stock
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'medicine_id' => $pmA->medicine_id,
            'type' => 'out_of_stock',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'medicine_id' => $pmB->medicine_id,
            'type' => 'low_stock',
        ]);
    }

    private function pharmacyFor(User $user): Pharmacy
    {
        return Pharmacy::where('user_id', $user->id)->firstOrFail();
    }
}
