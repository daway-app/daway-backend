<?php

namespace Tests\Feature\Web;

use App\Models\Category;
use App\Models\Medicine;
use App\Models\MedicineRequest;
use App\Models\Pharmacy;
use App\Models\User;
use Tests\TestCase;

/**
 * صفحة «طلب دواء جديد» (`/pharmacy/medicines/request`) — الصيدلية.
 *
 * ## ما تغيّر (تبسيط النموذج)
 *
 * كانت الصفحة تعرض **10 حقول**، ستّة منها لا يقرأها الأدمن أصلًا عند الاعتماد:
 *   - أُبقيت: `trade_name` (إلزامي) · `trade_name_ar` · `active_ingredient` ·
 *     `category_id` (إلزامي) · `barcode`.
 *   - أُزيلت من الواجهة: `generic_name` · `manufacturer` · `dosage_form` ·
 *     `official_price` · `subcategory_id`.
 *
 * ⚠️ الإزالة **من الواجهة فقط**: لا عمود في قاعدة البيانات أُسقط، ولا
 * `storeRequest()` ولا `approve()` تغيّرا. لذلك يوجد هنا اختباران يحرسان
 * ذلك صراحةً (payload قديم يُقبل · طلب ناقص الحقول الاختيارية يُعتمد بنجاح).
 *
 * ## الباركود
 *
 * الصفحة **لا** تحتوي ماسحًا جديدًا. تُعيد استخدام محرّك الباركود المحاسبي
 * نفسه (`resources/js/accounting/*`) وتمرّر سلوكها عبر `window.__acScanHandlers`.
 * الاختبارات هنا تتحقق من وجود **نقاط الوصل** في الـHTML ومن تحميل ملفات
 * المحرّك فعليًا عبر Vite.
 */
class PharmacyMedicineRequestPageTest extends TestCase
{
    private User $user;
    private Pharmacy $pharmacy;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->pharmacy()->create();
        $this->pharmacy = Pharmacy::factory()->create(['user_id' => $this->user->id]);

