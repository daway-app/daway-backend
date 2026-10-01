<?php

namespace Tests\Feature\Web;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Tests\TestCase;

/**
 * Phase 9 — حراسة التنقّل الجزئي لترقيم مخزون الصيدلية (الخادم).
 *
 * الـJS (`resources/js/pharmacy/inventory-nav.js`) يقرأ الحاوي `#inventory-content`
 * من استجابة HTML الكاملة ويستبدله. هذا الاختبار يثبّت العقد الخادمي الذي يعتمد
 * عليه الـJS، ويثبت أن الصفحة تعمل **بدون JS** (تنقّل تقليدي كامل):
 *   1) يوجد حاوٍ واحد بالضبط بـid="inventory-content".
 *   2) روابط الترقيم داخل الحاوي (حتى تُستخرج من الاستجابة الجديدة).
 *   3) صفحة 2 تُخدَم كاملة من الخادم بـ200 (fallback حقيقي بلا JS).
 *   4) الحاوي يحفظ فلتر البحث عبر الصفحات (withQueryString) — حتى لا يتغيّر
 *      المعنى عند التنقّل الجزئي.
 */
class PharmacyInventoryPartialNavTest extends TestCase
{
    private function seedInventory(int $count = 60): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        for ($i = 0; $i < $count; $i++) {
            $m = Medicine::factory()->create([
                'trade_name' => 'INV-MED-'.$i,
                'active_ingredient' => 'ING-'.$i,
            ]);
            PharmacyMedicine::create([
                'pharmacy_id' => $pharmacy->id,
                'medicine_id' => $m->id,
                'price' => 5,
                'quantity' => 20,
            ]);
        }

        return [$user, $pharmacy];
    }

    public function test_inventory_renders_exactly_one_content_container(): void
    {
        [$user] = $this->seedInventory(5);

        $html = $this->actingAs($user)->get('/pharmacy/inventory')->assertOk()->getContent();

        $this->assertSame(
            1,
            substr_count($html, 'id="inventory-content"'),
            'يجب أن يوجد حاوٍ واحد فقط بـid="inventory-content" (نقطة الاستبدال الوحيدة).'
        );
    }

    public function test_pagination_links_live_inside_the_content_container(): void
    {
        [$user] = $this->seedInventory(60); // 60 صفًا / 50 لكل صفحة ⇒ صفحتان

        $html = $this->actingAs($user)->get('/pharmacy/inventory')->assertOk()->getContent();

        // الحاوي يفتح قبل روابط الترقيم ويُغلق بعدها ⇒ الاستبدال يلتقطها.
        $containerStart = strpos($html, 'id="inventory-content"');
        $paginationPos = strpos($html, 'pagination-wrapper');

        $this->assertNotFalse($containerStart, 'الحاوي مفقود.');
        $this->assertNotFalse($paginationPos, 'روابط الترقيم مفقودة.');
        $this->assertGreaterThan(
            $containerStart,
            $paginationPos,
            'روابط الترقيم يجب أن تكون داخل الحاوي (بعد نقطة فتحه).'
        );
    }

    public function test_page_two_is_served_as_a_full_html_document_without_js(): void
    {
        [$user] = $this->seedInventory(60);

        $response = $this->actingAs($user)->get('/pharmacy/inventory?page=2')->assertOk();

        $html = $response->getContent();
        // وثيقة كاملة (fallback حقيقي) — لا partial.
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertSame(1, substr_count($html, 'id="inventory-content"'));
    }

    public function test_search_filter_is_preserved_in_pagination_links(): void
    {
        // 120 صفًا: فلتر "INV-MED" يطابقها كلها (60 > 50) ⇒ أكثر من صفحة
        // ⇒ روابط الترقيم موجودة فعلًا. (لو كان الفلتر يطابق ≤50 صفًا لما
        // رُسمت روابط أصلًا — ومن ثمّ لا يمكن تأكيد حفظه عبر الصفحات.)
        [$user] = $this->seedInventory(120);

        $html = $this->actingAs($user)->get('/pharmacy/inventory?q=INV-MED')->assertOk()->getContent();

        // الصفحة الأولى يجب أن تعرض 50 صفًا (حجم الصفحة) ⇒ ما زال هناك صفحة تالية.
        $this->assertSame(50, substr_count($html, 'data-status='));
        $this->assertStringContainsString('pagination-wrapper', $html);

        // روابط الترقيم تحفظ q عبر withQueryString. الـ`&` يُهرَّب في HTML كـ`&amp;`
        // لذا نطابق `page=` في نفس الوسم ونتأكد أن الفلتر سبقه.
        $this->assertMatchesRegularExpression(
            '/href="[^"]*inventory\?q=INV-MED&amp;page=\d+"/',
            $html,
            'روابط الترقيم يجب أن تحفظ فلتر البحث (withQueryString).'
        );
    }
}
