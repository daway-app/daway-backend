<?php

namespace Tests\Feature\Web;

use App\Models\Pharmacy;
use App\Models\User;
use Tests\TestCase;

/**
 * المسح بالهاتف — الهاتف كقارئ باركود عن بُعد للويب.
 *
 * ## لماذا هذه الاختبارات مهمّة بهذا الشكل
 *
 * الميزة تعتمد على **باك-إند غير موجود بعد**، فيمكن بسهولة أن تُبنى واجهة
 * توهم المستخدم باقتران حقيقي وهو غير قائم. لذلك لا نختبر «هل نجح الاقتران»
 * (لا يمكن — لا endpoint)، بل نختبر **الصدق البنيوي**:
 *
 *   ✅ أن عناصر الواجهة تُرسم فعلًا (لا assertRedirect فقط).
 *   ✅ أن الوضع التجريبي **مُعلَن** في الصفحة عند غياب المسارات.
 *   ✅ أن الصفحة **لا تُسرّب** أي توكن/مفتاح/كلمة مرور إلى HTML.
 *   ✅ أن مناطق الحالة الحيّة موجودة بـ`aria-live` (التغيير يأتي من جهاز آخر).
 *   ✅ أن **قارئ USB لم يُكسر** — الحقل ما زال مدخلًا نصيًّا عاديًّا.
 *   ✅ أن المسح بالهاتف **لا يفتح كاميرا** في الويب (لا getUserMedia).
 *
 * ❌ لا نختبر حفظ جلسات في قاعدة البيانات — الجدول غير موجود.
 */