        $this->category = Category::create([
            'name_ar' => 'قسم الطلب',
            'name_en' => 'Request Cat '.uniqid(),
            'slug' => 'req-cat-'.uniqid(),
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    /** مسار ملف مُصدَّر من Vite — يتحمّل إعادة البناء (dev server أو manifest). */
    private function viteAsset(string $entry): string
    {
        if (file_exists(public_path('hot'))) {
            return $entry; // dev server: @vite يطبع المسار المصدري كما هو
        }

        $manifest = public_path('build/manifest.json');
        if (is_file($manifest)) {
            $json = json_decode((string) file_get_contents($manifest), true);
            if (isset($json[$entry]['file'])) {
                return basename($json[$entry]['file']);
            }
        }

        return basename($entry);
    }

    private function requestUri(): string
    {
        return route('pharmacy.medicines.request.create');
    }

    /* ==========================================================
       1) الوصول والحماية
       ========================================================== */

    public function test_request_page_renders_for_pharmacy(): void
    {
        $this->actingAs($this->user)
            ->get($this->requestUri())
            ->assertOk()
            ->assertSee(__('pharmacy.medicines.request.title'));
    }

    public function test_guest_is_redirected(): void
    {
        $this->get($this->requestUri())->assertRedirect();
    }

    public function test_patient_cannot_open_request_page(): void
    {
        $this->actingAs(User::factory()->patient()->create())
            ->get($this->requestUri())
            ->assertRedirect();
    }

    /* ==========================================================
       2) النموذج — الحقول الخمسة فقط
       ========================================================== */

    public function test_form_has_exactly_the_five_kept_fields(): void
    {
        $res = $this->actingAs($this->user)->get($this->requestUri())->assertOk();

        foreach (['trade_name', 'trade_name_ar', 'active_ingredient', 'category_id', 'barcode'] as $field) {
            $res->assertSee('name="'.$field.'"', false);
        }
    }

    public function test_removed_fields_are_absent_from_the_html(): void
    {
        $res = $this->actingAs($this->user)->get($this->requestUri())->assertOk();

        foreach (['generic_name', 'manufacturer', 'dosage_form', 'official_price', 'subcategory_id'] as $field) {
            $res->assertDontSee('name="'.$field.'"', false);
        }
    }

    public function test_trade_name_and_category_are_marked_required(): void
    {
        $res = $this->actingAs($this->user)->get($this->requestUri())->assertOk();

        // علامة الإلزام في الواجهة (وسم البصر + سمة required في HTML)
        $res->assertSee('for="trade_name"', false);
        $res->assertSee('for="category_id"', false);
        $res->assertSee('required', false);
    }

    /* ==========================================================
       3) الباركود — إعادة استخدام المحرّك المحاسبي (بلا ماسح جديد)
       ========================================================== */

    public function test_barcode_field_and_scanner_hooks_are_present(): void
    {
        $res = $this->actingAs($this->user)->get($this->requestUri())->assertOk();

        // حقل الباركود نفسه (مكوّن المحاسبة المشترك)
        $res->assertSee('id="request_barcode"', false);
        $res->assertSee('data-barcode-input', false);

        // زر المسح المحلي (قارئ USB) + زر المسح بالهاتف
        $res->assertSee('data-barcode-scan', false);
        $res->assertSee('data-phone-scanner-open', false);

        // نافذة المسح بالهاتف + نافذة تعارض الباركود
        $res->assertSee('data-phone-scanner-modal', false);
        $res->assertSee('data-barcode-conflict-modal', false);

        // جسر i18n المحاسبي (يُحمَّل عبر partials.accounting-i18n)
        $res->assertSee('window.acBarcodeI18n', false);
        $res->assertSee('window.acAccountingConfig', false);
    }

    public function test_page_bridge_overrides_unknown_and_resolved_handlers(): void
    {
        $res = $this->actingAs($this->user)->get($this->requestUri())->assertOk();

        // ⚠️ بلا هذا الجسر، الباركود المجهول يفتح نافذة «ربط بدواء موجود» —
        // وهي بلا معنى في صفحة تُدخل دواءً غير موجود أصلًا.
        $res->assertSee('__acScanHandlers', false);
        $res->assertSee('onUnknown', false);
        $res->assertSee('onResolved', false);
    }

    public function test_shared_barcode_engine_assets_are_loaded(): void
    {
        $res = $this->actingAs($this->user)->get($this->requestUri())->assertOk();

        foreach ([
            'resources/js/accounting/accounting-shared.js',
            'resources/js/accounting/accounting-barcode.js',
            'resources/js/accounting/accounting-scanner-session.js',
            'resources/js/accounting/accounting-phone-scanner.js',
            'resources/css/pages/pharmacy_accounting.css',
        ] as $entry) {
            $res->assertSee($this->viteAsset($entry), false);
        }
    }

    /* ==========================================================
       4) الإرسال — التحقق والسلوك
       ========================================================== */

    public function test_trade_name_and_category_are_required_on_submit(): void
    {
        $this->actingAs($this->user)
            ->from($this->requestUri())
            ->post(route('pharmacy.medicines.request.store'), [])
            ->assertSessionHasErrors(['trade_name', 'category_id']);

        $this->assertSame(0, MedicineRequest::count());
    }

    public function test_arabic_trade_name_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->from($this->requestUri())
            ->post(route('pharmacy.medicines.request.store'), [
                'trade_name' => 'بنادول اكسترا',
                'category_id' => $this->category->id,
            ])
            ->assertSessionHasErrors('trade_name');
    }

    public function test_minimal_request_is_stored_with_null_optional_fields(): void
    {
        $this->actingAs($this->user)
            ->post(route('pharmacy.medicines.request.store'), [
                'trade_name' => 'MINIMAL REQUEST MED',
                'trade_name_ar' => 'دواء طلب مختصر',
                'active_ingredient' => 'TestIngredient',
                'category_id' => $this->category->id,
                'barcode' => '6281001005555',
            ])
            ->assertRedirect(route('pharmacy.medicines.index'));

        $request = MedicineRequest::where('trade_name', 'MINIMAL REQUEST MED')->first();

        $this->assertNotNull($request);
        $this->assertSame(MedicineRequest::STATUS_PENDING, $request->status);
        $this->assertSame($this->pharmacy->id, $request->pharmacy_id);
        $this->assertSame($this->category->id, $request->category_id);
        $this->assertSame('6281001005555', $request->barcode);

        // الحقول التي أُزيلت من الواجهة تبقى **فارغة** — لا قيم وهمية.
        $this->assertNull($request->generic_name);
        $this->assertNull($request->manufacturer);
        $this->assertNull($request->dosage_form);
        $this->assertNull($request->official_price);
        $this->assertNull($request->subcategory_id);
    }

    /**
     * انحدار: الحقول التي أُزيلت من الواجهة ما زالت **مقبولة** في الـendpoint.
     * السبب: عدم كسر أي عميل قديم/تكامل آخر، ولأن `approve()` ما زال يقرأ
     * `generic_name`/`manufacturer`/`official_price` عند تحويل الطلب لدواء.
     */
    public function test_legacy_payload_with_removed_fields_is_still_accepted(): void
    {
        $this->actingAs($this->user)
            ->post(route('pharmacy.medicines.request.store'), [
                'trade_name' => 'LEGACY REQUEST MED',
                'active_ingredient' => 'TestIngredient',
                'category_id' => $this->category->id,
                'generic_name' => 'LegacyGeneric',
                'manufacturer' => 'LegacyManufacturer',
                'dosage_form' => 'Tablet',
                'official_price' => 12.5,
            ])
            ->assertRedirect();

        $request = MedicineRequest::where('trade_name', 'LEGACY REQUEST MED')->first();

        $this->assertNotNull($request);
        $this->assertSame('LegacyGeneric', $request->generic_name);
        $this->assertSame('LegacyManufacturer', $request->manufacturer);
        $this->assertSame('Tablet', $request->dosage_form);
        $this->assertEqualsWithDelta(12.5, (float) $request->official_price, 0.001);
    }

    /* ==========================================================
       5) الأدمن غير متأثّر — طلب بالحقول الخمسة يُعتمد ويُدخل للمخزون
       ========================================================== */

    public function test_admin_can_approve_a_minimal_request(): void
    {
        $this->actingAs($this->user)->post(route('pharmacy.medicines.request.store'), [
            'trade_name' => 'APPROVABLE MINIMAL MED',
            'active_ingredient' => 'TestIngredient',
            'category_id' => $this->category->id,
        ])->assertRedirect();

        $request = MedicineRequest::where('trade_name', 'APPROVABLE MINIMAL MED')->firstOrFail();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('medicine_requests.approve', $request->id))
            ->assertRedirect();

        $request->refresh();
        $this->assertSame(MedicineRequest::STATUS_APPROVED, $request->status);
        $this->assertNotNull($request->approved_medicine_id);

        // دواء محلي أُنشئ + صفّ مخزون للصيدلية الطالبة
        $this->assertDatabaseHas('medicines', [
            'id' => $request->approved_medicine_id,
            'trade_name' => 'APPROVABLE MINIMAL MED',
        ]);
        $this->assertDatabaseHas('pharmacy_medicines', [
            'pharmacy_id' => $this->pharmacy->id,
            'medicine_id' => $request->approved_medicine_id,
        ]);
    }

    public function test_admin_approve_uses_zero_price_when_official_price_absent(): void
    {
        $this->actingAs($this->user)->post(route('pharmacy.medicines.request.store'), [
            'trade_name' => 'NO PRICE MED',
            'active_ingredient' => 'TestIngredient',
            'category_id' => $this->category->id,
        ])->assertRedirect();

        $request = MedicineRequest::where('trade_name', 'NO PRICE MED')->firstOrFail();
        $this->assertNull($request->official_price, 'الصفحة المبسّطة لا ترسل سعرًا');

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('medicine_requests.approve', $request->id))
            ->assertRedirect();

        $row = \App\Models\PharmacyMedicine::where('pharmacy_id', $this->pharmacy->id)
            ->where('medicine_id', $request->fresh()->approved_medicine_id)
            ->first();

        $this->assertNotNull($row, 'صفّ المخزون أُنشئ رغم غياب السعر');
        $this->assertEqualsWithDelta(0.0, (float) $row->price, 0.001);

        // حماية من الانحدار: لا يُخترع دواء بلا اسم
        $this->assertNotNull(Medicine::find($request->fresh()->approved_medicine_id));
    }
}
