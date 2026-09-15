<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\AccountingLedger;
use App\Services\Accounting\AccountingReports;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * بيانات محاسبة تجريبية **صالحة** — تُكتب في الجداول الحقيقية عبر البوّابة
 * الوحيدة (`AccountingLedger`)، لا بإدخال صفوف مباشر.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * ── لماذا عبر الـLedger لا عبر `Model::create` مباشرة؟ ─────────────────
 * لأن الهدف أن تكون البيانات التجريبية **متّسقة بنفس قوانين الإنتاج**:
 * المخزون مخفوض فعلاً، والصندوق مجموعه من حركات حقيقية، ودين العميل يطابق
 * فواتيره. لو أدخلنا صفوفًا يدويًا لصارت الأرقام «تبدو» صحيحة بينما تشير
 * إلى مخزون لم يُخصم وإلى رصيد صندوق لا يطابق أي حركة — وهذا أسوأ من عدم
 * وجود بيانات: يوهم بأن النظام يعمل.
 *
 * ── idempotent ─────────────────────────────────────────────────────────
 * التشغيل الثاني لا يضاعف شيئًا:
 *   · العملاء/الموردون بـ`updateOrCreate` على (pharmacy_id, phone).
 *   · الفواتير: نتوقّف إن وُجدت أي فاتورة لهذه الصيدلية (البيانات موجودة).
 *   · التصنيفات: `seedDefaultExpenseCategories` نفسه idempotent.
 *
 * ── Deterministic (بلا عشوائية) ────────────────────────────────────────
 * لا `random_int` ولا `rand`. كل الأرقام مكتوبة صريحة كي تكون النتائج
 * قابلة للتكرار: نفس البذرة = نفس الأرقام تمامًا. هذا مهم للاختبارات
 * (assertions على أرقام ثابتة) وللعرض أمام المستخدم.
 * ══════════════════════════════════════════════════════════════════════════
 */
class AccountingDemoSeeder extends Seeder
{
    /**
     * سطور المخزون التي نبني عليها الفواتير.
     * كل مدخل: [barcode, trade_name, unit_price, quantity]
     *
     * نخزّنها هنا لأننا نحتاج `pharmacy_medicine_id` الفعلي عند البيع.
     * الأسعار بلا ضريبة (₪) كما أُقرّ في المواصفات.
     */
    private const CATALOG = [
        ['6281001001234', 'بنادول 500mg', 24.00, 120],
        ['6281001005678', 'اوجمنتين 1g', 39.25, 60],
        ['6281001009012', 'فولتارين جل', 34.00, 40],
        ['6281001003456', 'فيتامين د 50000', 28.00, 35],
        ['6281001007890', 'نيكسيوم 40mg', 52.50, 25],
        ['6281001002345', 'كونجستال', 18.75, 150],
        ['6281001004567', 'أموكسيسيلين 500mg', 22.00, 80],
        ['6281001006789', 'أوميبرازول 20mg', 31.50, 45],
        ['6281001008901', 'سيتال أطفال شراب', 16.50, 55],
        ['6281001001122', 'مرهم بيبانثين', 27.75, 30],
    ];

    /**
     * العملاء التجريبيون. الهاتف واحد لكل عميل حتى يعمل `updateOrCreate`
     * عليه ويبقى التشغيل idempotent.
     */
    private const CUSTOMERS = [
        ['name' => 'أحمد محمود', 'phone' => '0599111223', 'credit_limit' => 500.00],
        ['name' => 'سارة العمري', 'phone' => '0598222334', 'credit_limit' => 300.00],
        ['name' => 'خالد أبو ندى', 'phone' => '0597333445', 'credit_limit' => 0.00],
        ['name' => 'منى الشريف', 'phone' => '0596444556', 'credit_limit' => 1000.00],
        ['name' => 'يوسف حمدان', 'phone' => '0595555667', 'credit_limit' => 200.00],
    ];

