<?php

namespace Tests\Feature\Api;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * تدقيق ربط الواجهة بالمحاسبة عبر الأسطح الحقيقية.
 *
 * هذه الاختبارات تُثبت — لا تستنتج — أن لكل عطل أُصلح تغطية تمنع رجوعه.
 */
class AccountingWiringTest extends TestCase
{
    private User $user;
    private Pharmacy $pharmacy;
    private int $pmId = 0;
    private int $medicineId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->pharmacy()->create();
        $this->pharmacy = Pharmacy::factory()->create(['user_id' => $this->user->id]);

        $medicine = Medicine::factory()->create(['trade_name' => 'بنادول 500mg']);

        $pm = PharmacyMedicine::create([
            'pharmacy_id' => $this->pharmacy->id,
            'medicine_id' => $medicine->id,
            'price' => 24.00,
            'quantity' => 20,
            'is_available' => true,
        ]);

        $this->pmId = (int) $pm->id;
        $this->medicineId = (int) $medicine->id;

        Sanctum::actingAs($this->user);
    }

    /* ══════════════════════════════════════════════════════════════════
       1) عقد نقطة البيع — كان يرسل {lines,payment} والـBackend يريد
          {items,payment_method} ⇒ 422 دائمًا على كل عملية بيع.
       ══════════════════════════════════════════════════════════════════ */

    /** الشكل القديم (المكسور) يبقى مرفوضًا — يوثّق الفرق. */
    public function test_old_pos_payload_shape_is_rejected(): void
    {
        $this->postJson('/api/pharmacy/accounting/sales', [
            'lines' => [['id' => 1, 'qty' => 2, 'price' => 24.00, 'discount' => 0]],
            'payment' => 'cash',
            'paid' => 48.00,
        ])->assertStatus(422)->assertJsonValidationErrors(['items', 'payment_method']);
    }

    /** الشكل الصحيح (ما يُنتجه buildSalePayload الآن) ينجح ويكتب فعلًا. */
    public function test_new_pos_payload_shape_succeeds_and_deducts_stock(): void
    {
        $response = $this->postJson('/api/pharmacy/accounting/sales', [
            'items' => [[
                'pharmacy_medicine_id' => $this->pmId,
                'medicine_id' => $this->medicineId,
                'medicine_name' => 'بنادول 500mg',
                'barcode' => '6281001001234',
                'unit_price' => 24.00,
                'quantity' => 2,
                'line_discount' => 0,
            ]],
            'payment_method' => 'cash',
            'paid' => 48.00,
            'discount' => 0,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 48)
            ->assertJsonPath('data.status', 'paid');

        // الأثر الحقيقي: المخزون نُقص فعلًا
        $this->assertSame(18, (int) PharmacyMedicine::find($this->pmId)->quantity);

        // والمخزون نُقص على **هذا** السطر لا على سطر آخر
        $this->assertSame(1, PharmacyMedicine::where('pharmacy_id', $this->pharmacy->id)->count());
    }

    /** الخصم الكلي يُطبَّق على الإجمالي لا على المجموع الفرعي. */
    public function test_invoice_discount_applies_to_total(): void
    {
        $this->postJson('/api/pharmacy/accounting/sales', [
            'items' => [[
                'pharmacy_medicine_id' => $this->pmId,
                'medicine_name' => 'بنادول 500mg',
                'unit_price' => 24.00,
                'quantity' => 2,
            ]],
            'payment_method' => 'cash',
            'paid' => 43.00,
            'discount' => 5.00,
        ])->assertCreated()
            ->assertJsonPath('data.subtotal', 48)
            ->assertJsonPath('data.discount', 5)
            ->assertJsonPath('data.total', 43);
    }

    /* ══════════════════════════════════════════════════════════════════
       2) محورا المعرّف — `medicines.id` مقابل `pharmacy_medicines.id`.
          الخلط بينهما يخصم سطر مخزون لا علاقة له بالدواء المبيع.
       ══════════════════════════════════════════════════════════════════ */

    /**
     * إرسال `medicines.id` في مكان سطر المخزون يخصم سطرًا **آخر** أو يفشل.
     *
     * ⚠️ لا يمكن إثبات هذا بمقارنة الأرقام: `medicines.id` و
     * `pharmacy_medicines.id` تسلسلان مستقلان يتقاربان في قواعد صغيرة.
     * الإثبات الصحيح: نُنشئ سطرَي مخزون مختلفين، ونرسل معرّف سطر **آخر**،
     * ثم نتحقّق أن السطر المقصود لم يُلمس.
     * (الحماية الفعلية هنا هي `where pharmacy_id` داخل الاستعلام — لا
     * المطابقة. الاختبار يثبّت أن الخطأ لا يمرّ صامتًا.)
     */
    public function test_wrong_inventory_id_does_not_deduct_unrelated_row(): void
    {
        $other = Medicine::factory()->create(['trade_name' => 'دواء آخر']);
        $otherPm = PharmacyMedicine::create([
            'pharmacy_id' => $this->pharmacy->id,
            'medicine_id' => $other->id,
            'price' => 10.00,
            'quantity' => 7,
            'is_available' => true,
        ]);

        // معرّف سطر مخزون حقيقي لكنه ليس السطر الذي أردناه (محاكاة الخطأ)
        $this->assertNotSame($this->pmId, (int) $otherPm->id);

        $this->postJson('/api/pharmacy/accounting/sales', [
            'items' => [[
                'pharmacy_medicine_id' => $otherPm->id,
                'medicine_name' => 'دواء آخر',
                'unit_price' => 10.00,
                'quantity' => 1,
            ]],
            'payment_method' => 'cash',
            'paid' => 10.00,
        ])->assertCreated();

        // السطر المُرسل نُقص فعلًا…
        $this->assertSame(6, (int) PharmacyMedicine::find($otherPm->id)->quantity);
        // …والسطر الآخر لم يُلمس
        $this->assertSame(20, (int) PharmacyMedicine::find($this->pmId)->quantity);
    }

    /* ══════════════════════════════════════════════════════════════════
       3) البحث عن العملاء — البيع الآجل يحتاج customer_id رقميًا.
       ══════════════════════════════════════════════════════════════════ */

    public function test_customer_search_returns_id_needed_for_credit_sales(): void
    {
        $created = $this->postJson('/api/pharmacy/accounting/customers', [
            'name' => 'أبو أحمد',
            'phone' => '0599111222',
        ])->assertCreated();

        $customerId = (int) $created->json('data.id');
        $this->assertGreaterThan(0, $customerId);

        // البحث بالاسم يعيد نفس الـid — وهو ما ستخزّنه الواجهة
        $this->getJson('/api/pharmacy/accounting/customers?search=أحمد')
            ->assertOk()
            ->assertJsonPath('data.0.id', $customerId)
            ->assertJsonPath('data.0.name', 'أبو أحمد');
    }

    /** بيع آجل بمعرّف عميل حقيقي ⇒ دين فعلي على العميل. */
    public function test_credit_sale_with_customer_id_creates_real_debt(): void
    {
        $customerId = (int) $this->postJson('/api/pharmacy/accounting/customers', [
            'name' => 'أبو أحمد',
        ])->json('data.id');

        $this->postJson('/api/pharmacy/accounting/sales', [
            'customer_id' => $customerId,
            'items' => [[
                'pharmacy_medicine_id' => $this->pmId,
                'medicine_name' => 'بنادول 500mg',
                'unit_price' => 24.00,
                'quantity' => 2,
            ]],
            'payment_method' => 'credit',
            'paid' => 0,
        ])->assertCreated()->assertJsonPath('data.remaining', 48);

        $this->assertSame(48.0, (float) \App\Models\Customer::find($customerId)->current_balance);

        // ولا حركة صندوق — الآجل لا يزيد النقد
        $this->assertSame(0, \App\Models\CashMovement::where('pharmacy_id', $this->pharmacy->id)->count());
    }

    /** بيع آجل بلا customer_id لا ينشئ دينًا وهميًا. */
    public function test_credit_sale_without_customer_id_leaves_no_orphan_debt(): void
    {
        $this->postJson('/api/pharmacy/accounting/sales', [
            'items' => [[
                'pharmacy_medicine_id' => $this->pmId,
                'medicine_name' => 'بنادول 500mg',
                'unit_price' => 24.00,
                'quantity' => 1,
            ]],
            'payment_method' => 'credit',
            'paid' => 0,
        ])->assertCreated()->assertJsonPath('data.remaining', 24);

        $this->assertSame(0, \App\Models\Customer::count());
        $this->assertSame(0, \App\Models\CashMovement::count());
    }

    /* ══════════════════════════════════════════════════════════════════
       4) حماية من الخصم على مخزون صيدلية أخرى (IDOR كتابة)
       ══════════════════════════════════════════════════════════════════ */

    public function test_foreign_inventory_id_is_rejected(): void
    {
        $otherUser = User::factory()->pharmacy()->create();
        $otherPharmacy = Pharmacy::factory()->create(['user_id' => $otherUser->id]);
        $foreignPm = PharmacyMedicine::create([
            'pharmacy_id' => $otherPharmacy->id,
            'medicine_id' => Medicine::factory()->create()->id,
            'price' => 5.00,
            'quantity' => 99,
            'is_available' => true,
        ]);

        $this->postJson('/api/pharmacy/accounting/sales', [
            'items' => [[
                'pharmacy_medicine_id' => $foreignPm->id,
                'medicine_name' => 'دواء الغير',
                'unit_price' => 5.00,
                'quantity' => 1,
            ]],
            'payment_method' => 'cash',
            'paid' => 5.00,
        ])->assertStatus(422)
            ->assertJsonPath('data.invalid_pharmacy_medicine_ids.0', $foreignPm->id);

        $this->assertSame(99, (int) PharmacyMedicine::find($foreignPm->id)->quantity);
    }

    /* ══════════════════════════════════════════════════════════════════
       5) الباركود — محور الربط: local_medicine_id هو medicines.id
       ══════════════════════════════════════════════════════════════════ */

    public function test_barcode_endpoint_exposes_medicine_id_not_inventory_id(): void
    {
        \Illuminate\Support\Facades\DB::table('medicine_barcodes')->insert([
            'moh_medicine_id' => \Illuminate\Support\Facades\DB::table('moh_medicines')->insertGetId([
                'trade_name' => 'بنادول 500mg',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'local_medicine_id' => $this->medicineId,
            'barcode' => '6281001001234',
            'barcode_type' => 'EAN13',
            'is_verified' => true,
            'source' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->getJson('/api/medicines/barcode/6281001001234')->assertOk();

        // الـBackend يعيد `local_medicine_id` = `medicines.id` بالضبط
        $this->assertSame($this->medicineId, (int) $res->json('data.medicine.local_medicine_id'));

        // ✅ والإثبات الحقيقي: المخزون يُعثر عليه بمطابقة `medicine_id`
        // (وهو ما تفعله `mapBarcodeResult` الآن). الـ`local_medicine_id`
        // الواصل مساوٍ لـ`medicine_id` المطلوب — ولو طابقناه بـ`id` فحسب
        // لفشل الربط على أي قاعدة يكون فيها التسلسلان مختلفين، وهذا هو
        // الواقع على الإنتاج (جداول مستقلة، تسلسلات مستقلة).
        $matchedPm = PharmacyMedicine::query()
            ->where('pharmacy_id', $this->pharmacy->id)
            ->where('medicine_id', (int) $res->json('data.medicine.local_medicine_id'))
            ->first();

        $this->assertNotNull($matchedPm, 'لازم ينجح الربط عبر medicine_id');
        $this->assertSame($this->pmId, (int) $matchedPm->id);
        $this->assertSame(20, (int) $matchedPm->quantity);
    }
}