class PhoneScannerTest extends TestCase
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

    private function pos(): \Illuminate\Testing\TestResponse
    {
        [$user] = $this->pharmacyUser();

        return $this->actingAs($user)->get('/pharmacy/accounting/sales/create');
    }

    /* ==========================================================
       وجود الميزة في الصفحة
       ========================================================== */

    public function test_pos_page_renders_the_phone_scanner_entry_point(): void
    {
        $this->pos()
            ->assertOk()
            ->assertSee('data-phone-scanner-open', false)
            ->assertSee(__('accounting.scanner.button'));
    }

    public function test_pos_page_renders_the_pairing_modal_with_all_eight_states(): void
    {
        // ⚠️ الحالات الثمانية جزء من العقد: كل واحدة نصّها موجود في الـlang.
        // لو حُذف أحد المفاتيح، يسقط الاختبار بدل أن تظهر شارة فارغة للمستخدم.
        $response = $this->pos()->assertOk();

        $response->assertSee('data-phone-scanner-modal', false);

        foreach ([
            'state_waiting', 'state_connecting', 'state_connected',
            'state_scanning', 'state_received', 'state_disconnected',
            'state_expired', 'state_error',
        ] as $key) {
            $this->assertNotSame(
                'accounting.scanner.' . $key,
                __('accounting.scanner.' . $key),
                "مفتاح الترجمة مفقود: accounting.scanner.{$key}"
            );
        }
    }

    public function test_pairing_modal_shows_a_pairing_code_region_and_qr_slot(): void
    {
        $this->pos()
            ->assertOk()
            ->assertSee('data-pair-code', false)
            ->assertSee(__('accounting.scanner.pairing_code'))
            // خانة الـQR (مؤقّتة الآن، حقيقية عند توفّر pairing_url)
            ->assertSee('data-pair-qr', false)
            ->assertSee(__('accounting.scanner.qr_pending'));
    }

    public function test_session_status_region_is_announced_to_assistive_tech(): void
    {
        // السبب: التغيير يأتي من جهاز آخر — يجب أن يُعلَن بلا سرقة التركيز
        // من حقل الباركود (WCAG 4.1.3 الحالات الرسائلية).
        $this->pos()
            ->assertOk()
            ->assertSee('data-session-status', false)
            ->assertSee('aria-live="polite"', false);
    }

    public function test_device_card_is_ready_for_multiple_phones(): void
    {
        $this->pos()
            ->assertOk()
            ->assertSee('data-devices-card', false)
            ->assertSee('data-device-request', false)
            ->assertSee(__('accounting.scanner.device_allow'))
            ->assertSee(__('accounting.scanner.device_reject'));
    }

    /* ==========================================================
       الأمان — لا تسريب
       ========================================================== */

    public function test_page_never_leaks_tokens_or_secrets(): void
    {
        // ⚠️ الميزة عرضت QR/رمز اقتران. القاعدة: جلسة مؤقتة فقط.
        // هذا الاختبار يفشل إذا تسرّب يو*ما توكن أو مفتاح إلى الصفحة.
        $html = $this->pos()->assertOk()->getContent();

        foreach ([
            'personal_access_token',
            'plainTextToken',
            'api_key',
            'apiKey',
            'password',
            'secret',
            'Bearer ',
        ] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $html,
                "تسريب محتمل إلى HTML: {$needle}"
            );
        }
    }

    public function test_web_never_opens_a_camera(): void
    {
        // ⚠️ قرار مقصود: الكاميرا في الهاتف فقط. الويب يستقبل **نصّ الباركود**.
        $html = $this->pos()->assertOk()->getContent();

        $this->assertStringNotContainsString('getUserMedia', $html);
        $this->assertStringNotContainsString('mediaDevices', $html);
        $this->assertStringNotContainsString('BarcodeDetector', $html);
    }

    /* ==========================================================
       الصدق البنيوي — الوضع التجريبي مُعلَن
       ========================================================== */

    public function test_demo_mode_is_declared_because_endpoints_do_not_exist_yet(): void
    {
        // AccountingMockData::scanSessionEndpoints() تُرجع [] عمدًا.
        $this->assertSame([], \App\Support\Accounting\AccountingMockData::scanSessionEndpoints());

        $this->pos()
            ->assertOk()
            ->assertSee(__('accounting.scanner.mock_title'))
            ->assertSee(__('accounting.scanner.mock_body'));
    }

    public function test_scanner_config_declares_no_fake_endpoints(): void
    {
        $this->pos()
            ->assertOk()
            // المسارات غير موجودة ⇒ الحمولة تحمل مصفوفة فارغة
            ->assertSee('"scanSessions":[]', false)
            // النقل المختار: polling محدود — لا WebSocket (غير موجود بالمشروع)
            ->assertSee('"scanSessionTransport":"polling"', false)
            // الوضع التجريبي مُفعَّل صراحةً
            ->assertSee('"scanSessionMock":true', false);
    }

    public function test_transport_is_polling_not_websocket(): void
    {
        // ⚠️ المشروع لا يملك أي بنية real-time:
        // BROADCAST_CONNECTION=log، ولا Pusher/Reverb/Echo، ولا channels.php.
        // إضافة websocket الآن = بنية تحتية بلا مشغّل. الـpolling المحدود
        // (2s، ويتوقف عند إخفاء التبويب) هو الخيار المتوافق مع المشروع.
        $this->pos()
            ->assertOk()
            ->assertSee('"scanSessionTransport":"polling"', false)
            ->assertDontSee('"scanSessionTransport":"websocket"', false);
    }

    /* ==========================================================
       عدم كسر قارئ USB / لوحة المفاتيح
       ========================================================== */

    public function test_usb_keyboard_scanner_still_works(): void
    {
        // ⚠️ قارئ USB يُعرّف نفسه كـلوحة مفاتيح ⇒ يجب أن يبقى الحقل
        // عنصر <input> عاديًّا قابلًا للكتابة والتركيز، مع نقطة وصل Enter.
        $this->pos()
            ->assertOk()
            ->assertSee('data-barcode-input', false)
            ->assertSee('inputmode="numeric"', false)
            ->assertSee('autocomplete="off"', false)
            // تلميح المبدأ — يمنع الصيدلي من افتراض خطأ عند باركود مجهول
            ->assertSee(__('accounting.barcode.not_every_medicine_hint'));
    }

    public function test_phone_scanner_and_local_scan_coexist_on_the_same_page(): void
    {
        // المبدأ: الهاتف **طريقة إدخال إضافية**، لا نظام POS منفصل.
        // الثلاث طرق تنتهي إلى نفس السلة ونفس مسار البحث.
        $this->pos()
            ->assertOk()
            ->assertSee('data-barcode-scan', false)        // مسح محلي
            ->assertSee('data-phone-scanner-open', false)  // مسح بالهاتف
            ->assertSee('data-ac-search', false)           // بحث بالاسم
            ->assertSee('data-ac-cart-body', false);       // سلة واحدة مشتركة
    }

    /* ==========================================================
       مفردات حالة الباركود (BarcodeStatus)
       ========================================================== */

    public function test_barcode_status_vocabulary_matches_the_real_schema(): void
    {
        $status = \App\Support\Accounting\BarcodeStatus::class;

        // الحالات الأربع مشتقّة من is_verified + مراجعة الإثراء
        $this->assertSame(
            ['unknown', 'pending', 'verified', 'conflict'],
            $status::all()
        );

        $this->assertSame('unknown', $status::derive(false, false, false));
        $this->assertSame('pending', $status::derive(true, false, false));
        $this->assertSame('verified', $status::derive(true, true, false));
        $this->assertSame('conflict', $status::derive(true, true, true));

        // «غير معروف» ليست خطأ ⇒ لا تطلب تدخّلًا بنبرة فشل، ولا تُدرج ضمن
        // الحالات القابلة للبيع مباشرة (تحتاج ربطًا).
        $this->assertTrue($status::requiresAction('unknown'));
        $this->assertFalse(in_array('unknown', $status::sellable(), true));

        // pending قابلة للبيع: التوثيق يخصّ جودة البيانات لا صلاحية البيع
        $this->assertTrue(in_array('pending', $status::sellable(), true));
    }

    public function test_unknown_barcode_is_not_rendered_as_an_error(): void
    {
        // ⚠️ قواعد CSS: العائلة المحايدة لـ«غير مرتبط» — لا حمراء ولا صفراء.
        // هذا اختبار على التصميم نفسه، لأن اللون هو الرسالة هنا.
        $css = file_get_contents(resource_path('css/pages/pharmacy_accounting.css'));

        $this->assertStringContainsString('.ac-bcode-badge.is-unknown', $css);
        $this->assertStringContainsString('var(--ph-line-soft)', $css);

        // ولا يوجد صنف «خطأ» لباركود مجهول
        $this->assertStringNotContainsString('.ac-bcode-badge.is-unknown.is-error', $css);
    }

    public function test_barcode_normalization_is_consistent_between_php_and_js(): void
    {
        // الخادم والمتصفح يجب أن يحسبا نفس القيمة، وإلا اختلفت نتيجة
        // المسح بحسب الجهاز.
        $normalize = \App\Support\Accounting\BarcodeStatus::class . '::normalizeBarcode';

        $this->assertSame('6281234567890', $normalize('6281 2345 67890'));
        $this->assertSame('6281234567890', $normalize('6281-2345-67890'));
        $this->assertSame('6281234567890', $normalize('  6281234567890  '));

        // JS يطبّق نفس التحويل — نتحقق من وجوده في الملف
        $js = file_get_contents(resource_path('js/accounting/accounting-shared.js'));
        $this->assertStringContainsString("replace(/[\\s\\-_]/g, '')", $js);
    }
}
