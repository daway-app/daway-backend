<?php

namespace Tests\Feature\Web;

use App\Models\Pharmacy;
use App\Models\User;
use Tests\TestCase;

/**
 * وحدة محاسبة الصيدلية — Frontend-only.
 *
 * نطاق هذه الاختبارات (مهم):
 *   ✅ تسجيل المسارات و middleware.
 *   ✅ أن كل قالب **يُرسم فعلًا** (وليس assertRedirect فقط — درس
 *      review.blade.php الذي ظل 500 لأشهر خلف assertRedirect).
 *   ✅ وجود عناصر الواجهة الحرجة (حقل الباركود، السلة، الأزرار).
 *   ✅ عدم وجود روابط ميتة في الشريط الجانبي.
 *
 *   ❌ لا نختبر حفظًا في قاعدة البيانات — لا يوجد Backend محاسبة،
 *      واختبار «حفظ» سيكون اختبارًا لسلوك غير موجود.
 */
class AccountingPagesTest extends TestCase
{
    private function pharmacyUser(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create([
            'user_id' => $user->id,
            'delivered_at' => now(),
        ]);

        return [$user, $pharmacy];
    }

    /* ==========================================================
       الحماية (middleware)
       ========================================================== */

    public function test_guest_is_redirected_from_all_accounting_pages(): void
    {
        foreach ([
            '/pharmacy/accounting',
            '/pharmacy/accounting/sales',
            '/pharmacy/accounting/sales/create',
            '/pharmacy/accounting/sales/INV-1042',
        ] as $uri) {
            $this->get($uri)->assertRedirect();
        }
    }

    public function test_patient_cannot_access_accounting_pages(): void
    {
        $this->actingAs(User::factory()->patient()->create());

        $this->get('/pharmacy/accounting')->assertRedirect();
        $this->get('/pharmacy/accounting/sales')->assertRedirect();
    }

    public function test_admin_cannot_access_pharmacy_accounting_pages(): void
    {
        // role:pharmacy يحجب الأدمن — العقد الحالي للمشروع
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/pharmacy/accounting')->assertRedirect();
    }

    public function test_pharmacy_user_can_render_every_accounting_page(): void
    {
        [$user] = $this->pharmacyUser();
        $this->actingAs($user);

        // كل صفحة مبنية تُرسم 200 فعلًا — هذا هو الاختبار الحقيقي
        $this->get('/pharmacy/accounting')->assertOk()->assertSee(__('accounting.overview.heading'));
        $this->get('/pharmacy/accounting/sales')->assertOk()->assertSee(__('accounting.sales.heading'));
        $this->get('/pharmacy/accounting/sales/create')->assertOk()->assertSee(__('accounting.pos.heading'));
    }

    /* ==========================================================
       النظرة العامة
       ========================================================== */

    public function test_overview_renders_all_six_kpi_cards(): void
    {
        [$user] = $this->pharmacyUser();

        $response = $this->actingAs($user)->get('/pharmacy/accounting');

        $response->assertOk()
            ->assertSee(__('accounting.overview.kpi_today_sales'))
            ->assertSee(__('accounting.overview.kpi_today_purchases'))
            ->assertSee(__('accounting.overview.kpi_today_expenses'))
            ->assertSee(__('accounting.overview.kpi_today_profit'))
            ->assertSee(__('accounting.overview.kpi_cash_balance'))
            ->assertSee(__('accounting.overview.kpi_outstanding_debts'));
    }

    public function test_overview_renders_charts_and_alerts(): void
    {
        [$user] = $this->pharmacyUser();

        $this->actingAs($user)->get('/pharmacy/accounting')
            ->assertOk()
            ->assertSee('data-ac-chart="sales"', false)
            ->assertSee('data-ac-chart="expenses"', false)
            ->assertSee(__('accounting.overview.alerts_title'))
            ->assertSee(__('accounting.overview.recent_transactions'));
    }

