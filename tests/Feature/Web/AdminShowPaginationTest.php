<?php

namespace Tests\Feature\Web;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تغطية انحدار لترقيم صفحات العرض.
 *
 * قبل الإصلاح كانت هذه الصفحات تعرض كل الصفوف في DOM واحد بلا ترقيم:
 *   - pharmacies/show:90   → @forelse($pharmacy->pharmacyMedicines) بلا حد
 *   - medicines/show:107   → @forelse($medicine->pharmacyMedicines) بلا حد
 *   - pharmacy/alternatives/index:44 → ->get() بلا حد + استعلامان لكل صف
 *
 * هذه الاختبارات تُثبّت: العدّاد الكلي صحيح، وكل صفحة تعرض العدد المحدد فقط،
 * وأن الصفحة التالية تعرض البقية.
 */
class AdminShowPaginationTest extends TestCase
{
    use RefreshDatabase;

    private const PER_PAGE = 20;

    private const ALTERNATIVES_PER_PAGE = 50; // 7 -> 50 بقرار الأدمن الموحد لكل قوائم الويب

    private const TOTAL = 60; // 50/صفحة: يجب أن تتجاوز البيانات حدود الصفحة الأولى

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_pharmacy_show_paginates_inventory_and_reports_full_total(): void
    {
        $pharmacy = Pharmacy::factory()->create();

        for ($i = 1; $i <= self::TOTAL; $i++) {
            $medicine = Medicine::factory()->create(['trade_name' => 'MED-'.$i]);
            PharmacyMedicine::create([
                'pharmacy_id' => $pharmacy->id,
                'medicine_id' => $medicine->id,
                'price' => 5,
                'quantity' => 10,
            ]);
        }

        $admin = $this->admin();

        $page1 = $this->actingAs($admin)->get(route('pharmacies.show', $pharmacy->id));

        $page1->assertOk()
            // العدّاد الكلي = 25 لا 20 (يُحسب بـSQL على كامل المجموعة)
            ->assertSee(__('pharmacies.medicines_in_stock').' ('.self::TOTAL.')', false)
            // الأحدث أولاً (orderByDesc('id')) → يظهر في الصفحة الأولى
            ->assertSee('MED-'.self::TOTAL.'</strong>', false)
            // الأقدم (5 صفوف) يجب أن يكون خارج الصفحة الأولى
            ->assertDontSee('MED-1</strong>', false)
            ->assertDontSee('MED-5</strong>', false);

        // عدد الصفوف فعلياً في الصفحة الأولى = 20 (لا 25)
        $this->assertSame(
            self::PER_PAGE,
            substr_count($page1->getContent(), 'class="med-name"'),
            'الصفحة الأولى يجب أن تعرض 20 صفاً فقط'
        );

        $page2 = $this->actingAs($admin)->get(
            route('pharmacies.show', ['pharmacy' => $pharmacy->id, 'page' => 2])
        );

        $page2->assertOk()
            // TOTAL=60 و20 لكل صفحة → الصفحة الثانية تظهر 40..21
            ->assertSee('MED-40</strong>', false)
            ->assertSee('MED-21</strong>', false);

        // ماركب الترقيم موجود (nav role="navigation" هو ما تعتمد عليه قواعد CSS
        // العالمية في app_layout.css لتصغير أسهم SVG — راجع تعليق القسم هناك).
        $page1->assertSee('role="navigation"', false);
    }

    public function test_medicine_show_paginates_pharmacies_and_reports_full_total(): void
    {
        $medicine = Medicine::factory()->create(['trade_name' => 'SINGLE-MED']);

        for ($i = 1; $i <= self::TOTAL; $i++) {
            $pharmacy = Pharmacy::factory()->create(['pharmacy_name' => 'PH-'.$i]);
            PharmacyMedicine::create([
                'pharmacy_id' => $pharmacy->id,
                'medicine_id' => $medicine->id,
                'price' => 7,
                'quantity' => 3,
            ]);
        }

        $admin = $this->admin();

        $page1 = $this->actingAs($admin)->get(route('medicines.show', $medicine->id));

        $page1->assertOk()
            // العدّادان (أعلى الصفحة + عنوان القسم) = 25
            ->assertSee('متوفر في '.self::TOTAL.' صيدلية')
            ->assertSee('(' . self::TOTAL . ')')
            ->assertSee('PH-'.self::TOTAL.'</strong>', false)
            ->assertDontSee('PH-1</strong>', false);

        $this->assertSame(
            self::PER_PAGE,
            substr_count($page1->getContent(), 'PH-'),
            'الصفحة الأولى يجب أن تعرض 20 صيدلية فقط'
        );

        $page2 = $this->actingAs($admin)->get(
            route('medicines.show', ['medicine' => $medicine->id, 'page' => 2])
        );

        $page2->assertOk()->assertSee('PH-40</strong>', false);
    }

