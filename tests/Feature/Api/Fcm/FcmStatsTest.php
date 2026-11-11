<?php

namespace Tests\Feature\Api\Fcm;

use App\Models\Notification;
use App\Models\User;
use App\Services\Fcm\FcmPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `fcm:stats` — ملخّص تجميعي بلا بيانات مستخدمين.
 */
class FcmStatsTest extends TestCase
{
    use RefreshDatabase;

    private function notification(string $status, ?string $code = null, ?int $userId = null): Notification
    {
        return Notification::create([
            'user_id' => $userId ?? User::factory()->patient()->create()->id,
            'type' => 'order_status',
            'message' => 'تحديث',
            'is_read' => false,
            'push_status' => $status,
            'push_error_code' => $code,
            'created_at' => now(),
        ]);
    }

    public function test_it_counts_each_delivery_state(): void
    {
        $this->notification(FcmPushService::STATUS_SENT);
        $this->notification(FcmPushService::STATUS_SENT);
        $this->notification(FcmPushService::STATUS_SKIPPED);
        $this->notification(FcmPushService::STATUS_FAILED, 'UNAVAILABLE');

        $this->artisan('fcm:stats')
            ->assertExitCode(1) // يوجد فشل
            ->expectsOutputToContain('sent=2 failed=1 skipped=1 pending=0')
            ->expectsOutputToContain('failure_codes: UNAVAILABLE=1');
    }

    public function test_it_exits_zero_when_everything_is_delivered(): void
    {
        $this->notification(FcmPushService::STATUS_SENT);

        $this->artisan('fcm:stats')->assertExitCode(0);
    }

    public function test_it_never_prints_user_identifiers(): void
    {
        $user = User::factory()->patient()->create(['email' => 'privacy-check@daway.test']);
        $this->notification(FcmPushService::STATUS_FAILED, 'INTERNAL', $user->id);

        // معرّف رقمي صغير يظهر داخل أي عدّاد («failed=1») فلا يصلح كدليل؛
        // البريد فريد، وذكر اسم العمود نفسه يكفي لإثبات أنه لا يُطبع.
        $this->artisan('fcm:stats')
            ->doesntExpectOutputToContain('privacy-check@daway.test')
            ->doesntExpectOutputToContain('user_id');
    }
}