    public function test_overview_shows_all_four_sales_ranges(): void
    {
        [$user] = $this->pharmacyUser();

        $this->actingAs($user)->get('/pharmacy/accounting')
            ->assertOk()
            ->assertSee('data-ac-range="today"', false)
            ->assertSee('data-ac-range="7d"', false)
            ->assertSee('data-ac-range="30d"', false)
            ->assertSee('data-ac-range="month"', false);
    }

    public function test_overview_emits_a_valid_json_config(): void
    {
        [$user] = $this->pharmacyUser();

        $html = $this->actingAs($user)->get('/pharmacy/accounting')->getContent();

        // نستخرج كتلة الإعداد ونتأكد أنها JSON صالح (اختبار @json الفعلي)
        $this->assertMatchesRegularExpression(
            '/window\.acOverviewConfig\s*=\s*(\{.*?\});/s',
            $html,
            'كتلة إعداد النظرة العامة مفقودة'
        );

        preg_match('/window\.acOverviewConfig\s*=\s*(\{.*?\});/s', $html, $m);
        $decoded = json_decode($m[1], true);

        $this->assertIsArray($decoded, 'إعداد النظرة العامة ليس JSON صالحًا');
        $this->assertSame('7d', $decoded['defaultRange']);
        $this->assertArrayHasKey('salesSeries', $decoded);
        foreach (['today', '7d', '30d', 'month'] as $range) {
            $this->assertArrayHasKey($range, $decoded['salesSeries'], "المدى $range مفقود");
        }
    }

    /* ==========================================================
       المبيعات
       ========================================================== */

    public function test_sales_page_renders_full_table_headers(): void
    {
        [$user] = $this->pharmacyUser();

        $this->actingAs($user)->get('/pharmacy/accounting/sales')
            ->assertOk()
            ->assertSee(__('accounting.sales.col_invoice'))
            ->assertSee(__('accounting.sales.col_discount'))
            ->assertSee(__('accounting.sales.col_remaining'))
            ->assertSee(__('accounting.sales.col_payment'))
            ->assertSee(__('accounting.sales.col_status'));
    }

    public function test_sales_filters_narrow_the_result_set(): void
    {
        [$user] = $this->pharmacyUser();
        $this->actingAs($user);

        // فلتر الحالة: فاتورة مدفوعة موجودة، والملغاة يجب ألا تظهر
        $paid = $this->get('/pharmacy/accounting/sales?status=paid');
        $paid->assertOk();
        $this->assertStringContainsString('INV-1042', $paid->getContent());

        $cancelled = $this->get('/pharmacy/accounting/sales?status=cancelled');
        $cancelled->assertOk()->assertSee('INV-1031');
        $this->assertStringNotContainsString('INV-1042', $cancelled->getContent());
    }

    public function test_sales_search_filters_by_invoice_number(): void
    {
        [$user] = $this->pharmacyUser();

        $this->actingAs($user)->get('/pharmacy/accounting/sales?q=INV-1042')
            ->assertOk()
            ->assertSee('INV-1042')
            ->assertDontSee('INV-1041');
    }

    public function test_sales_page_shows_empty_state_distinctly_from_no_results(): void
    {
        [$user] = $this->pharmacyUser();

        // فلتر لا يطابق شيئًا ⇒ «لا نتائج» (لا «لا مبيعات»)
        $this->actingAs($user)->get('/pharmacy/accounting/sales?q=ZZZ-NOTHING')
            ->assertOk()
            ->assertSee(__('accounting.sales.no_results'))
            ->assertDontSee(__('accounting.sales.empty'));
    }

    public function test_sales_page_paginates_instead_of_dumping_every_row(): void
    {
        [$user] = $this->pharmacyUser();

        $response = $this->actingAs($user)->get('/pharmacy/accounting/sales');
        $response->assertOk();

        // 20 فاتورة في mock، 10 لكل صفحة ⇒ يوجد ترقيم صفحات
        $response->assertSee('page=2', false);

        // الفاتورة الحادية عشرة وما بعدها مؤجّلة للصفحة التالية (لا تُحمَّل مع الصفحة الأولى)
        $response->assertDontSee('INV-1032');
        $response->assertDontSee('INV-1023');

        // الأولى حاضرة
        $response->assertSee('INV-1042');
    }