    private const SUPPLIERS = [
        ['name' => 'شركة الأمل الطبية', 'company' => 'الأمل', 'phone' => '022951122'],
        ['name' => 'مستودع الشفاء', 'company' => 'الشفاء', 'phone' => '022963344'],
        ['name' => 'دار الدواء', 'company' => 'دار الدواء', 'phone' => '022975566'],
    ];

    public function run(): void
    {
        $pharmacy = $this->resolvePharmacy();

        if (! $pharmacy) {
            $this->command?->warn('AccountingDemoSeeder: لا توجد صيدلية — تجاهل.');

            return;
        }

        // الحماية من التكرار: وجود فاتورة واحدة يعني البيانات موجودة.
        // ⚠️ `Pharmacy` لا يملك علاقة `sales()` — الاستعلام مباشر على Sale.
        if (Sale::query()->forPharmacy($pharmacy->id)->exists()) {
            $this->command?->info('AccountingDemoSeeder: بيانات المحاسبة موجودة مسبقًا — تجاهل.');

            return;
        }

        $userId = $this->resolveUserId($pharmacy);

        $this->command?->info("AccountingDemoSeeder: تهيئة بيانات لصيدلية #{$pharmacy->id}…");

        // (1) التصنيفات الافتراضية — عبر الخدمة نفسها (idempotent).
        AccountingReports::seedDefaultExpenseCategories($pharmacy->id);

        // (2) الأطراف.
        $customers = $this->seedCustomers($pharmacy);
        $suppliers = $this->seedSuppliers($pharmacy);

        // (3) باركودات على مستوى الدواء (medicine_barcodes — لا على المخزون).
        $this->seedBarcodes();

        // (4) مخزون — نضمن وجود سطور للأدوية المستخدمة في الفواتير.
        $inventory = $this->seedInventory($pharmacy);

        // (5) الفواتير موزّعة على آخر 30 يومًا + مصروفات + مشتريات + دفعات.
        $this->seedSales($pharmacy, $userId, $customers, $inventory);
        $this->seedExpenses($pharmacy, $userId);
        $this->seedPurchases($pharmacy, $userId, $suppliers);
        $this->seedPayments($pharmacy, $userId);

        // (6) سحوبات/إيداعات نقدية — آخرًا كي يكون أثرها واضحًا على الرصيد.
        $this->seedCashAdjustments($pharmacy, $userId);

        $this->report($pharmacy);
    }

    // ══════════════════════════════════════════════════════════════════

    /**
     * الصيدلية الأولى المتاحة. نُفضّل الصيدلية التي لها user_id (أي يمكن
     * نسب العمليات لمستخدم حقيقي) لأن `created_by` قد يكون إلزاميًا منطقيًا.
     */
    private function resolvePharmacy(): ?Pharmacy
    {
        return Pharmacy::query()
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->first()
            ?? Pharmacy::query()->orderBy('id')->first();
    }

    /**
     * المستخدم الذي تُنسب إليه العمليات. صاحب الصيدلية هو الأصلح؛
     * وإلا أول مستخدم pharmacy؛ وإلا أول مستخدم. القيمة تُستخدم لعمود
     * `created_by` فقط (لا صلاحيات)، فلا خطر من الاحتياط.
     */
    private function resolveUserId(Pharmacy $pharmacy): int
    {
        if ($pharmacy->user_id) {
            return (int) $pharmacy->user_id;
        }

        $user = User::query()->where('role', 'pharmacy')->orderBy('id')->first()
            ?? User::query()->orderBy('id')->first();

        return (int) ($user->id ?? 1);
    }

    /**
     * إنشاء العملاء. `updateOrCreate` على (pharmacy_id, phone) — نفس
     * فرادة الهاتف داخل الصيدلية المفروضة بفهرس فريد في الميجريشن.
     *
     * @return array<int, Customer> مفهرسة بالترتيب (0-based) لتسهيل الربط
     */
    private function seedCustomers(Pharmacy $pharmacy): array
    {
        $out = [];

        foreach (self::CUSTOMERS as $row) {
            $out[] = Customer::updateOrCreate(
                ['pharmacy_id' => $pharmacy->id, 'phone' => $row['phone']],
                [
                    'name' => $row['name'],
                    'credit_limit' => $row['credit_limit'],
                    'is_active' => true,
                ]
            );
        }

        return $out;
    }

