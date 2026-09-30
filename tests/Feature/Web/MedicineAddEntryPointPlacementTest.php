<?php

namespace Tests\Feature\Web;

use App\Models\Pharmacy;
use App\Models\User;
use Tests\TestCase;

/**
 * مكان زر «أضف دواء» — بوابة عامة واحدة في الشريط الجانبي.
 *
 * القرار (محدَّث بطلب المالك): رابط «إضافة دواء» عنصر nav-item عادي في
 * الشريط الجانبي (`components/sidebar.blade.php`) يفتح صفحة الإضافة الكاملة
 * (`pharmacy.medicines.create`) — لا فورم داخل السايدبار. الشريط يظهر في كل
 * صفحة، فالرابط هو البوابة العامة الوحيدة.
 *
 * القواعد التي يقيسها هذا الاختبار:
 *   - رابط الإنشاء يظهر **مرة واحدة بالضبط** في صفحات الصيدلية التي لا تملك
 *     زر إضافة خاصًا بها (لوحة التحكم، المخزون، طلب دواء) — أي من السايدبار فقط.
 *   - صفحة قائمة الأدوية تملك زرها الخاص بجانب القائمة، فيظهر الرابط **مرتين**
 *     (السايدبار + زر الصفحة).
 *   - لا روابط إنشاء أخرى في أي مكان آخر.
 */
class MedicineAddEntryPointPlacementTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create(['user_id' => $this->user->id]);
    }

    /** رابط صفحة إنشاء الدواء. */
    private function createUrl(): string
    {
        return route('pharmacy.medicines.create');
    }

    /** عدد مرات ظهور رابط الإنشاء في HTML الخام للصفحة. */
    private function countCreateUrl(string $html): int
    {
        return substr_count($html, $this->createUrl());
    }

    /* ==========================================================
       قائمة الأدوية — زر الصفحة + رابط السايدبار
       ========================================================== */

    public function test_medicines_index_is_the_single_entry_point(): void
    {
        $res = $this->actingAs($this->user)
            ->get(route('pharmacy.medicines.index'))
            ->assertOk();

        $this->assertSame(
            2,
            $this->countCreateUrl($res->getContent()),
            'صفحة الأدوية: رابط السايدبار + زر الصفحة فقط.'
        );
        $res->assertSee(__('pharmacy.medicines.index.add_medicine'), false);
    }

    /* ==========================================================
       باقي الصفحات — رابط السايدبار وحده (مرة واحدة بالضبط)
       ========================================================== */

    public function test_dashboard_has_no_add_medicine_entry_point(): void
    {
        $res = $this->actingAs($this->user)
            ->get('/pharmacy/dashboard')
            ->assertOk();

        $this->assertSame(
            1,
            $this->countCreateUrl($res->getContent()),
            'لوحة التحكم: رابط السايدبار وحده، بلا زر إضافة في المحتوى.'
        );
    }

    public function test_inventory_page_has_no_add_medicine_entry_point(): void
    {
        $res = $this->actingAs($this->user)
            ->get(route('pharmacy.inventory.index'))
            ->assertOk();

        $this->assertSame(
            1,
            $this->countCreateUrl($res->getContent()),
            'صفحة المخزون: رابط السايدبار وحده، بلا زر إضافة في المحتوى.'
        );
    }

    /**
     * الشريط الجانبي يظهر في **كل** صفحة — رابط واحد عام فيه لا أكثر.
     */
    public function test_sidebar_has_no_add_medicine_entry_point_on_any_pharmacy_page(): void
    {
        $pages = [
            '/pharmacy/dashboard',
            route('pharmacy.inventory.index'),
            route('pharmacy.medicines.request.create'),
        ];

        foreach ($pages as $uri) {
            $res = $this->actingAs($this->user)
                ->get($uri)
                ->assertOk();

            $this->assertSame(
                1,
                $this->countCreateUrl($res->getContent()),
                "رابط إنشاء واحد فقط (السايدبار) في: {$uri}."
            );
        }
    }

    /**
     * حماية من الانحدار المعاكس: الشريط الجانبي نفسه يجب أن يظل موجودًا
     * (الحذف لا يجوز أن يمسّ التنقّل الأساسي).
     */
    public function test_sidebar_still_renders_navigation(): void
    {
        $this->actingAs($this->user)
            ->get(route('pharmacy.medicines.index'))
            ->assertOk()
            ->assertSee(route('pharmacy.inventory.index'), false)
            ->assertSee(route('pharmacy.medicines.index'), false);
    }
}