    /* ==========================================================
       شاشة البيع (POS)
       ========================================================== */

    public function test_pos_page_renders_barcode_field_ready_for_a_scanner(): void
    {
        [$user] = $this->pharmacyUser();

        $this->actingAs($user)->get('/pharmacy/accounting/sales/create')
            ->assertOk()
            // قارئ USB يُعرّف نفسه كـلوحة مفاتيح ⇒ الحقل يجب أن يبقى قابلًا
            // للكتابة والتركيز، مع inputmode منعًا للكيبورد الافتراضي على الهاتف.
            ->assertSee('data-barcode-input', false)
            ->assertSee('inputmode="numeric"', false)
            ->assertSee(__('accounting.pos.barcode_hint'));
    }

    public function test_pos_page_treats_search_and_scan_as_equal_weight_paths(): void
    {
        [$user] = $this->pharmacyUser();

        // ⚠️ المبدأ: قاعدة moh_medicines لا تحتوي باركودًا كاملًا، والمسح
        // يجب أن يكون مسارًا أول بنفس وزن البحث — لا بديلًا احتياطيًّا.
        // اختبار سلوكي: كلتا الطريقتين لها نقطة وصل حقيقية في نفس الصفحة.
        $this->actingAs($user)->get('/pharmacy/accounting/sales/create')
            ->assertOk()
            // مسار البحث
            ->assertSee('data-ac-search', false)
            ->assertSee(__('accounting.pos.search_label'))
            // مسار المسح المحلي
            ->assertSee('data-barcode-scan', false)
            // مسار المسح بالهاتف
            ->assertSee('data-phone-scanner-open', false)
            ->assertSee(__('accounting.scanner.button'));
    }

    public function test_pos_page_explains_that_not_every_medicine_has_a_barcode(): void
    {
        [$user] = $this->pharmacyUser();

        // هذا النصّ هو قلب التصميم: يجعل «الباركود المجهول» متوقَّعًا لا مفاجئًا.
        $this->actingAs($user)->get('/pharmacy/accounting/sales/create')
            ->assertOk()
            ->assertSee(__('accounting.barcode.not_every_medicine_hint'));
    }

    public function test_pos_page_renders_cart_empty_state_and_invoice_panel(): void
    {
        [$user] = $this->pharmacyUser();

        $this->actingAs($user)->get('/pharmacy/accounting/sales/create')
            ->assertOk()
            ->assertSee('data-ac-cart-body', false)
            ->assertSee(__('accounting.pos.cart_empty'))
            ->assertSee('data-ac-subtotal', false)
            ->assertSee('data-ac-total', false)
            ->assertSee('data-ac-paid', false)
            ->assertSee('data-ac-submit', false);
    }

    public function test_pos_config_points_only_at_endpoints_that_actually_exist(): void
    {
        [$user] = $this->pharmacyUser();

        // المساران يجب أن يكونا الموجودين فعلاً في routes/api.php.
        // ملاحظة: @json يهرّب '/' إلى '\/' — لذا نطابق مقطعًا بلا سلاش.
        $this->actingAs($user)->get('/pharmacy/accounting/sales/create')
            ->assertOk()
            ->assertSee('"medicineSearch"', false)
            ->assertSee('medicines\/search"', false)
            ->assertSee('"barcodeLookup"', false)
            ->assertSee('medicines\/barcode"', false);
    }

    public function test_pos_config_has_a_non_empty_local_catalog(): void
    {
        [$user] = $this->pharmacyUser();

        $this->actingAs($user)->get('/pharmacy/accounting/sales/create')
            ->assertOk()
            // كتالوج محلي جاهز للعمل بلا شبكة — عناصر حقيقية من AccountingMockData
            ->assertSee('6281001001234', false)
            ->assertSee('"quantity"', false)
            ->assertSee('"price"', false);
    }

