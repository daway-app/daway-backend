<?php

namespace App\Support\Accounting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * ⚠️ بيانات محاسبية تجريبية (MOCK) — مؤقتة بطبيعتها.
 * ==========================================================
 * لا يوجد Backend محاسبة في Daway بعد. هذا الصف هو **نقطة الفصل الوحيدة**
 * بين الواجهة والبيانات: كل صفحة محاسبية تقرأ من هنا.
 *
 * عند إضافة الـAPI الحقيقي:
 *   1. أبقِ أسماء المفاتيح كما هي (عقد داخلي ثابت للـviews).
 *   2. استبدل جسم الدوال باستعلامات Eloquent/Service حقيقية.
 *   3. لا تعدّل الـviews ولا الـJS — العقد نفسه.
 *
 * كل الأرقام هنا ثابتة (لا rand) حتى تكون اللقطات والاختبارات قابلة للتكرار.
 * المبالغ بالشيكل (₪) — نفس عملة المشروع.
 *
 * ملاحظة مهمة: لا تُرسَل هذه البيانات إلى أي API مُختلَق. صفحة POS تُرسل
 * لمسارين **موجودين فعلاً**: GET /api/medicines/search و
 * GET /api/medicines/barcode/{barcode} — والباقي يبقى محليًا.
 */
final class AccountingMockData
{
    public const CURRENCY = '₪';

    /** الحد الآمن لرصيد الصندوق — تحت هذا الرقم يظهر تنبيه «رصيد منخفض». */
    public const LOW_CASH_THRESHOLD = 500.00;

    /**
     * فئات المصروفات (يُعاد استخدامها في الفلترة وفي الرسم الدائري).
     */
    public static function expenseCategories(): array
    {
        return [
            'salaries' => __('accounting.expenses.category.salaries'),
            'rent' => __('accounting.expenses.category.rent'),
            'electricity' => __('accounting.expenses.category.electricity'),
            'water' => __('accounting.expenses.category.water'),
            'internet' => __('accounting.expenses.category.internet'),
            'transport' => __('accounting.expenses.category.transport'),
            'maintenance' => __('accounting.expenses.category.maintenance'),
            'taxes' => __('accounting.expenses.category.taxes'),
            'other' => __('accounting.expenses.category.other'),
        ];
    }

    /**
     * مؤشرات الأداء الرئيسة لصفحة النظرة العامة.
     * المفاتيح: value (رقم)، format ('money'|'int')، tone (لون الأيقونة)، route (اختياري).
     */
    public static function kpis(): array
    {
        $todaySales = 4285.50;
        $todayPurchases = 1920.00;
        $todayExpenses = 340.00;
        $cashBalance = 386.75;
        $outstanding = 5640.00;

        return [
            [
                'key' => 'today_sales',
                'label' => __('accounting.overview.kpi_today_sales'),
                'value' => $todaySales,
                'format' => 'money',
                'icon' => 'fas fa-cash-register',
                'tone' => 'teal',
                'href' => route('pharmacy.accounting.sales.index'),
            ],
            [
                'key' => 'today_purchases',
                'label' => __('accounting.overview.kpi_today_purchases'),
                'value' => $todayPurchases,
                'format' => 'money',
                'icon' => 'fas fa-truck-ramp-box',
                'tone' => 'blue',
                'href' => null,
            ],
            [
                'key' => 'today_expenses',
                'label' => __('accounting.overview.kpi_today_expenses'),
                'value' => $todayExpenses,
                'format' => 'money',
                'icon' => 'fas fa-receipt',
                'tone' => 'orange',
                'href' => null,
            ],
            [
                'key' => 'today_profit',
                'label' => __('accounting.overview.kpi_today_profit'),
                'value' => $todaySales - $todayPurchases - $todayExpenses,
                'format' => 'money',
                'icon' => 'fas fa-chart-line',
                'tone' => 'green',
                'href' => null,
            ],
            [
                'key' => 'cash_balance',
                'label' => __('accounting.overview.kpi_cash_balance'),
                'value' => $cashBalance,
                'format' => 'money',
                'icon' => 'fas fa-wallet',
                'tone' => $cashBalance < self::LOW_CASH_THRESHOLD ? 'red' : 'gray',
                'href' => null,
            ],
            [
                'key' => 'outstanding_debts',
                'label' => __('accounting.overview.kpi_outstanding_debts'),
                'value' => $outstanding,
                'format' => 'money',
                'icon' => 'fas fa-hand-holding-dollar',
                'tone' => 'red',
                'href' => null,
            ],
        ];
    }