    /** @return array<int, Supplier> */
    private function seedSuppliers(Pharmacy $pharmacy): array
    {
        $out = [];

        foreach (self::SUPPLIERS as $row) {
            $out[] = Supplier::updateOrCreate(
                ['pharmacy_id' => $pharmacy->id, 'phone' => $row['phone']],
                [
                    'name' => $row['name'],
                    'company' => $row['company'],
                    'is_active' => true,
                ]
            );
        }

        return $out;
    }

    /**
     * ضمان وجود سطور مخزون للباركودات المستخدمة في الفواتير.
     *
     * ⚠️⚠️ تصحيح مهم: **`pharmacy_medicines` لا يحتوي عمود `barcode`**.
     * الباركود يسكن في `medicine_barcodes` (فريد عالميًا، مربوط بـ
     * `local_medicine_id` → `medicines.id`). لذلك:
     *
     *   · هنا لا نكتب باركود على سطر المخزون أبدًا (لن ينجح).
     *   · الباركود يُسجَّل في `seedBarcodes()` على مستوى الدواء (لا الصيدلية).
     *   · المفتاح المستخدم للربط في هذه الدالة هو **اسم الدواء**
     *     (`medicines.trade_name`) لأن لا باركود متاح على سطر المخزون.
     *
     * ⚠️ **لا نلمس مخزوناً موجوداً** — لا نكتب كمية فوق كمية حقيقية.
     * لو السطر موجود نستخدمه كما هو (الكمية الحقيقية هي الحقيقة، ويُخصم منها).
     *
     * @return array<string, PharmacyMedicine> مفهرسة بالباركود (لأن الفواتير
     *                                          في السيدر تُكتب بالباركود للقراءة)
     */
    private function seedInventory(Pharmacy $pharmacy): array
    {
        $out = [];

        foreach (self::CATALOG as [$barcode, $tradeName, $price, $quantity]) {
            // الربط باسم الدواء — المفتاح المتاح فعلاً (لا باركود على المخزون).
            $pm = PharmacyMedicine::query()
                ->where('pharmacy_id', $pharmacy->id)
                ->whereHas('medicine', fn ($q) => $q->where('trade_name', $tradeName))
                ->first();

            if ($pm) {
                $out[$barcode] = $pm;

                continue;
            }

            // سطر جديد: ننشئه بكمية كافية كي لا تنفد أثناء توليد الفواتير.
            $medicine = Medicine::query()
                ->where('trade_name', $tradeName)
                ->first();

            if (! $medicine) {
                // لا نختلق دواء في الكتالوج. نتخطّى هذا السطر بأمان،
                // وستُتخطّى الفواتير التي تعتمد عليه تلقائيًا.
                $this->command?->warn("  تخطّي: «{$tradeName}» غير موجود في كتالوج الأدوية.");

                continue;
            }

            $out[$barcode] = PharmacyMedicine::create([
                'pharmacy_id' => $pharmacy->id,
                'medicine_id' => $medicine->id,
                'price' => $price,
                'quantity' => $quantity,
                'is_available' => true,
            ]);
        }

        return $out;
    }

