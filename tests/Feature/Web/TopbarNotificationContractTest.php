<?php

namespace Tests\Feature\Web;

use App\Models\Notification;
use App\Models\User;
use Tests\TestCase;

/**
 * يحمي العقد بين شريط الإشعارات (Blade/JS) وواجهة برمجة الإشعارات.
 *
 * التاريخ: كان شريط الإشعارات يقرأ data.count و data.notifications من الاستجابة،
 * بينما الـ API يرجّع data.unread_count و data.data — ما يعني أن الشارة لم تكن
 * تظهر أبدًا والقائمة كانت تفشل بصمت. هذه الاختبارات تمنع رجوع الخطأ.
 */
class TopbarNotificationContractTest extends TestCase
{
    private function topbarScript(User $user): string
    {
        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        // استخرج آخر <script> في الصفحة (سكربت الشريط العلوي)
        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/s', $html, $matches);

        return implode("\n", $matches[1] ?? []);
    }

    public function test_count_badge_reads_the_nested_unread_count_field(): void
    {
        $user = User::factory()->admin()->create();

        $script = $this->topbarScript($user);

        // يجب أن يقرأ data.data.unread_count
        $this->assertStringContainsString(
            'data?.data?.unread_count',
            $script,
            'شارة الإشعارات يجب أن تقرأ data.data.unread_count'
        );

        // ولا يجب أن تعود لقراءة الحقل القديم غير الموجود
        $this->assertStringNotContainsString(
            'data.count',
            $script,
            'شارة الإشعارات ما زالت تقرأ data.count وهو حقل غير موجود في الاستجابة'
        );
    }

    public function test_notifications_list_reads_the_data_array(): void
    {
        $user = User::factory()->admin()->create();

        $script = $this->topbarScript($user);

        $this->assertStringContainsString(
            'data?.data',
            $script,
            'قائمة الإشعارات يجب أن تقرأ المصفوفة من data.data'
        );

        $this->assertStringNotContainsString(
            'data.notifications',
            $script,
            'قائمة الإشعارات ما زالت تقرأ data.notifications وهو حقل غير موجود في الاستجابة'
        );
    }

    public function test_api_actually_returns_the_fields_the_topbar_expects(): void
    {
        $user = User::factory()->admin()->create();

        Notification::create([
            'user_id' => $user->id,
            'type' => 'low_stock',
            'message' => 'نقص في المخزون',
            'is_read' => false,
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/notifications/count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);

        $this->actingAs($user)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.message', 'نقص في المخزون')
            ->assertJsonPath('unread_count', 1);
    }
}