    /**
     * سلاسل المبيعات حسب الفترة. الشكل: [rangeKey => ['labels'=>[], 'data'=>[]]]
     * ranges: today | 7d | 30d | month
     */
    public static function salesSeries(): array
    {
        return [
            'today' => [
                'labels' => ['08', '10', '12', '14', '16', '18', '20'],
                'data' => [120, 340, 610, 480, 720, 540, 260],
            ],
            '7d' => [
                'labels' => [
                    __('accounting.common.today'),
                    '−1', '−2', '−3', '−4', '−5', '−6',
                ],
                'data' => [4285, 3910, 4620, 3180, 5040, 2760, 3350],
            ],
            '30d' => [
                'labels' => ['1', '5', '10', '15', '20', '25', '30'],
                'data' => [22400, 24850, 21300, 26900, 29100, 25600, 24350],
            ],
            'month' => [
                'labels' => ['W1', 'W2', 'W3', 'W4'],
                'data' => [38600, 41250, 36900, 43800],
            ],
        ];
    }

    /**
     * توزيع المصروفات (للرسم الدائري + قائمة الأسطورة).
     */
    public static function expenseBreakdown(): array
    {
        $rows = [
            ['category' => 'salaries', 'amount' => 3200.00],
            ['category' => 'rent', 'amount' => 1800.00],
            ['category' => 'electricity', 'amount' => 420.00],
            ['category' => 'internet', 'amount' => 150.00],
            ['category' => 'transport', 'amount' => 260.00],
            ['category' => 'maintenance', 'amount' => 180.00],
            ['category' => 'other', 'amount' => 240.00],
        ];

        $categories = self::expenseCategories();
        $total = array_sum(array_column($rows, 'amount'));

        return array_map(function (array $row) use ($categories, $total) {
            return [
                'category' => $row['category'],
                'label' => $categories[$row['category']] ?? $row['category'],
                'amount' => $row['amount'],
                'percentage' => $total > 0 ? round(($row['amount'] / $total) * 100, 1) : 0.0,
            ];
        }, $rows);
    }

    /**
     * آخر الحركات المالية (جدول النظرة العامة).
     */
    public static function recentTransactions(int $limit = 8): array
    {
        $rows = [
            ['days_ago' => 0, 'time' => '14:32', 'type' => 'sale', 'reference' => 'INV-1042', 'description' => 'فاتورة بيع — بنادول ٥٠٠mg ×4', 'amount' => 96.00, 'method' => 'cash', 'status' => 'paid'],
            ['days_ago' => 0, 'time' => '12:05', 'type' => 'expense', 'reference' => 'EXP-0231', 'description' => 'فاتورة كهرباء', 'amount' => -420.00, 'method' => 'bank_transfer', 'status' => 'paid'],
            ['days_ago' => 0, 'time' => '10:41', 'type' => 'sale', 'reference' => 'INV-1041', 'description' => 'فاتورة بيع — اوجمنتين ١g ×2', 'amount' => 78.50, 'method' => 'card', 'status' => 'paid'],
            ['days_ago' => 1, 'time' => '18:20', 'type' => 'purchase', 'reference' => 'PUR-0311', 'description' => 'شراء من :supplier1', 'amount' => -1920.00, 'method' => 'credit', 'status' => 'partially_paid'],
            ['days_ago' => 1, 'time' => '16:03', 'type' => 'sale', 'reference' => 'INV-1040', 'description' => 'فاتورة بيع — فولتارين جل ×1', 'amount' => 34.00, 'method' => 'cash', 'status' => 'paid'],
            ['days_ago' => 1, 'time' => '09:55', 'type' => 'customer_payment', 'reference' => 'RCP-0088', 'description' => 'تحصيل دفعة من :customer1', 'amount' => 150.00, 'method' => 'cash', 'status' => 'paid'],
            ['days_ago' => 2, 'time' => '17:12', 'type' => 'sale', 'reference' => 'INV-1039', 'description' => 'فاتورة بيع — فيتامين د ×3', 'amount' => 84.00, 'method' => 'credit', 'status' => 'unpaid'],
            ['days_ago' => 2, 'time' => '11:30', 'type' => 'withdrawal', 'reference' => 'WDR-0012', 'description' => 'سحب نقدي من الصندوق', 'amount' => -300.00, 'method' => 'cash', 'status' => 'paid'],
        ];

        foreach ($rows as &$row) {
            $row['description'] = str_replace(
                [':supplier1', ':customer1'],
                [self::supplierName(1), self::customerName(1)],
                $row['description']
            );
            $row['date'] = Carbon::today()->subDays($row['days_ago']);
        }
        unset($row);

        return array_slice($rows, 0, $limit);
    }