    /**
     * تسجيل باركودات الأدوية في `medicine_barcodes` — المكان الحقيقي للباركود.
     *
     * ملف واحد لكل أدوية الكتالوج المشاركة في السيدر، مفهرس بالباركود.
     *
     * `source` قيمة صريحة `demo-seeder` — مهمة للتدقيق: يمكن لاحقًا حذف
     * كل ما أنشأه السيدر بـ`WHERE source = 'demo-seeder'` بلا لمس بيانات
     * حقيقية وردت من مصادر أخرى.
     *
     * `moh_medicine_id` **إلزامي** (FK غير قابل للـnull على `moh_medicines`).
     * لو الدواء بلا `moh_medicine_id` لا يمكن تسجيل باركود له — نتخطّاه
     * بصراحة بدل اختراع قيمة.
     */
    private function seedBarcodes(): void
    {
        foreach (self::CATALOG as [$barcode, $tradeName, $_price, $_qty]) {
            $medicine = Medicine::query()
                ->where('trade_name', $tradeName)
                ->first();

            if (! $medicine) {
                continue;
            }

            $mohId = $medicine->moh_medicine_id ?? null;

            if ($mohId === null) {
                // لا نختلق moh_medicine_id: القيد الأجنبي سيرفض أي قيمة وهمية.
                continue;
            }

            DB::table('medicine_barcodes')->updateOrInsert(
                ['barcode' => $barcode],
                [
                    'moh_medicine_id' => $mohId,
                    'local_medicine_id' => $medicine->id,
                    'barcode_raw' => $barcode,
                    'barcode_type' => strlen($barcode) === 13 ? 'EAN13' : 'EAN8',
                    'source' => 'demo-seeder',
                    'source_reference' => null,
                    'confidence' => 1.000,
                    'is_verified' => true,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]
            );
        }
    }

