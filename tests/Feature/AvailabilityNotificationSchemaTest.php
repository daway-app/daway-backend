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

    public function test_duplicate_rows_are_allowed_after_removing_rigid_unique(): void
    {
        // H5: الهدف إزالة القيد القديم الذي كان يمنع دورة إشعار جديدة.
        $user = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();
        $pharmacy = Pharmacy::factory()->create();

        $stamp = now();

        $first = AvailabilityNotification::create([
            'user_id' => $user->id,
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $pharmacy->id,
            'is_notified' => true,
            'notified_at' => $stamp,
        ]);

        $second = AvailabilityNotification::create([
            'user_id' => $user->id,
            'medicine_id' => $medicine->id,
            'pharmacy_id' => $pharmacy->id,
            'is_notified' => true,
            'notified_at' => $stamp,
        ]);

        $this->assertNotSame($first->id, $second->id);
    }
}
