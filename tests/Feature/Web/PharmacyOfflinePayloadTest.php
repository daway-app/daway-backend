<?php

namespace Tests\Feature\Web;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Tests\TestCase;

/**
 * 🔴 حارس انحدار لعطل /pharmacy/medicines المُبلَّغ عنه:
 * «الصف الأول كامل والبقية صور فقط».
 *
 * السبب كان مُهيّئ الأوفلاين (resources/js/offline/render.js) يهدم الجدول ويعيد
 * بناءه بقالب ناقص. الحل: أُلغيت إعادة البناء. لذلك يجب أن تبقى:
 *   1) صفوف Blade كاملة (صورة/إجراءات) — لا يعتمد عليها JS.
 *   2) حمولة الأوفلاين بلا المفتاح الميت `strength`.
 */
class PharmacyOfflinePayloadTest extends TestCase
{
    /**
     * @return array{0: User, 1: Pharmacy}
     */
    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    public function test_medicines_page_renders_full_rows_and_offline_payload(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        $medicine = Medicine::factory()->create([
            'trade_name' => 'Panadol Extra',
            'active_ingredient' => 'Paracetamol',
        ]);
        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 7.5,
            'quantity' => 20,
        ]);

        $response = $this->actingAs($user)->get('/pharmacy/medicines')->assertOk();

        // الحمولة موجودة (يستمر البذر منها).
        $response->assertSee('daway-offline-medicines', false);
        $response->assertSee('Panadol Extra');

        // ⚠️ حارس المضادّ: مفاتيح القالب الكامل في Blade يجب أن تبقى.
        $response->assertSee('med-cell-text', false);
        $response->assertSee('med-ingredient-text', false);

        // زرّا تعديل/حذف موجودان في صفّ Blade.
        $response->assertSee('fa-pen', false);
        $response->assertSee('fa-trash', false);
    }

    public function test_offline_medicines_payload_no_longer_carries_dead_strength_key(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        $medicine = Medicine::factory()->create(['trade_name' => 'PayloadCheck']);
        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 3,
            'quantity' => 5,
        ]);

        $response = $this->actingAs($user)->get('/pharmacy/medicines')->assertOk();

        // استخرج نصّ الحمولة فقط (بين وسم السكربت) لتفادي التقاط `strength` من CSS.
        $html = $response->getContent();
        $start = strpos($html, 'id="daway-offline-medicines"');
        if ($start === false) {
            $start = strpos($html, "id='daway-offline-medicines'");
        }
        $this->assertNotFalse($start, 'لم يُعثر على وسم حمولة الأوفلاين للأدوية.');

        $start = strpos($html, '>', $start) + 1;
        $end = strpos($html, '</script>', $start);
        $payload = substr($html, $start, $end - $start);

        $this->assertStringContainsString('PayloadCheck', $payload);
        $this->assertStringNotContainsString('strength', $payload,
            'مفتاح `strength` ميت (لا عمود في قاعدة البيانات) ويجب ألا يعود للحمولة.');
    }
}