    /**
     * توليد الفواتير على آخر 30 يومًا.
     *
     * التوزيع مقصود ليغطّي كل الحالات التي تعرضها الواجهة:
     *   · فواتير نقدية مدفوعة كاملة (الأغلبية)
     *   · فواتير بطاقة
     *   · فواتير آجلة (unpaid) تُنشئ دينًا على العميل
     *   · فواتير مدفوعة جزئيًا (partially_paid)
     *   · فاتورة ملغاة (لاختبار أن التقارير تستثنيها)
     *   · فاتورة بخصم
     *
     * كل فاتورة تُسجَّل عبر `recordSale` — فالمخزون يُخصم والصندوق يُكتب
     * ودين العميل يُحدَّث بقوانين الإنتاج نفسها.
     *
     * @param  array<int, Customer>  $customers
     * @param  array<string, PharmacyMedicine>  $inventory
     */
    private function seedSales(Pharmacy $pharmacy, int $userId, array $customers, array $inventory): void
    {
        // [أيام للخلف، ساعة، باركود، كمية، طريقة الدفع، المدفوع، خصم فاتورة، عميل(index|null), ملغاة]
        $plan = [
            // اليوم
            [0, 10, '6281001001234', 4, 'cash', null, 0, null, false],
            [0, 12, '6281001005678', 2, 'card', null, 0, null, false],
            [0, 14, '6281001002345', 6, 'cash', null, 2.50, 0, false],
            [0, 16, '6281001008901', 3, 'cash', null, 0, null, false],
            // أمس
            [1, 9, '6281001009012', 1, 'cash', null, 0, null, false],
            [1, 11, '6281001007890', 2, 'credit', 0, 0, 1, false],
            [1, 15, '6281001004567', 5, 'cash', null, 0, null, false],
            [1, 18, '6281001006789', 3, 'card', null, 1.50, 2, false],
            // قبل يومين
            [2, 10, '6281001001122', 2, 'cash', null, 0, null, false],
            [2, 13, '6281001001234', 8, 'credit', 100.00, 0, 3, false],
            [2, 17, '6281001003456', 4, 'cash', null, 0, null, false],
            // قبل 3 أيام
            [3, 11, '6281001005678', 3, 'cash', null, 0, null, false],
            [3, 14, '6281001002345', 10, 'cash', null, 5.00, null, false],
            [3, 16, '6281001008901', 2, 'bank_transfer', null, 0, 4, false],
            // قبل 4 أيام
            [4, 9, '6281001006789', 1, 'credit', 0, 0, 0, false],
            [4, 12, '6281001009012', 2, 'card', null, 0, null, false],
            [4, 15, '6281001001122', 3, 'cash', null, 0, null, false],
            // قبل 5 أيام
            [5, 10, '6281001004567', 4, 'credit', 50.00, 0, 2, false],
            [5, 13, '6281001001234', 5, 'cash', null, 0, null, false],
            // قبل 6 أيام
            [6, 11, '6281001005678', 1, 'cash', null, 0, null, false],
            [6, 14, '6281001003456', 6, 'cash', null, 0, null, false],
            // أسبوعين — ملغاة (لاختبار الاستثناء في التقارير)
            [14, 12, '6281001007890', 2, 'cash', null, 0, null, true],
            // 3 أسابيع
            [21, 10, '6281001002345', 7, 'credit', 0, 0, 1, false],
            [21, 15, '6281001008901', 4, 'cash', null, 0, null, false],
            // 4 أسابيع
            [28, 11, '6281001006789', 2, 'cash', null, 0, null, false],
            [28, 16, '6281001001122', 5, 'cash', null, 3.00, null, false],
        ];

        foreach ($plan as $row) {
            [$daysAgo, $hour, $barcode, $qty, $method, $paidOverride, $discount, $customerIdx, $cancel] = $row;

            $pm = $inventory[$barcode] ?? null;
            if (! $pm) {
                continue; // دواء غير متاح في الكتالوج — تخطّي بأمان
            }

            $soldAt = Carbon::today()
                ->subDays($daysAgo)
                ->setTime($hour, (15 + ($daysAgo * 7)) % 60, 0);

            $customer = $customerIdx === null ? null : $customers[$customerIdx];

            $payload = [
                'customer_id' => $customer?->id,
                'payment_method' => $method,
                'discount' => $discount,
                'sold_at' => $soldAt,
                'notes' => null,
                'items' => [[
                    'medicine_id' => $pm->medicine_id,
                    'pharmacy_medicine_id' => $pm->id,
                    'medicine_name' => $pm->medicine->trade_name ?? ('دواء #'.$pm->id),
                    // لقطة الباركود على البند: القيمة من خطة البيع (مصدرها
                    // `medicine_barcodes`)، لا من سطر المخزون الذي لا يحمل باركودًا.
                    'barcode' => $barcode,
                    'unit_price' => (float) $pm->price,
                    'quantity' => $qty,
                    'line_discount' => 0,
                ]],
            ];

            if ($paidOverride !== null) {
                $payload['paid'] = $paidOverride;
            }

            try {
                $sale = AccountingLedger::recordSale($pharmacy->id, $userId, $payload);

                if ($cancel) {
                    AccountingLedger::cancelSale($sale, $userId);
                }
            } catch (\InvalidArgumentException $e) {
                // كميات غير كافية (نظريًا لا تحدث لأننا نبدأ بكمية وافرة) —
                // نتخطّى بلا كسر السيدر.
                $this->command?->warn("  تخطّي فاتورة {$barcode}: {$e->getMessage()}");
            }
        }
    }

