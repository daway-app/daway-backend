<?php

namespace Tests\Feature;

use App\Models\AvailabilityNotification;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AvailabilityNotificationSchemaTest extends TestCase
{
    public function test_notified_at_column_exists(): void
    {
        $this->assertTrue(
            Schema::hasColumn('availability_notifications', 'notified_at'),
            'H5: العمود notified_at مطلوب لإعادة الإشعار بعد إعادة التوفر.'
        );
    }

    public function test_old_unique_constraint_dropped_allows_repeat_after_delivery(): void
    {
        // H5: بعد إزالة الـ unique القديم، يجب أن نستطيع إنشاء صفّين بنفس
        // (user, medicine, pharmacy) إذا كان notified_at مختلفاً.
        $user = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();
        $pharmacy = Pharmacy::factory()->create();

        $first = AvailabilityNotification::create([
            'user_id' => $user->id,
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $pharmacy->id,
            'is_notified' => true,
            'notified_at' => now()->subDays(1),
        ]);

        $this->assertNotNull($first);

        // دورة إشعار ثانية بعد إعادة التوفر — نفس الـ tuple لكن notified_at مختلف.
        $second = AvailabilityNotification::create([
            'user_id' => $user->id,
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $pharmacy->id,
            'is_notified' => false,
            'notified_at' => null,
        ]);

        $this->assertNotNull($second);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_same_cycle_duplicate_still_blocked(): void
    {
        // H5: الـ unique المركّب الجديد لا يزال يمنع تكراراً بنفس notified_at.
        $user = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();
        $pharmacy = Pharmacy::factory()->create();

        $stamp = now();

        AvailabilityNotification::create([
            'user_id' => $user->id,
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $pharmacy->id,
            'is_notified' => true,
            'notified_at' => $stamp,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        AvailabilityNotification::create([
            'user_id' => $user->id,
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $pharmacy->id,
            'is_notified' => true,
            'notified_at' => $stamp,
        ]);
    }
}
