<?php

namespace Tests\Feature\Web;

use App\Models\Medicine;
use App\Models\MedicineBarcode;
use App\Models\MohMedicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\Sale;
use App\Models\User;
use Tests\TestCase;

/**
 * وحدة محاسبة الصيدلية — صفحات الويب.
 *
 * نطاق هذه الاختبارات (مهم):
 *   ✅ تسجيل المسارات و middleware.
 *   ✅ أن كل قالب **يُرسم فعلًا** (وليس assertRedirect فقط — درس
 *      review.blade.php الذي ظل 500 لأشهر خلف assertRedirect).
 *   ✅ وجود عناصر الواجهة الحرجة (حقل الباركود، السلة، الأزرار).
 *   ✅ عدم وجود روابط ميتة في الشريط الجانبي.
 *
 * ⚠️ تحديث 2026-09-15: الموديول صار له Backend حقيقي (AccountingLedger +
 * جداول sales/sale_items). لم يعد هناك mock في المسار الحقيقي، لذا:
 *   - قائمة المبيعات تبدأ **فارغة** لصيدلية جديدة (لم يعد 20 فاتورة وهمية).
 *   - `/sales/{number}` يرجع 404 لأي رقم غير موجود فعلًا في قاعدة البيانات.
 * اختبارات البيانات الحقيقية (حفظ/خصم/بيع آجل) في
 * `tests/Feature/Api/AccountingWiringTest.php`، والتحقق من أن كل نقطة وصل
 * موجودة في الصفحات في `tests/Feature/Web/AccountingPosWiringRenderTest.php`.
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
        [$user, $pharmacy] = $this->pharmacyUser();

        // ⚠️ الجدول يُرسم فقط عند وجود صفوف (الحالة الفارغة بديل كامل)
        // ⇒ ننشئ فاتورة حقيقية قبل فحص العناوين.
        $this->makeSale($pharmacy->id, 'INV-5001');

        $this->actingAs($user)->get('/pharmacy/accounting/sales')
            ->assertOk()
            ->assertSee('INV-5001')
            ->assertSee(__('accounting.sales.col_invoice'))
            ->assertSee(__('accounting.sales.col_discount'))
            ->assertSee(__('accounting.sales.col_remaining'))
            ->assertSee(__('accounting.sales.col_payment'))
            ->assertSee(__('accounting.sales.col_status'));
    }

    public function test_sales_filters_accept_known_values_without_error(): void
    {
        [$user] = $this->pharmacyUser();
        $this->actingAs($user);

        // ⚠️ القيم غير المعروفة تُتجاهل بصمت (200 لا 422) — نفس عقد الـAPI
        // حرفيًا. هنا نتأكد أن كل قيمة معروفة تُرسم بلا خطأ 500.
        foreach (['status=paid', 'status=cancelled', 'method=cash', 'range=today', 'range=all'] as $qs) {
            $this->get('/pharmacy/accounting/sales?'.$qs)->assertOk();
        }
    }

    public function test_sales_filter_values_round_trip_into_the_form(): void
    {
        [$user] = $this->pharmacyUser();

        // الفلتر يُعاد إرساله للـview ويُحدَّد في الـ<select> — وإلا فُقد الفلتر
        // عند الترقيم أو البحث.
        $html = $this->actingAs($user)->get('/pharmacy/accounting/sales?status=paid&method=cash&range=today')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<option value="paid"\s+selected/', $html);
        $this->assertMatchesRegularExpression('/<option value="cash"\s+selected/', $html);
        $this->assertMatchesRegularExpression('/<option value="today"\s+selected/', $html);
    }

    public function test_sales_search_narrows_by_invoice_number(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();

        // ننشئ فاتورتين حقيقيتين — البحث يجب أن يعزل واحدة بالتحديد.
        $this->makeSale($pharmacy->id, 'INV-2001');
        $this->makeSale($pharmacy->id, 'INV-2002');

        $html = $this->actingAs($user)->get('/pharmacy/accounting/sales?q=INV-2001')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('INV-2001', $html);
        $this->assertStringNotContainsString('INV-2002', $html);
    }

    public function test_sales_page_shows_empty_state_distinctly_from_no_results(): void
    {
        [$user] = $this->pharmacyUser();

        // ⚠️ لا نستعمل assertSee/assertDontSee على النصّ المجرّد: كتلة i18n
        // المحقونة في الصفحة تحتوي `barcode.link_no_results` = "No matching
        // results." — وهي تحتوي "No matching results" كسلسلة فرعية، فينجح
        // assertDontSee زورًا (أو يفشل زورًا). نقصر الفحص على كتلة الحالة الفارغة.
        $empty = $this->actingAs($user)->get('/pharmacy/accounting/sales')->assertOk()->getContent();
        $block = $this->emptyStateBlock($empty);

        $this->assertStringContainsString(__('accounting.sales.empty'), $block);
        $this->assertStringNotContainsString(__('accounting.sales.no_results'), $block);

        // فلتر لا يطابق شيئًا ⇒ «لا نتائج» (لا «لا مبيعات»)
        $filtered = $this->actingAs($user)->get('/pharmacy/accounting/sales?q=ZZZ-NOTHING')
            ->assertOk()->getContent();
        $block2 = $this->emptyStateBlock($filtered);

        $this->assertStringContainsString(__('accounting.sales.no_results'), $block2);
        $this->assertStringNotContainsString(__('accounting.sales.empty'), $block2);
    }

    /** مقطع كتلة الحالة الفارغة وحدها (`.ph-empty`) — لا الصفحة كلها. */
    private function emptyStateBlock(string $html): string
    {
        if (preg_match('/<div class="ph-empty">.*?<\/div>\s*<\/div>/s', $html, $m)) {
            return $m[0];
        }

        $this->fail('كتلة الحالة الفارغة (.ph-empty) غير موجودة في الصفحة');
    }

    public function test_sales_page_paginates_instead_of_dumping_every_row(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();

        // 12 فاتورة، 10 لكل صفحة ⇒ صفحتان.
        for ($i = 1; $i <= 12; $i++) {
            $this->makeSale($pharmacy->id, 'INV-'.(3000 + $i));
        }

        $page1 = $this->actingAs($user)->get('/pharmacy/accounting/sales')->assertOk();
        $page1->assertSee('INV-3012');           // الأحدث في الصفحة الأولى
        $page1->assertDontSee('INV-3001');       // الأقدم مؤجّلة للصفحة الثانية
        $page1->assertSee('page=2', false);

        $page2 = $this->actingAs($user)->get('/pharmacy/accounting/sales?page=2')->assertOk();
        $page2->assertSee('INV-3001');
        $page2->assertDontSee('INV-3012');
    }

    public function test_sales_list_isolates_pharmacies(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();

        [$otherUser, $otherPharmacy] = $this->pharmacyUser();

        $this->makeSale($pharmacy->id, 'INV-4001');
        $this->makeSale($otherPharmacy->id, 'INV-4999');

        // 🔴 IDOR: فاتورة صيدلية أخرى يجب ألا تظهر إطلاقًا
        $html = $this->actingAs($user)->get('/pharmacy/accounting/sales')->assertOk()->getContent();
        $this->assertStringContainsString('INV-4001', $html);
        $this->assertStringNotContainsString('INV-4999', $html);

        // ونفس المبدأ على صفحة الفاتورة المفردة
        $this->actingAs($user)->get('/pharmacy/accounting/sales/INV-4999')->assertNotFound();
        $this->actingAs($user)->get('/pharmacy/accounting/sales/INV-4001')->assertOk();
    }

    public function test_new_sale_saved_through_the_ledger_appears_in_the_list(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();

        // ⚠️ هذا هو جوهر الاندماج: فاتورة تُكتب عبر مسار الـAPI الحقيقي
        // (`AccountingLedger`) يجب أن تُقرأ فورًا في صفحة الويب. قبل الإصلاح
        // كانت الصفحة تقرأ من الـmock فلا تظهر الفاتورة أبدًا.
        //
        // نستعمل Sanctum:actingAs (لا actingAs وحدها) لأن مسار الـAPI محروس
        // بـauth:sanctum — وactingAs تبني جلسة ويب لا توكِن الـAPI.
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->postJson('/api/pharmacy/accounting/sales', [
            'items' => [[
                'medicine_name' => 'باراسيتامول ٥٠٠',
                'quantity' => 2,
                'unit_price' => 4.50,
            ]],
            'payment_method' => 'cash',
        ])->assertCreated();

        $list = $this->actingAs($user)->get('/pharmacy/accounting/sales')->assertOk()->getContent();

        // الفاتورة ظهرت ⇒ الجدول مرسوم، ولا وجود لحالة فارغة
        $this->assertStringContainsString('<thead', $list);
        $this->assertStringNotContainsString('class="ph-empty"', $list);
        $this->assertStringContainsString('باراسيتامول ٥٠٠', $this->firstInvoiceHtml($user));
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

    public function test_pos_config_catalog_reflects_the_pharmacys_real_inventory(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();

        // 🔴 قبل الإصلاح كان الكتالوج من الـmock (باركود وهمي 6281001001234).
        // الآن يجب أن يكون **مخزون هذه الصيدلية** — وهذا ما نتحقق منه.
        $this->stockItem($pharmacy->id, 'دواء كتالوج حقيقي', 42, 7.25, '6299999990001');

        $html = $this->actingAs($user)->get('/pharmacy/accounting/sales/create')
            ->assertOk()
            ->getContent();

        // ⚠️ `@json` (Blade) يهرّب غير-ASCII إلى \uXXXX — فالمطابقة تكون على
        // الشكل المهرَّب. نستخرج كتلة الكتالوج ونفكّها ثم نتحقق من القيم.
        $catalog = $this->catalogFromHtml($html);

        $this->assertCount(1, $catalog, 'الكتالوج يجب أن يحوي صنفًا واحدًا فقط');

        $item = $catalog[0];
        $this->assertSame('دواء كتالوج حقيقي', $item['trade_name']);
        $this->assertSame('6299999990001', $item['barcode']);
        $this->assertSame(42, $item['quantity']);
        $this->assertSame(7.25, $item['price']);
        // المحوران مطلوبان: `medicine_id` (لكتابة الـbarcode) و`id` (للخصم)
        $this->assertNotNull($item['medicine_id']);
        $this->assertNotNull($item['id']);

        // ولا يتسرّب مخزون صيدلية أخرى إلى كتالوج هذه الصيدلية
        [$otherUser, $otherPharmacy] = $this->pharmacyUser();
        $this->stockItem($otherPharmacy->id, 'دواء صيدلية أخرى', 5, 1.0, '6299999997777');

        $catalog2 = $this->catalogFromHtml(
            $this->actingAs($user)->get('/pharmacy/accounting/sales/create')->assertOk()->getContent()
        );

        $this->assertCount(1, $catalog2);
        $this->assertSame('دواء كتالوج حقيقي', $catalog2[0]['trade_name']);
    }

    /** يفكّ `catalog` من كتلة إعداد POS (مع فكّ تهرّب `@json` لغير-ASCII). */
    private function catalogFromHtml(string $html): array
    {
        $plain = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // ⚠️ `"catalog"` يظهر **مرّتين**: مرة في الإعداد الأساسي من
        // `partials.accounting-i18n` (= `[]`، بديل مؤقّت) ومرة في كتلة الدمج
        // من `sale-create` (= الكتالوج الحقيقي). الصفحة الفعّالة هي **الأخيرة**
        // لأن `Object.assign(..., $posConfig)` يأتي بعدها. نأخذ آخر ظهور.
        $key = strrpos($plain, '"catalog"');

        $this->assertNotFalse($key, 'كتلة الكتالوج مفقودة من إعداد POS');
        $this->assertSame(
            2,
            substr_count($plain, '"catalog"'),
            'عدد كتل `catalog` تغيّر — راجع ترتيب دمج إعداد POS'
        );

        // نمشي من '[' مع عدّ الأقواس — أدقّ من regex على JSON متداخل.
        $start = strpos($plain, '[', $key);
        $depth = 0;
        $end = $start;

        for ($i = $start, $len = strlen($plain); $i < $len; $i++) {
            if ($plain[$i] === '[') {
                $depth++;
            } elseif ($plain[$i] === ']') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        $decoded = json_decode(substr($plain, $start, $end - $start + 1), true);

        $this->assertIsArray($decoded, 'كتالوج POS ليس JSON صالحًا');

        return $decoded;
    }

    public function test_pos_catalog_is_empty_for_a_pharmacy_with_no_inventory(): void
    {
        [$user] = $this->pharmacyUser();

        // صيدلية بلا مخزون ⇒ كتالوج فارغ + شريط تجريبي (لا خطأ 500).
        $html = $this->actingAs($user)->get('/pharmacy/accounting/sales/create')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/"catalog":\s*\[\s*\]/', $html);
    }

    /* ==========================================================
       تفاصيل الفاتورة
       ========================================================== */

    public function test_invoice_page_renders_for_an_existing_sale(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();

        $this->makeSale($pharmacy->id, 'INV-1042');

        $this->actingAs($user)->get('/pharmacy/accounting/sales/INV-1042')
            ->assertOk()
            ->assertSee('INV-1042')
            ->assertSee(__('accounting.invoice.payment_history'));
    }

    public function test_invoice_page_renders_the_real_line_items(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();

        // 🔴 قبل الإصلاح كانت الفاتورة تعرض «لا توجد بنود مسجّلة» دائمًا لأن
        // الـmock بلا بنود. الآن البنود تُقرأ من `sale_items` (لقطة وقت البيع).
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $created = $this->postJson('/api/pharmacy/accounting/sales', [
            'items' => [[
                'medicine_name' => 'أموكسيسيلين ٥٠٠',
                'barcode' => '6291111112222',
                'quantity' => 3,
                'unit_price' => 6.00,
            ]],
            'payment_method' => 'cash',
        ])->assertCreated()->json('data');

        $html = $this->actingAs($user)
            ->get('/pharmacy/accounting/sales/'.$created['number'])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('أموكسيسيلين ٥٠٠', $html);
        $this->assertStringContainsString('6291111112222', $html);
        $this->assertStringNotContainsString(__('accounting.invoice.items_pending'), $html);
    }

    public function test_invoice_page_404s_for_an_unknown_number(): void
    {
        [$user] = $this->pharmacyUser();

        $this->actingAs($user)->get('/pharmacy/accounting/sales/INV-9999')->assertNotFound();
    }

    public function test_invoice_page_is_print_ready(): void
    {
        [$user, $pharmacy] = $this->pharmacyUser();

        $this->makeSale($pharmacy->id, 'INV-1042');

        // زر الطباعة + قاعدة الطباعة التي تخفي عناصر التنقّل
        $this->actingAs($user)->get('/pharmacy/accounting/sales/INV-1042')
            ->assertOk()
            ->assertSee('data-ac-print', false)
            ->assertSee('ac-no-print', false);
    }

    /* ==========================================================
       مساعدات الإنشاء — بيانات حقيقية في الجداول
       ========================================================== */

    /** فاتورة مباشرة في الجدول (بلا مسار الـAPI) — لاختبار القراءة/الترقيم. */
    private function makeSale(int $pharmacyId, string $number): Sale
    {
        return Sale::create([
            'pharmacy_id' => $pharmacyId,
            'number' => $number,
            'sold_at' => now(),
            'subtotal' => 10,
            'discount' => 0,
            'total' => 10,
            'paid' => 10,
            'remaining' => 0,
            'payment_method' => 'cash',
            'status' => 'paid',
            'items_count' => 1,
        ]);
    }

    /** صف مخزون حقيقي بإحداثياته الكاملة (دواء + مخزون + باركود). */
    private function stockItem(
        int $pharmacyId,
        string $tradeName,
        int $qty,
        float $price,
        string $barcode
    ): void {
        $medicine = Medicine::factory()->create([
            'trade_name' => $tradeName,
            'trade_name_ar' => $tradeName,
        ]);

        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacyId,
            'medicine_id' => $medicine->id,
            'quantity' => $qty,
            'price' => $price,
            'is_available' => true,
        ]);

        // ⚠️ `medicine_barcodes.moh_medicine_id` إلزامي (NOT NULL) — الباركود
        // يُنسب لسجل وزارة الصحة، و`local_medicine_id` اختياري هو ما يربطه
        // بمخزون الصيدلية. ترك `moh_medicine_id` فارغًا يرمي QueryException.
        $moh = MohMedicine::create([
            'trade_name' => $tradeName,
            'moh_product_id' => random_int(100000, 999999),
        ]);

        MedicineBarcode::create([
            'moh_medicine_id' => $moh->id,
            'local_medicine_id' => $medicine->id,
            'barcode' => $barcode,
            'barcode_type' => 'EAN13',
            'source' => 'manual',
            'confidence' => 1.0,
            'is_verified' => true,
        ]);
    }

    /** HTML صفحة الفاتورة الأولى (أحدث فاتورة للصيدلية). */
    private function firstInvoiceHtml(User $user): string
    {
        $number = Sale::query()->orderByDesc('id')->value('number');

        return $this->actingAs($user)
            ->get('/pharmacy/accounting/sales/'.$number)
            ->assertOk()
            ->getContent();
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