    /**
     * التنبيهات (بطاقة «يحتاج انتباهك»).
     */
    public static function alerts(): array
    {
        return [
            [
                'severity' => 'warning',
                'icon' => 'fas fa-truck-ramp-box',
                'title' => __('accounting.overview.alert_supplier_due'),
                'description' => __('accounting.overview.alert_supplier_due_desc', [
                    'supplier' => self::supplierName(1),
                    'amount' => self::money(1450.00),
                ]),
            ],
            [
                'severity' => 'danger',
                'icon' => 'fas fa-user-clock',
                'title' => __('accounting.overview.alert_customer_overdue'),
                'description' => __('accounting.overview.alert_customer_overdue_desc', [
                    'customer' => self::customerName(2),
                    'days' => 21,
                ]),
            ],
            [
                'severity' => 'danger',
                'icon' => 'fas fa-wallet',
                'title' => __('accounting.overview.alert_low_cash'),
                'description' => __('accounting.overview.alert_low_cash_desc', [
                    'amount' => self::money(386.75),
                ]),
            ],
            [
                'severity' => 'info',
                'icon' => 'fas fa-door-open',
                'title' => __('accounting.overview.alert_register_unclosed'),
                'description' => __('accounting.overview.alert_register_unclosed_desc', [
                    'register' => 1,
                    'time' => '08:00',
                ]),
            ],
        ];
    }

    /**
     * بيانات أولية لسلة POS (تُبَثّ إلى JS كحمولة JSON).
     * هذه قائمة "مخزون مصغّرة" تُستخدم فقط عند تعذّر الوصول للـAPI —
     * أي أن البحث يعمل بلا شبكة بلا اختراع endpoint.
     */
    public static function posCatalog(): array
    {
        return [
            ['id' => 1, 'barcode' => '6281001001234', 'trade_name' => 'بنادول 500mg', 'active_ingredient' => 'Paracetamol', 'price' => 24.00, 'quantity' => 42],
            ['id' => 2, 'barcode' => '6281001005678', 'trade_name' => 'اوجمنتين 1g', 'active_ingredient' => 'Amoxicillin/Clavulanic acid', 'price' => 39.25, 'quantity' => 18],
            ['id' => 3, 'barcode' => '6281001009012', 'trade_name' => 'فولتارين جل', 'active_ingredient' => 'Diclofenac', 'price' => 34.00, 'quantity' => 7],
            ['id' => 4, 'barcode' => '6281001003456', 'trade_name' => 'فيتامين د 50000', 'active_ingredient' => 'Cholecalciferol', 'price' => 28.00, 'quantity' => 0],
            ['id' => 5, 'barcode' => '6281001007890', 'trade_name' => 'نيكسيوم 40mg', 'active_ingredient' => 'Esomeprazole', 'price' => 52.50, 'quantity' => 11],
            ['id' => 6, 'barcode' => '6281001002345', 'trade_name' => 'كونجستال', 'active_ingredient' => 'Paracetamol/Chlorpheniramine', 'price' => 18.75, 'quantity' => 63],
        ];
    }

    /**
     * مسارات جلسة المسح بالهاتف — **تُرجع مصفوفة فارغة الآن**.
     *
     * ⚠️ هذا هو الفصل الصريح بين الواجهة والافتراض:
     *   `POST /api/pharmacy/scan-sessions` وأخواتها **غير موجودة** في المشروع.
     *   لا نخترعها ولا نبنيها. المصفوفة الفارغة تجعل الواجهة:
     *     - تدخل وضع «غير متاح» بصراحة،
     *     - وتُظهر وسم «وضع تجريبي» في النافذة.
     *
     * عند تنفيذها في الباك-إند: أرجع المفاتيح الخمسة أدناه (store/show/pair/
     * barcode/destroy) بالمُعامل `{id}` حيث يلزم، وستُستخدم تلقائيًا بلا أي
     * تعديل في JS.
     *
     * @return array{store?:string, show?:string, pair?:string, barcode?:string, destroy?:string}
     */
    public static function scanSessionEndpoints(): array
    {
        return [];
    }

