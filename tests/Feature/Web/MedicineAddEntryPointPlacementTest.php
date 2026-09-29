<?php

namespace Tests\Feature\Web;

use App\Models\Pharmacy;
use App\Models\User;
use Tests\TestCase;

/**
 * مكان زر «أضف دواء» — بوابة واحدة فقط.
 *
 * الخلفية (المشكلة الأصلية): الزر كان يظهر في **أربعة** أماكن:
 *   1) الشريط الجانبي (`components/sidebar.blade.php`)
 *   2) لوحة الصيدلية (`pharmacy/dashboard/index.blade.php`)
 *   3) المخزون (`pharmacy/inventory/index.blade.php`)
 *   4) قائمة أدوية الصيدلية (`pharmacy/medicines/index.blade.php`)
 *
 * النتيجة: المستخدم لا يعرف «من أين يُضاف الدواء فعلًا»، وثلاثة من الأربعة
 * تنقل إلى **نفس** الصفحة في سياق مختلف عن موضعه ⇒ تشويش.
 *
 * القرار: تبقى **بوابة واحدة** في قائمة الأدوية (`pharmacy.medicines.index`) —
 * لأنها الصفحة التي يعرض فيها المخزون، فالإضافة بجانب القائمة منطقية سياقيًا.
 *
 * ⚠️ هذا الاختبار يقيس **الرابط إلى `pharmacy.medicines.create`** في الصفحة
 * المُخدَمة (بما فيها الشريط الجانبي) — لا نصّ الزر. لو عاد الزر في أي مكان
 * آخر بنفس الرابط، يسقط الاختبار.
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

    /** الرابط الوحيد المسموح لإنشاء دواء من لوحة الصيدلية. */
    private function createUrl(): string
    {
        return route('pharmacy.medicines.create');
    }

    /* ==========================================================
       البوابة الوحيدة — قائمة الأدوية
       ========================================================== */

    public function test_medicines_index_is_the_single_entry_point(): void
    {
        $this->actingAs($this->user)
            ->get(route('pharmacy.medicines.index'))
            ->assertOk()
            ->assertSee($this->createUrl(), false)
            ->assertSee(__('pharmacy.medicines.index.add_medicine'), false);
    }

    /* ==========================================================
       البوابات المكرّرة — يجب أن تبقى مغلقة
       ========================================================== */

    public function test_dashboard_has_no_add_medicine_entry_point(): void
    {
        $this->actingAs($this->user)
            ->get('/pharmacy/dashboard')
            ->assertOk()
            ->assertDontSee($this->createUrl(), false);
    }

    public function test_inventory_page_has_no_add_medicine_entry_point(): void
    {
        $this->actingAs($this->user)
            ->get(route('pharmacy.inventory.index'))
            ->assertOk()
            ->assertDontSee($this->createUrl(), false);
    }

    /**
     * الشريط الجانبي يظهر في **كل** صفحة — لذا نتحقق على عدة صفحات لا واحدة.
     * لو عاد عنصر «أضف دواء» للسايدبار، يسقط هذا الاختبار على أول صفحة.
     *
     * ⚠️ `pharmacy.medicines.index` **مستثناة عن قصد**: الزر مسموح فيها
     * (البوابة الوحيدة) — فوجود الرابط هناك ليس انحدارًا. تفحصها
     * `test_medicines_index_is_the_single_entry_point` أعلاه.
     */
    public function test_sidebar_has_no_add_medicine_entry_point_on_any_pharmacy_page(): void
    {
        $pages = [
            '/pharmacy/dashboard',
            route('pharmacy.inventory.index'),
            route('pharmacy.medicines.request.create'),
        ];

        foreach ($pages as $uri) {
            $this->actingAs($this->user)
                ->get($uri)
                ->assertOk()
                ->assertDontSee($this->createUrl(), false);
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
