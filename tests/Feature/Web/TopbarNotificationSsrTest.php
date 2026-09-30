<?php

namespace Tests\Feature\Web;

use App\Models\Notification;
use App\Models\User;
use Tests\TestCase;

/**
 * Phase 4 — حراسة: عدّ الإشعارات يُرسَم من الخادم (SSR) بلا طلب XHR عند التحميل.
 */
class TopbarNotificationSsrTest extends TestCase
{
    public function test_badge_renders_unread_count_from_server_without_client_fetch(): void
    {
        $user = User::factory()->admin()->create();

        Notification::create([
            'user_id' => $user->id, 'type' => 'low_stock',
            'message' => 'a', 'is_read' => false, 'created_at' => now(),
        ]);
        Notification::create([
            'user_id' => $user->id, 'type' => 'low_stock',
            'message' => 'b', 'is_read' => false, 'created_at' => now(),
        ]);
        Notification::create([
            'user_id' => $user->id, 'type' => 'low_stock',
            'message' => 'c', 'is_read' => true, 'created_at' => now(),
        ]);

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        // الشارة تحمل 2 (غير المقروءة فقط) ومُعروضة.
        $this->assertMatchesRegularExpression(
            '/id="notificationBadge"[^>]*style="display:flex"[^>]*>2</',
            $html,
            'شارة الإشعارات يجب أن تُرسَم من الخادم بالعدّ الصحيح وبحالة عرض صحيحة'
        );

        // لا يجوز أن يبقى نداء عدّ الإشعارات عند التحميل (تم استبداله بـ SSR).
        $this->assertStringNotContainsString(
            'fetchNotificationCount(); // Fetch notification count on page load',
            $html,
            'يجب إزالة جلب العدّ عند تحميل الصفحة — صار مُرسَمًا من الخادم'
        );
    }

    public function test_badge_hidden_when_no_unread(): void
    {
        $user = User::factory()->admin()->create();

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        // بلا غير مقروء: لا style=display:flex على الشارة.
        $this->assertDoesNotMatchRegularExpression(
            '/id="notificationBadge"[^>]*style="display:flex"/',
            $html,
            'الشارة يجب أن تبقى مخفية عند عدم وجود إشعارات غير مقروءة'
        );
    }

    public function test_count_is_scoped_to_the_authenticated_user(): void
    {
        $userA = User::factory()->admin()->create();
        $userB = User::factory()->admin()->create();

        // للمستخدم B ثلاثة غير مقروءة، للمستخدم A واحد.
        foreach (range(1, 3) as $i) {
            Notification::create([
                'user_id' => $userB->id, 'type' => 'low_stock',
                'message' => 'b'.$i, 'is_read' => false, 'created_at' => now(),
            ]);
        }
        Notification::create([
            'user_id' => $userA->id, 'type' => 'low_stock',
            'message' => 'a', 'is_read' => false, 'created_at' => now(),
        ]);

        $html = $this->actingAs($userA)->get(route('dashboard'))->assertOk()->getContent();

        // عزل: A يرى 1 لا 3.
        $this->assertMatchesRegularExpression(
            '/id="notificationBadge"[^>]*style="display:flex"[^>]*>1</',
            $html,
            'عدّ الإشعارات يجب أن يكون مقيّدًا بالمستخدم الحالي فقط'
        );
    }
}