    /**
     * باركودات للعرض التجريبي في نافذة المسح بالهاتف.
     *
     * ⚠️ **لا تُستخدم في الإنتاج.** كلها قيم تشغيلية لا تُكتب في قاعدة
     * البيانات، وتُعلَن في الواجهة بوسم «وضع تجريبي». واحدة منها مأخوذة من
     * `posCatalog` (معروفة ⇒ مسار الإضافة)، والأخرى غير مسجَّلة عن قصد
     * (⇒ مسار الربط) لتجربة الحالتين.
     *
     * @return list<string>
     */
    public static function scanSessionDemoBarcodes(): array
    {
        return [
            '6281001001234',   // مسجَّل في الكتالوج المحلي ⇒ بطاقة تأكيد ثم إضافة
            '6289999999999',   // غير مسجَّل ⇒ مسار الربط بدواء موجود
        ];
    }

    /**
     * فواتير البيع لصفحة المبيعات.
     */
    public static function sales(int $limit = 30): array
    {
        $seeds = [
            ['number' => 'INV-1042', 'hours_ago' => 2, 'customer' => null, 'items' => 4, 'subtotal' => 96.00, 'discount' => 0.00, 'paid' => 96.00, 'method' => 'cash', 'status' => 'paid'],
            ['number' => 'INV-1041', 'hours_ago' => 6, 'customer' => 1, 'items' => 2, 'subtotal' => 78.50, 'discount' => 0.00, 'paid' => 78.50, 'method' => 'card', 'status' => 'paid'],
            ['number' => 'INV-1040', 'hours_ago' => 8, 'customer' => null, 'items' => 1, 'subtotal' => 34.00, 'discount' => 0.00, 'paid' => 34.00, 'method' => 'cash', 'status' => 'paid'],
            ['number' => 'INV-1039', 'hours_ago' => 26, 'customer' => 2, 'items' => 3, 'subtotal' => 84.00, 'discount' => 0.00, 'paid' => 0.00, 'method' => 'credit', 'status' => 'unpaid'],
            ['number' => 'INV-1038', 'hours_ago' => 30, 'customer' => 3, 'items' => 5, 'subtotal' => 210.00, 'discount' => 15.00, 'paid' => 100.00, 'method' => 'credit', 'status' => 'partially_paid'],
            ['number' => 'INV-1037', 'hours_ago' => 32, 'customer' => null, 'items' => 2, 'subtotal' => 63.00, 'discount' => 0.00, 'paid' => 63.00, 'method' => 'cash', 'status' => 'paid'],
            ['number' => 'INV-1036', 'hours_ago' => 50, 'customer' => 4, 'items' => 1, 'subtotal' => 120.00, 'discount' => 0.00, 'paid' => 0.00, 'method' => 'credit', 'status' => 'unpaid'],
            ['number' => 'INV-1035', 'hours_ago' => 54, 'customer' => null, 'items' => 6, 'subtotal' => 147.50, 'discount' => 7.50, 'paid' => 140.00, 'method' => 'card', 'status' => 'paid'],
            ['number' => 'INV-1034', 'hours_ago' => 74, 'customer' => 5, 'items' => 2, 'subtotal' => 58.00, 'discount' => 0.00, 'paid' => 58.00, 'method' => 'cash', 'status' => 'refunded'],
            ['number' => 'INV-1033', 'hours_ago' => 78, 'customer' => null, 'items' => 3, 'subtotal' => 92.75, 'discount' => 0.00, 'paid' => 92.75, 'method' => 'cash', 'status' => 'paid'],
            ['number' => 'INV-1032', 'hours_ago' => 96, 'customer' => 6, 'items' => 4, 'subtotal' => 168.00, 'discount' => 8.00, 'paid' => 160.00, 'method' => 'bank_transfer', 'status' => 'paid'],
            ['number' => 'INV-1031', 'hours_ago' => 100, 'customer' => null, 'items' => 1, 'subtotal' => 24.00, 'discount' => 0.00, 'paid' => 0.00, 'method' => 'cash', 'status' => 'cancelled'],
            ['number' => 'INV-1030', 'hours_ago' => 122, 'customer' => 7, 'items' => 7, 'subtotal' => 305.00, 'discount' => 25.00, 'paid' => 180.00, 'method' => 'credit', 'status' => 'partially_paid'],
            ['number' => 'INV-1029', 'hours_ago' => 126, 'customer' => null, 'items' => 2, 'subtotal' => 47.50, 'discount' => 0.00, 'paid' => 47.50, 'method' => 'cash', 'status' => 'paid'],
            ['number' => 'INV-1028', 'hours_ago' => 146, 'customer' => 1, 'items' => 3, 'subtotal' => 112.00, 'discount' => 0.00, 'paid' => 112.00, 'method' => 'card', 'status' => 'paid'],
            ['number' => 'INV-1027', 'hours_ago' => 150, 'customer' => null, 'items' => 5, 'subtotal' => 199.00, 'discount' => 9.00, 'paid' => 190.00, 'method' => 'cash', 'status' => 'paid'],
            ['number' => 'INV-1026', 'hours_ago' => 170, 'customer' => 8, 'items' => 1, 'subtotal' => 52.50, 'discount' => 0.00, 'paid' => 0.00, 'method' => 'credit', 'status' => 'unpaid'],
            ['number' => 'INV-1025', 'hours_ago' => 174, 'customer' => null, 'items' => 4, 'subtotal' => 138.00, 'discount' => 0.00, 'paid' => 138.00, 'method' => 'cash', 'status' => 'paid'],
            ['number' => 'INV-1024', 'hours_ago' => 194, 'customer' => 3, 'items' => 2, 'subtotal' => 76.00, 'discount' => 0.00, 'paid' => 40.00, 'method' => 'credit', 'status' => 'partially_paid'],
            ['number' => 'INV-1023', 'hours_ago' => 198, 'customer' => null, 'items' => 3, 'subtotal' => 88.25, 'discount' => 0.00, 'paid' => 88.25, 'method' => 'card', 'status' => 'paid'],
        ];

        $rows = [];
        foreach (array_slice($seeds, 0, $limit) as $seed) {
            $total = round($seed['subtotal'] - $seed['discount'], 2);
            $rows[] = [
                'number' => $seed['number'],
                'date' => Carbon::now()->subHours($seed['hours_ago']),
                'customer' => $seed['customer'] === null ? null : self::customerName($seed['customer']),
                'customer_id' => $seed['customer'],
                'items' => $seed['items'],
                'subtotal' => (float) $seed['subtotal'],
                'discount' => (float) $seed['discount'],
                'total' => $total,
                'paid' => (float) $seed['paid'],
                'remaining' => round($total - $seed['paid'], 2),
                'method' => $seed['method'],
                'status' => $seed['status'],
            ];
        }

        return $rows;
    }

