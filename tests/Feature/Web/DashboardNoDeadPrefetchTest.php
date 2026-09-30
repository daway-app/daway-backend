<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 6 — حراسة إزالة «التحميل المسبق» الميت من لوحة الأدمن.
 *
 * السبب: كان `dashboard/index.blade.php` يُنفّذ `fetch(url, {cache:'force-cache'})`
 * عند مرور المؤشر على `.nav-item` لتسخين الكاش. لكنه **لا يسرّع التنقّل إطلاقًا**:
 *   1) طلب التسخين mode='cors' لا 'navigate'، و`sw.js` يُعيد التنقّل من فرع
 *      `request.mode === 'navigate'` (network-only) ⇒ لا يتقاطعان.
 *   2) لا توجد أي headers كاش على HTML ⇒ لا نسخة مخزّنة يُعاد استخدامها.
 *   ⇒ كل تسخين = render كامل مصادَق على السيرفر بلا أي نفع (traffic زائد فقط).
 *
 * هذا الاختبار يمنع عودة النمط الميت (regression) — لا يفحص سلوكًا مرئيًا.
 */
class DashboardNoDeadPrefetchTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $user = User::factory()->create(['role' => 'admin']);

        return $user;
    }

    public function test_admin_dashboard_does_not_emit_the_dead_prefetch_warmup(): void
    {
        $response = $this->actingAs($this->adminUser())->get('/');
        $response->assertOk();

        $html = $response->getContent();

        // النمط الميت: تسخين بـ force-cache على روابط التنقّل.
        $this->assertStringNotContainsString(
            "cache: 'force-cache'",
            $html,
            'لوحة الأدمن لا يجب أن تُسخّن الكاش عبر force-cache (لا نفع — التنقّل network-only).'
        );
        $this->assertStringNotContainsString(
            'mouseenter',
            $html,
            'يجب ألا يبقى مستمع mouseenter للتسخين على لوحة الأدمن.'
        );
    }

    public function test_admin_dashboard_still_renders_navigation_links(): void
    {
        // الحذف لا يمسّ روابط التنقّل نفسها — الصفحة لا تزال تعرض الشريط الجانبي.
        $response = $this->actingAs($this->adminUser())->get('/');
        $response->assertOk();
        $response->assertSee('nav-item', false);
    }
}