    /**
     * مصروفات شهرية نموذجية. أغلبها نقدي (يخرج من الصندوق) وبعضها تحويل
     * بنكي (لا يمسّ الصندوق) — وهذا الفرق مقصود لاختبار التمييز.
     */
    private function seedExpenses(Pharmacy $pharmacy, int $userId): void
    {
        $categories = AccountingReports::expenseCategories($pharmacy->id)
            ->keyBy('key');

        // [أيام للخلف، مفتاح التصنيف، المبلغ، الوصف، طريقة الدفع]
        $plan = [
            [1, 'electricity', 420.00, 'فاتورة كهرباء الشهر', 'bank_transfer'],
            [2, 'internet', 150.00, 'اشتراك إنترنت', 'bank_transfer'],
            [3, 'transport', 85.00, 'مواصلات توصيل طلبية', 'cash'],
            [5, 'maintenance', 180.00, 'صيانة ثلاجة الأدوية', 'cash'],
            [8, 'rent', 1800.00, 'إيجار المحل — الشهر الحالي', 'bank_transfer'],
            [10, 'salaries', 3200.00, 'رواتب الموظفين', 'bank_transfer'],
            [12, 'water', 95.00, 'فاتورة مياه', 'cash'],
            [18, 'other', 240.00, 'مستلزمات مكتبية', 'cash'],
            [22, 'taxes', 310.00, 'رسوم بلدية', 'bank_transfer'],
            [27, 'transport', 60.00, 'مواصلات', 'cash'],
        ];

        foreach ($plan as [$daysAgo, $key, $amount, $description, $method]) {
            $category = $categories->get($key);

            AccountingLedger::recordExpense($pharmacy->id, $userId, [
                'expense_category_id' => $category?->id,
                'category_key' => $key,
                'category_name' => $category?->name_ar
                    ?? __('accounting.expenses.category.'.$key),
                'amount' => $amount,
                'description' => $description,
                'payment_method' => $method,
                'expense_date' => Carbon::today()->subDays($daysAgo),
            ]);
        }
    }

    /**
     * أوامر شراء — تُنشئ ذممًا للموردين (ما علينا لهم).
     *
     * ⚠️ `purchases` لا يمرّ عبر `AccountingLedger` لأن المشروع لم يُعرّف
     * `recordPurchase` (المواصفات طلبت البيع/المصروف/الدفعات/الصندوق فقط).
     * لذا نُدخل الصفوف مباشرة **مع تعديل رصيد المورد يدويًا في نفس المعاملة**
     * كي لا يبقى الرصيد منفصلًا عن أوامر الشراء.
     */
    private function seedPurchases(Pharmacy $pharmacy, int $userId, array $suppliers): void
    {
        // [أيام للخلف، مورد(index)، الإجمالي، المدفوع، الحالة، طريقة الدفع]
        $plan = [
            [7, 0, 1920.00, 500.00, 'partially_paid', 'credit'],
            [16, 1, 1450.00, 0.00, 'unpaid', 'credit'],
            [24, 2, 860.00, 860.00, 'paid', 'bank_transfer'],
        ];

        \Illuminate\Support\Facades\DB::transaction(function () use ($pharmacy, $userId, $suppliers, $plan) {
            foreach ($plan as [$daysAgo, $supplierIdx, $total, $paid, $status, $method]) {
                $supplier = $suppliers[$supplierIdx] ?? null;
                $remaining = round($total - $paid, 2);
                $date = Carbon::today()->subDays($daysAgo)->setTime(10, 30, 0);

                $purchase = \App\Models\Purchase::create([
                    'pharmacy_id' => $pharmacy->id,
                    'supplier_id' => $supplier?->id,
                    'supplier_name' => $supplier?->name,
                    'number' => AccountingLedger::nextPurchaseNumber($pharmacy->id),
                    'subtotal' => $total,
                    'discount' => 0,
                    'total' => $total,
                    'paid' => $paid,
                    'remaining' => $remaining,
                    'payment_method' => $method,
                    'status' => $status,
                    'purchased_at' => $date,
                    'created_by' => $userId,
                ]);

                // الذمة: ما تبقّى غير مدفوع يصير دينًا للمورد.
                if ($supplier && $remaining > 0) {
                    $supplier->increment('current_balance', $remaining);
                }
            }
        });
    }

