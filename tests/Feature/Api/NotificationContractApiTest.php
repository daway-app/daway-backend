<?php

namespace Tests\Feature\Api;

use App\Models\Notification;
use App\Models\User;
use Tests\TestCase;

/**
 * عقد استجابة الإشعارات كما يستهلكه شريط الإشعارات.
 *
 * ── لماذا ملف مستقل؟ ─────────────────────────────────────────────
 * كان هذا التأكيد داخل `tests/Feature/Web/TopbarNotificationContractTest.php`
 * مع تأكيدين آخرين يقرآن HTML لوحة Blade. التأكيدان يموتان مع Blade، أما
 * هذا فهو **API نقي** (`/api/notifications*`) ويجب أن يبقى بعد الحذف.
 *
 * التاريخ: كان الشريط يقرأ `data.count` و`data.notifications` بينما الـAPI
 * يرجّع `data.unread_count` و`data.data` — فلم تكن الشارة تظهر أبدًا
 * والقائمة تفشل بصمت. هذه الاختبارات تمنع رجوع الخطأ.
 */
final class NotificationContractApiTest extends TestCase
{
    public function test_api_returns_the_fields_the_notification_bar_expects(): void
    {
        $user = User::factory()->admin()->create();

        Notification::create([
            'user_id' => $user->id,
            'type' => 'low_stock',
            'message' => 'نقص في المخزون',
            'is_read' => false,
            'created_at' => now(),
        ]);

        // العدد: الشارة تقرأ `data.unread_count` (لا `data.count`).
        $this->actingAs($user)
            ->getJson('/api/notifications/count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonMissingPath('data.count');

        // القائمة: العناصر في `data` (لا `data.notifications`)، والعدد في الجذر.
        $this->actingAs($user)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.message', 'نقص في المخزون')
            ->assertJsonPath('unread_count', 1)
            ->assertJsonMissingPath('data.notifications');
    }
}