    public function test_pharmacy_alternatives_index_paginates_without_n_plus_one(): void
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        for ($i = 1; $i <= self::TOTAL; $i++) {
            $medicine = Medicine::factory()->create([
                'trade_name' => 'ALT-MED-'.$i,
                'active_ingredient' => 'ING-'.$i,
            ]);
            PharmacyMedicine::create([
                'pharmacy_id' => $pharmacy->id,
                'medicine_id' => $medicine->id,
                'price' => 5,
                'quantity' => 0,
            ]);
        }

        // عدد الاستعلامات: الترقيم + 2 استعلامات مجمّعة + إحصاءان = ثابت مهما كان عدد الصفوف.
        // قبل الإصلاح كان استعلامان لكل صف داخل حلقة العرض.
        \Illuminate\Support\Facades\DB::enableQueryLog();

        $response = $this->actingAs($user)->get(route('pharmacy.alternatives.index'));
        $response->assertOk();

        $queryCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // مقيس فعلياً: 10 استعلامات ثابتة لـ25 دواءً (كان 2×عدد الصفوف المعروضة)
        $this->assertLessThan(
            20,
            $queryCount,
            "عدد الاستعلامات يجب أن يبقى ثابتاً ومنخفضاً (كان 2×عدد الصفوف). الفعلي: {$queryCount}"
        );

        // الصفحة الأولى: 7 بطاقات فقط من 25 مثل باقي صفحات لوحة الصيدلية.
        $this->assertSame(
            self::ALTERNATIVES_PER_PAGE,
            substr_count($response->getContent(), "class='ph-card ph-alt-block'"),
            'الصفحة الأولى يجب أن تعرض 50 بطاقة فقط'
        );

        $page2 = $this->actingAs($user)->get(
            route('pharmacy.alternatives.index', ['page' => 2])
        );

        $page2->assertOk()->assertSee('ALT-MED-'.(self::TOTAL - self::ALTERNATIVES_PER_PAGE), false);
    }

    public function test_pharmacy_alternatives_index_searches_before_paginating_and_preserves_query_string(): void
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        for ($i = 1; $i <= self::ALTERNATIVES_PER_PAGE + 8; $i++) {
            $medicine = Medicine::factory()->create([
                'trade_name' => 'SEARCH-ALT-'.$i,
                'active_ingredient' => 'ING-SEARCH-'.$i,
            ]);
            PharmacyMedicine::create([
                'pharmacy_id' => $pharmacy->id,
                'medicine_id' => $medicine->id,
                'price' => 5,
                'quantity' => 0,
            ]);
        }

        $otherMedicine = Medicine::factory()->create([
            'trade_name' => 'OTHER-MEDICINE',
            'active_ingredient' => 'ING-OTHER',
        ]);
        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $otherMedicine->id,
            'price' => 5,
            'quantity' => 0,
        ]);

        $page1 = $this->actingAs($user)->get(
            route('pharmacy.alternatives.index', ['q' => 'SEARCH-ALT'])
        );

        $page1->assertOk()
            ->assertSee('name=\'q\'', false)
            ->assertSee('value=\'SEARCH-ALT\'', false)
            ->assertSee('page=2', false)
            ->assertDontSee('OTHER-MEDICINE', false);

        $this->assertSame(
            self::ALTERNATIVES_PER_PAGE,
            substr_count($page1->getContent(), "class='ph-card ph-alt-block'"),
            'البحث يجب أن يتم في الاستعلام قبل الترقيم'
        );

        $page2 = $this->actingAs($user)->get(
            route('pharmacy.alternatives.index', ['q' => 'SEARCH-ALT', 'page' => 2])
        );

        $page2->assertOk()
            ->assertSee('SEARCH-ALT-1', false)
            ->assertDontSee('OTHER-MEDICINE', false);
    }
}