    /* ==========================================================
       تفاصيل الفاتورة
       ========================================================== */

    public function test_invoice_page_renders_for_an_existing_sale(): void
    {
        [$user] = $this->pharmacyUser();

        $this->actingAs($user)->get('/pharmacy/accounting/sales/INV-1042')
            ->assertOk()
            ->assertSee('INV-1042')
            ->assertSee(__('accounting.invoice.payment_history'));
    }

    public function test_invoice_page_404s_for_an_unknown_number(): void
    {
        [$user] = $this->pharmacyUser();

        $this->actingAs($user)->get('/pharmacy/accounting/sales/INV-9999')->assertNotFound();
    }

    public function test_invoice_page_is_print_ready(): void
    {
        [$user] = $this->pharmacyUser();

        // زر الطباعة + قاعدة الطباعة التي تخفي عناصر التنقّل
        $this->actingAs($user)->get('/pharmacy/accounting/sales/INV-1042')
            ->assertOk()
            ->assertSee('data-ac-print', false)
            ->assertSee('ac-no-print', false);
    }

    /* ==========================================================
       الشريط الجانبي
       ========================================================== */

    public function test_sidebar_shows_accounting_section_for_pharmacy(): void
    {
        [$user] = $this->pharmacyUser();

        $this->actingAs($user)->get('/pharmacy/accounting')
            ->assertOk()
            ->assertSee(__('accounting.sidebar.section_title'))
            ->assertSee('data-nav-toggle="ac-sidebar-menu"', false);
    }

    public function test_sidebar_accounting_has_no_dead_links(): void
    {
        [$user] = $this->pharmacyUser();

        $html = $this->actingAs($user)->get('/pharmacy/accounting')->getContent();

        // كل رابط فعلي داخل القائمة الفرعية يجب أن يشير لمسار مسجّل
        // (Blade يرتّب href قبل class)
        preg_match_all('/<a\s+href="([^"]+)"\s+class="nav-subitem/', $html, $m);
        $this->assertNotEmpty($m[1], 'لا توجد روابط فعلية في قائمة المحاسبة');

        foreach ($m[1] as $href) {
            $path = parse_url($href, PHP_URL_PATH);
            $this->assertContains(
                $path,
                ['/pharmacy/accounting', '/pharmacy/accounting/sales'],
                "رابط غير متوقّع في الشريط: {$path}"
            );
        }

        // الصفحات القادمة تُعرض كـ <span> بلا href (لا رابط ميت)
        $this->assertStringContainsString('aria-disabled="true"', $html);
        $this->assertStringContainsString(__('accounting.sidebar.soon'), $html);
    }

    public function test_sidebar_accounting_is_hidden_from_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/')->assertOk()->assertDontSee('data-nav-toggle="ac-sidebar-menu"', false);
    }

    public function test_accounting_section_is_expanded_when_on_an_accounting_page(): void
    {
        [$user] = $this->pharmacyUser();

        $this->actingAs($user)->get('/pharmacy/accounting')
            ->assertOk()
            ->assertSee('aria-expanded="true"', false);
    }

    /* ==========================================================
       سلامة عدم كسر الواجهة الحالية
       ========================================================== */

    public function test_existing_pharmacy_pages_still_render(): void
    {
        [$user] = $this->pharmacyUser();
        $this->actingAs($user);

        // إضافة قسم المحاسبة يجب ألا تكسر أي صفحة صيدلية قائمة
        $this->get('/pharmacy/dashboard')->assertOk();
        $this->get('/pharmacy/inventory')->assertOk();
        $this->get('/pharmacy/profile')->assertOk();
    }

    public function test_accounting_pages_do_not_leak_into_the_admin_area(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        // /pharmacy/accounting محجوب عن الأدمن (role:pharmacy)
        $this->get('/pharmacy/accounting')->assertRedirect();
    }
}