    /**
     * قائمة عملاء مصغّرة (لأغراض العرض والفلترة فقط — لا بيانات شخصية مخترعة
     * أكثر من اسم ورقم هاتف؛ لا عناوين ولا هويات).
     */
    public static function customers(): array
    {
        return [
            1 => ['name' => 'أحمد محمود', 'phone' => '0599 111 223'],
            2 => ['name' => 'سارة العمري', 'phone' => '0598 222 334'],
            3 => ['name' => 'خالد أبو ندى', 'phone' => '0597 333 445'],
            4 => ['name' => 'منى الشريف', 'phone' => '0596 444 556'],
            5 => ['name' => 'يوسف حمدان', 'phone' => '0595 555 667'],
            6 => ['name' => 'ليلى قاسم', 'phone' => '0594 666 778'],
            7 => ['name' => 'عمر صالح', 'phone' => '0593 777 889'],
            8 => ['name' => 'هدى نصر', 'phone' => '0592 888 990'],
        ];
    }

    /** قائمة موردين مصغّرة — اسم فقط + رقم هاتف، لا كيانات مخترعة. */
    public static function suppliers(): array
    {
        return [
            1 => ['name' => 'شركة الأمل الطبية', 'phone' => '02 295 1122'],
            2 => ['name' => 'مستودع الشفاء', 'phone' => '02 296 3344'],
            3 => ['name' => 'دار الدواء', 'phone' => '02 297 5566'],
        ];
    }

    public static function customerNames(): array
    {
        return array_values(array_map(fn ($c) => $c['name'], self::customers()));
    }

    private static function customerName(int $id): string
    {
        return self::customers()[$id]['name'] ?? '—';
    }

    private static function supplierName(int $id): string
    {
        return self::suppliers()[$id]['name'] ?? '—';
    }

    /** تنسيق مبلغ موحّد — يُستخدم في PHP فقط (JS له phFmtMoney). */
    public static function money(float $amount): string
    {
        return number_format($amount, 2).' '.self::CURRENCY;
    }

    /** اسم فاتورة عرضي للـPOS (يُولَّد على العميل عند الحفظ). */
    public static function nextInvoiceNumber(): string
    {
        return 'INV-'.str_pad((string) (1043 + random_int(0, 0)), 4, '0', STR_PAD_LEFT);
    }

    public static function labelsForMethod(string $method): string
    {
        return __('accounting.payment_methods.'.$method);
    }

    public static function labelsForStatus(string $status): string
    {
        return __('accounting.statuses.'.$status);
    }

    /** معرّف عشوائي قصير للصفوف المولّدة على العميل (لا يُستخدم في الحفظ). */
    public static function lineKey(): string
    {
        return Str::lower(Str::random(8));
    }
}