    /**
     * دفعات: تحصيل من عملائنا (يُنقص دينهم ويزيد الصندوق)، ودفعة لمورّد
     * (تُنقص ما علينا وتُخرج نقدًا). كلها عبر الـLedger (سيُحدّث الأرصدة).
     */
    private function seedPayments(Pharmacy $pharmacy, int $userId): void
    {
        // تحصيلات من العملاء الذين عليهم دين فعلي.
        $debtors = Customer::query()
            ->forPharmacy($pharmacy->id)
            ->where('current_balance', '>', 0)
            ->orderBy('id')
            ->get();

        foreach ($debtors as $index => $customer) {
            // نُسدّد جزءًا فقط (لا الكل) — يبقى بعض الدين ظاهرًا في التقارير.
            $balance = (float) $customer->current_balance;
            $amount = round(min($balance, 100.00), 2);

            if ($amount < 0.01) {
                continue;
            }

            AccountingLedger::recordCustomerPayment($pharmacy->id, $userId, $customer, [
                'amount' => $amount,
                'payment_method' => $index % 2 === 0 ? 'cash' : 'card',
                'reference' => 'RCP-'.str_pad((string) (100 + $index), 4, '0', STR_PAD_LEFT),
                'description' => 'تحصيل دفعة',
                'paid_at' => Carbon::today()->subDays(1)->setTime(11, 20, 0),
            ]);
        }

        // دفعة لمورّد عليه رصيد.
        $supplier = Supplier::query()
            ->forPharmacy($pharmacy->id)
            ->where('current_balance', '>', 0)
            ->orderBy('id')
            ->first();

        if ($supplier) {
            $amount = round(min((float) $supplier->current_balance, 300.00), 2);

            if ($amount >= 0.01) {
                AccountingLedger::recordSupplierPayment($pharmacy->id, $userId, $supplier, [
                    'amount' => $amount,
                    'payment_method' => 'cash',
                    'reference' => 'PAY-0101',
                    'description' => 'دفعة للمورّد',
                    'paid_at' => Carbon::today()->subDays(2)->setTime(13, 45, 0),
                ]);
            }
        }
    }

    /**
     * سحوبات/إيداعات نقدية — تُنشئ أثرًا واضحًا على رصيد الصندوق وتُعطي
     * شاشة الصندوق حركات يدوية حقيقية (لا مصدر تلقائي).
     */
    private function seedCashAdjustments(Pharmacy $pharmacy, int $userId): void
    {
        // [أيام للخلف، الاتجاه (in|out)، المبلغ، السبب]
        $plan = [
            [9, \App\Models\CashMovement::DIRECTION_OUT, 400.00, 'سحب نقدي — مصاريف شخصية'],
            [6, \App\Models\CashMovement::DIRECTION_IN, 250.00, 'إيداع رأس مال إضافي'],
            [3, \App\Models\CashMovement::DIRECTION_OUT, 200.00, 'سحب نقدي — طارئ'],
        ];

        foreach ($plan as [$daysAgo, $direction, $amount, $reason]) {
            AccountingLedger::recordCashAdjustment(
                $pharmacy->id,
                $userId,
                $direction,
                $amount,
                $reason,
                Carbon::today()->subDays($daysAgo)->setTime(17, 0, 0),
            );
        }
    }

    /** ملخّص ما أُدخل — يُطبع في الطرفية كي يتحقّق المشغّل بنفسه. */
    private function report(Pharmacy $pharmacy): void
    {
        $id = $pharmacy->id;

        $this->command?->info('✓ بيانات المحاسبة التجريبية جاهزة:');
        $this->command?->line('  · فواتير: '.Sale::query()->forPharmacy($id)->count());
        $this->command?->line('  · عملاء: '.Customer::query()->forPharmacy($id)->count());
        $this->command?->line('  · موردون: '.Supplier::query()->forPharmacy($id)->count());
        $this->command?->line('  · مصروفات: '.\App\Models\Expense::query()->forPharmacy($id)->count());
        $this->command?->line('  · حركات صندوق: '.\App\Models\CashMovement::query()->forPharmacy($id)->count());
        $this->command?->line('  · رصيد الصندوق: '.AccountingReports::money(AccountingReports::cashBalance($id)));
        $this->command?->line('  · ذمم العملاء: '.AccountingReports::money(AccountingReports::customerReceivablesTotal($id)));
        $this->command?->line('  · ذمم الموردين: '.AccountingReports::money(AccountingReports::supplierPayablesTotal($id)));
    }
}
