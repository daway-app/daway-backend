<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * القراءة/التجميع لكل أرقام المحاسبة — المصدر الوحيد للأرقام المعروضة.
 *
 * ⚠️ قاعدة صارمة: هذه الخدمة **قراءة فقط**. لا تكتب أي صفّ في أي جدول.
 * كل الكتابة تمرّ عبر `AccountingLedger` (البوّابة الوحيدة).
 *
 * ── لماذا لا عمود رصيد مخزَّن للصندوق؟ ─────────────────────────────────
 * رصيد الصندوق = `SUM(signed amount)` من `cash_movements` مُحسوبًا في SQL.
 * لا يوجد عمود `balance` يمكن أن ينحرف عن الحركات الفعلية. هذا قرار مقصود:
 * كل حركة نقدية تُسجَّل صفًّا، والرصيد دائمًا مشتق — فلا يمكن أن يكذب.
 *
 * ── لماذا نستثني الملغى صريحًا؟ ────────────────────────────────────────
 * الفواتير/المصروفات/المشتريات لا تُحذف أبدًا (تُلغى: status='cancelled'
 * أو is_cancelled=true) حفاظًا على الأثر التدقيقي. أي تجميع مالي **يجب**
 * أن يستثني الملغى، وإلا ضاعفت الإلغاءات الأرقام. لذلك كل دالة هنا تفلتر
 * الملغى بالقوة عبر `activeSaleScope()` / `->where('is_cancelled', false)`.
 *
 * ── لماذا MAX(id) لرقم الفاتورة التالي؟ ────────────────────────────────
 * `COUNT(*)` خطأ شائع: بعد حذف صف يُعاد استخدام الرقم، ولو كان ترقيمًا
 * يدويًا أو مستوردًا انكسر التسلسل. `MAX(id)` تصاعدي بطبعه ولا يعيد أرقامًا.
 */
final class AccountingReports
{
    /** عتبة التنبيه لانخفاض الصندوق — نفس قيمة الواجهة (لا تُكرَّر في JS). */
    public const LOW_CASH_THRESHOLD = 500.00;

    private const CURRENCY = '₪';

    // ══════════════════════════════════════════════════════════════════
    // 1) مؤشرات الأداء (بطاقات النظرة العامة)
    // ══════════════════════════════════════════════════════════════════

    /**
     * بطاقات الـKPI. المفاتيح مطابقة حرفيًا لما تنتظره الواجهة:
     * value (رقم) · format ('money'|'int') · tone · icon · href.
     *
     * @return list<array<string,mixed>>
     */
    public static function kpis(int $pharmacyId): array
    {
        $today = self::todayRange();

        $todaySales = self::sumSales($pharmacyId, $today[0], $today[1]);
        $todayPurchases = self::sumPurchases($pharmacyId, $today[0], $today[1]);
        $todayExpenses = self::sumExpenses($pharmacyId, $today[0], $today[1]);
        $cash = self::cashBalance($pharmacyId);
        $outstanding = self::outstandingTotal($pharmacyId);

        $profit = round($todaySales['total'] - $todayPurchases - $todayExpenses, 2);

        return [
            [
                'key' => 'today_sales',
                'label' => __('accounting.overview.kpi_today_sales'),
                'value' => $todaySales['total'],
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
                'value' => $profit,
                'format' => 'money',
                'icon' => 'fas fa-chart-line',
                'tone' => $profit < 0 ? 'red' : 'green',
                'href' => null,
            ],
            [
                'key' => 'cash_balance',
                'label' => __('accounting.overview.kpi_cash_balance'),
                'value' => $cash,
                'format' => 'money',
                'icon' => 'fas fa-wallet',
                'tone' => $cash < self::LOW_CASH_THRESHOLD ? 'red' : 'gray',
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
     * ملخّص الفترة — يعيد المبيعات والأرباح وعدد الفواتير، يُستخدم في
     * ترويسة صفحة المبيعات وفي المقارنة مع الفترة السابقة.
     *
     * ⚠️ `$from`/`$to` قابلان للـnull عمدًا: «بلا فلتر زمني» حالة شرعية
     * (شاشة السجل الكامل). عندها نجمع **كل** الفواتير بلا شرط زمني بدل
     * الفشل بـTypeError — وهذا عطل حقيقي اكتُشف باختبار الـendpoint.
     *
     * @return array{total:float,count:int,paid:float,remaining:float,discount:float}
     */
    public static function salesSummary(int $pharmacyId, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        return self::sumSales($pharmacyId, $from, $to);
    }

    /**
     * المتوسط المرجّح لعدد الأصناف في الفاتورة (لا نقسم على صفر أبدًا).
     */
    public static function averageItemsPerSale(int $pharmacyId, ?CarbonInterface $from = null, ?CarbonInterface $to = null): float
    {
        $q = Sale::query()->forPharmacy($pharmacyId)->active();

        if ($from !== null && $to !== null) {
            $q->between($from, $to);
        }

        $count = (clone $q)->count();

        if ($count === 0) {
            return 0.0;
        }

        $items = (int) (clone $q)->sum('items_count');

        return round($items / $count, 2);
    }

    // ══════════════════════════════════════════════════════════════════
    // 2) السلاسل الزمنية (الرسم الخطي)
    // ══════════════════════════════════════════════════════════════════

    /**
     * سلاسل المبيعات لأربع فترات. الشكل مطابق لما تنتظره الواجهة:
     * [rangeKey => ['labels' => [...], 'data' => [...]]]
     *
     * نُجمّع في SQL حيث أمكن (ساعة/يوم/شهر) بدل جلب كل الصفوف إلى PHP.
     *
     * @return array<string, array{labels:list<string>, data:list<float>}>
     */
    public static function salesSeries(int $pharmacyId): array
    {
        return [
            'today' => self::seriesToday($pharmacyId),
            '7d' => self::seriesDaily($pharmacyId, 7),
            '30d' => self::seriesDaily($pharmacyId, 30, step: 5),
            'month' => self::seriesMonthly($pharmacyId),
        ];
    }

    /** توزيع مبيعات اليوم على نافذة ساعتين من 08:00 إلى 20:00. */
    private static function seriesToday(int $pharmacyId): array
    {
        $slots = [
            ['08:00:00', '10:00:00', '08'],
            ['10:00:00', '12:00:00', '10'],
            ['12:00:00', '14:00:00', '12'],
            ['14:00:00', '16:00:00', '14'],
            ['16:00:00', '18:00:00', '16'],
            ['18:00:00', '20:00:00', '18'],
            ['20:00:00', '23:59:59', '20'],
        ];

        $labels = [];
        $data = [];

        foreach ($slots as [$start, $end, $label]) {
            $bucketStart = Carbon::today()->setTimeFromTimeString($start);
            $bucketEnd = Carbon::today()->setTimeFromTimeString($end);

            $labels[] = $label;
            $data[] = self::sumSales($pharmacyId, $bucketStart, $bucketEnd)['total'];
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /**
     * آخر N يومًا مجمَّعة في SQL. `$step` يسمح بتجميع كل عدة أيام
     * (مثلاً 30 يومًا في 6 نقاط) بلا جلب كل الصفوف.
     */
    private static function seriesDaily(int $pharmacyId, int $days, int $step = 1): array
    {
        $start = Carbon::today()->subDays($days - 1)->startOfDay();
        $rows = self::dailyTotals($pharmacyId, $start, Carbon::today()->endOfDay());

        $labels = [];
        $data = [];

        for ($offset = 0; $offset < $days; $offset += $step) {
            $bucketStart = $start->copy()->addDays($offset);
            $sum = 0.0;

            for ($i = 0; $i < $step && ($offset + $i) < $days; $i++) {
                $key = $bucketStart->copy()->addDays($i)->toDateString();
                $sum += (float) ($rows[$key] ?? 0.0);
            }

            $labels[] = $offset === 0 && $days <= 7
                ? __('accounting.common.today')
                : ($offset === 0 ? '1' : (string) ($offset + 1));

            $data[] = round($sum, 2);
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /**
     * أربعة أسابيع من الشهر الحالي. الأسبوع الرابع يمتد حتى نهاية الشهر
     * (يحتمل أكثر من 7 أيام — مقصود حتى لا يضيع آخر يوم من الشهر).
     */
    private static function seriesMonthly(int $pharmacyId): array
    {
        $startOfMonth = Carbon::today()->startOfMonth();

        $labels = [];
        $data = [];

        for ($week = 0; $week < 4; $week++) {
            $wStart = $startOfMonth->copy()->addWeeks($week)->startOfDay();
            $wEnd = $week === 3
                ? Carbon::today()->endOfMonth()
                : $startOfMonth->copy()->addWeeks($week + 1)->subSecond();

            $labels[] = 'W'.($week + 1);
            $data[] = self::sumSales($pharmacyId, $wStart, $wEnd)['total'];
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /**
     * إجمالي مبيعات لكل يوم في نطاق — استعلام واحد مجمَّع.
     * تحويل التاريخ يعتمد على قواعد MySQL/SQLite كلٌّ على حدة:
     * MySQL له `DATE()`، وSQLite يستخدم `strftime`. نختار حسب المُتصِل
     * بدل كتابة SQL غير محمول.
     *
     * @return array<string, float>  [Y-m-d => total]
     */
    private static function dailyTotals(int $pharmacyId, CarbonInterface $from, CarbonInterface $to): array
    {
        $driver = DB::connection()->getDriverName();

        $dateExpr = match ($driver) {
            'sqlite' => "strftime('%Y-%m-%d', sold_at)",
            'pgsql' => 'TO_CHAR(sold_at, \'YYYY-MM-DD\')',
            default => 'DATE(sold_at)',
        };

        $rows = Sale::query()
            ->forPharmacy($pharmacyId)
            ->active()
            ->between($from, $to)
            ->selectRaw("{$dateExpr} as day_key, SUM(total) as day_total")
            ->groupBy('day_key')
            ->pluck('day_total', 'day_key');

        return $rows->map(fn ($v) => (float) $v)->all();
    }

    // ══════════════════════════════════════════════════════════════════
    // 3) توزيع المصروفات (الرسم الدائري)
    // ══════════════════════════════════════════════════════════════════

    /**
     * توزيع المصروفات حسب التصنيف. يعتمد لقطة `category_key`/`category_name`
     * المخزَّنة على المصروف نفسه — لا join على `expense_categories`، لأن
     * التصنيف قد يُعاد تسميته أو يُعطَّل لاحقًا ولا يجب أن يتغيّر التقرير.
     *
     * @return list<array{category:string,label:string,amount:float,percentage:float}>
     */
    public static function expenseBreakdown(int $pharmacyId, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $from ??= Carbon::today()->startOfMonth();
        $to ??= Carbon::today()->endOfDay();

        $rows = Expense::query()
            ->forPharmacy($pharmacyId)
            ->active()
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('category_key, MAX(category_name) as category_name, SUM(amount) as total')
            ->groupBy('category_key')
            ->orderByDesc('total')
            ->get();

        $total = (float) $rows->sum('total');

        return $rows->map(function ($row) use ($total) {
            $amount = (float) $row->total;

            return [
                'category' => (string) $row->category_key,
                'label' => (string) ($row->category_name ?: $row->category_key),
                'amount' => round($amount, 2),
                'percentage' => $total > 0 ? round(($amount / $total) * 100, 1) : 0.0,
            ];
        })->values()->all();
    }

    /**
     * إجماليات المصروفات لكل تصنيف خلال الشهر — تستخدمها شاشة المصروفات.
     *
     * @return array<string, float>
     */
    public static function expenseTotalsByCategory(int $pharmacyId, CarbonInterface $from, CarbonInterface $to): array
    {
        return Expense::query()
            ->forPharmacy($pharmacyId)
            ->active()
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('category_key, SUM(amount) as total')
            ->groupBy('category_key')
            ->pluck('total', 'category_key')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    // ══════════════════════════════════════════════════════════════════
    // 4) الصندوق
    // ══════════════════════════════════════════════════════════════════

    /**
     * رصيد الصندوق = مجموع الحركات الموقّعة. مشتق دائمًا، لا عمود مخزَّن.
     *
     * الحساب في SQL بتعبير CASE واحد لتفادي جلب كل الحركات إلى PHP
     * (قد تكون عشرات الآلاف مع الوقت).
     */
    public static function cashBalance(int $pharmacyId): float
    {
        $row = CashMovement::query()
            ->forPharmacy($pharmacyId)
            ->selectRaw(
                "SUM(CASE WHEN direction = ? THEN amount ELSE -amount END) as balance",
                [CashMovement::DIRECTION_IN]
            )
            ->value('balance');

        return round((float) $row, 2);
    }

    /**
     * رصيد الصندوق حتى لحظة معيّنة (مفيد لكشف الصندوق في نهاية اليوم).
     */
    public static function cashBalanceAsOf(int $pharmacyId, CarbonInterface $moment): float
    {
        $row = CashMovement::query()
            ->forPharmacy($pharmacyId)
            ->where('moved_at', '<=', $moment)
            ->selectRaw(
                "SUM(CASE WHEN direction = ? THEN amount ELSE -amount END) as balance",
                [CashMovement::DIRECTION_IN]
            )
            ->value('balance');

        return round((float) $row, 2);
    }

    /**
     * حركات الصندوق في نطاق — للكشف (cash drawer report).
     *
     * @return array{in:float,out:float,net:float,count:int}
     */
    public static function cashFlow(int $pharmacyId, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = CashMovement::query()
            ->forPharmacy($pharmacyId)
            ->whereBetween('moved_at', [$from, $to])
            ->selectRaw('direction, SUM(amount) as total, COUNT(*) as cnt')
            ->groupBy('direction')
            ->get()
            ->keyBy('direction');

        $in = (float) ($rows[CashMovement::DIRECTION_IN]->total ?? 0);
        $out = (float) ($rows[CashMovement::DIRECTION_OUT]->total ?? 0);
        $count = (int) $rows->sum('cnt');

        return [
            'in' => round($in, 2),
            'out' => round($out, 2),
            'net' => round($in - $out, 2),
            'count' => $count,
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    // 5) الذمم (مستحق لنا / مستحق علينا)
    // ══════════════════════════════════════════════════════════════════

    /**
     * إجمالي الذمم المدينة (ما لنا عند العملاء) — من عمود `current_balance`
     * على جدول العملاء. العمود يُضبط **داخل** معاملة البيع في `AccountingLedger`
     * فلا يمكن أن ينفصل عن الفواتير.
     */
    public static function customerReceivablesTotal(int $pharmacyId): float
    {
        return round((float) Customer::query()
            ->forPharmacy($pharmacyId)
            ->where('is_active', true)
            ->sum('current_balance'), 2);
    }

    /** إجمالي الذمم الدائنة (ما علينا للموردين). */
    public static function supplierPayablesTotal(int $pharmacyId): float
    {
        return round((float) Supplier::query()
            ->forPharmacy($pharmacyId)
            ->where('is_active', true)
            ->sum('current_balance'), 2);
    }

    /**
     * إجمالي الذمم «المعلّقة» المصدر الثاني: مجموع المتبقي في الفواتير
     * غير المسدَّدة. يُعرض للتأكيد المتقاطع مع أرصدة العملاء: لو اختلفا،
     * فهناك خلل يستحق الانتباه — لا نخفي الفرق.
     *
     * @return array{customer_balances:float,invoice_remaining:float,drift:float,supplier_balances:float,purchase_remaining:float}
     */
    public static function receivablesAudit(int $pharmacyId): array
    {
        $customerBalances = self::customerReceivablesTotal($pharmacyId);
        $invoiceRemaining = round((float) Sale::query()
            ->forPharmacy($pharmacyId)
            ->active()
            ->sum('remaining'), 2);

        $supplierBalances = self::supplierPayablesTotal($pharmacyId);
        $purchaseRemaining = round((float) Purchase::query()
            ->forPharmacy($pharmacyId)
            ->active()
            ->sum('remaining'), 2);

        return [
            'customer_balances' => $customerBalances,
            'invoice_remaining' => $invoiceRemaining,
            'drift' => round($customerBalances - $invoiceRemaining, 2),
            'supplier_balances' => $supplierBalances,
            'purchase_remaining' => $purchaseRemaining,
        ];
    }

    /** أضخم المديونين — للوحة العملاء. */
    public static function topDebtors(int $pharmacyId, int $limit = 5): Collection
    {
        return Customer::query()
            ->forPharmacy($pharmacyId)
            ->where('is_active', true)
            ->where('current_balance', '>', 0)
            ->orderByDesc('current_balance')
            ->limit($limit)
            ->get();
    }

    // ══════════════════════════════════════════════════════════════════
    // 6) آخر الحركات المالية
    // ══════════════════════════════════════════════════════════════════

    /**
     * آخر الحركات المالية موحَّدة في قائمة واحدة (فواتير · مصروفات ·
     * مشتريات · تحصيلات · دفعات · سحب/إيداع).
     *
     * ── لماذا لا UNION في SQL؟ ────────────────────────────────────────
     * الجداول مختلفة الأعمدة، والـ UNION سيجعل كل صف يحمل أعمدة فارغة
     * ويصعّب التنسيق. بدلًا من ذلك: نجلب آخر N من كل جدول (استعلامان
     * صغيران مفهرسان)، ندمجهم في PHP، نرتّب زمنيًا، ونقتطع N.
     * الحدّ الأقصى المطلوب صغير (≤20) فلا مشكلة أداء.
     *
     * @return list<array<string,mixed>>
     */
    public static function recentTransactions(int $pharmacyId, int $limit = 8): array
    {
        $perSource = max($limit, 8);

        $rows = collect()
            ->merge(self::recentSales($pharmacyId, $perSource))
            ->merge(self::recentExpenses($pharmacyId, $perSource))
            ->merge(self::recentPurchases($pharmacyId, $perSource))
            ->merge(self::recentCustomerPayments($pharmacyId, $perSource))
            ->merge(self::recentSupplierPayments($pharmacyId, $perSource))
            ->merge(self::recentCashAdjustments($pharmacyId, $perSource))
            ->sortByDesc('_ts')
            ->take($limit)
            ->values();

        return $rows->map(function (array $row) {
            unset($row['_ts']);

            return $row;
        })->all();
    }

    private static function recentSales(int $pharmacyId, int $limit): Collection
    {
        return Sale::query()
            ->forPharmacy($pharmacyId)
            ->active()
            ->with(['items:id,sale_id,medicine_name,quantity'])
            ->orderByDesc('sold_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (Sale $sale) {
                $first = $sale->items->first();
                $desc = $first
                    ? 'فاتورة بيع — '.$first->medicine_name.' ×'.$first->quantity
                    : 'فاتورة بيع';

                return [
                    'type' => 'sale',
                    'reference' => $sale->number,
                    'description' => $desc,
                    'amount' => (float) $sale->total,
                    'method' => $sale->payment_method,
                    'status' => $sale->status,
                    'date' => $sale->sold_at,
                    'href' => route('pharmacy.accounting.sales.show', $sale->number),
                    '_ts' => $sale->sold_at?->getTimestamp() ?? 0,
                ];
            });
    }

    private static function recentExpenses(int $pharmacyId, int $limit): Collection
    {
        return Expense::query()
            ->forPharmacy($pharmacyId)
            ->active()
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Expense $expense) => [
                'type' => 'expense',
                'reference' => $expense->reference ?: 'EXP-'.str_pad((string) $expense->id, 4, '0', STR_PAD_LEFT),
                'description' => $expense->description ?: ($expense->category_name ?? 'مصروف'),
                'amount' => -1 * (float) $expense->amount,
                'method' => $expense->payment_method,
                'status' => 'paid',
                'date' => $expense->expense_date,
                'href' => null,
                '_ts' => $expense->expense_date?->getTimestamp() ?? 0,
            ]);
    }

    private static function recentPurchases(int $pharmacyId, int $limit): Collection
    {
        return Purchase::query()
            ->forPharmacy($pharmacyId)
            ->active()
            ->orderByDesc('purchased_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Purchase $purchase) => [
                'type' => 'purchase',
                'reference' => $purchase->number,
                'description' => 'شراء من: '.($purchase->supplier_name ?? 'مورّد'),
                'amount' => -1 * (float) $purchase->total,
                'method' => $purchase->payment_method,
                'status' => $purchase->status,
                'date' => $purchase->purchased_at,
                'href' => null,
                '_ts' => $purchase->purchased_at?->getTimestamp() ?? 0,
            ]);
    }

    private static function recentCustomerPayments(int $pharmacyId, int $limit): Collection
    {
        return \App\Models\CustomerPayment::query()
            ->forPharmacy($pharmacyId)
            ->active()
            ->with('customer:id,name')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($payment) => [
                'type' => 'customer_payment',
                'reference' => $payment->reference ?: 'RCP-'.str_pad((string) $payment->id, 4, '0', STR_PAD_LEFT),
                'description' => 'تحصيل دفعة من: '.($payment->customer->name ?? 'عميل'),
                'amount' => (float) $payment->amount,
                'method' => $payment->payment_method,
                'status' => 'paid',
                'date' => $payment->paid_at,
                'href' => null,
                '_ts' => $payment->paid_at?->getTimestamp() ?? 0,
            ]);
    }

    private static function recentSupplierPayments(int $pharmacyId, int $limit): Collection
    {
        return \App\Models\SupplierPayment::query()
            ->forPharmacy($pharmacyId)
            ->active()
            ->with('supplier:id,name')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($payment) => [
                'type' => 'supplier_payment',
                'reference' => $payment->reference ?: 'PAY-'.str_pad((string) $payment->id, 4, '0', STR_PAD_LEFT),
                'description' => 'دفعة لمورّد: '.($payment->supplier->name ?? 'مورّد'),
                'amount' => -1 * (float) $payment->amount,
                'method' => $payment->payment_method,
                'status' => 'paid',
                'date' => $payment->paid_at,
                'href' => null,
                '_ts' => $payment->paid_at?->getTimestamp() ?? 0,
            ]);
    }

    private static function recentCashAdjustments(int $pharmacyId, int $limit): Collection
    {
        return CashMovement::query()
            ->forPharmacy($pharmacyId)
            ->whereIn('source_type', [
                CashMovement::SOURCE_WITHDRAWAL,
                CashMovement::SOURCE_DEPOSIT,
                CashMovement::SOURCE_ADJUSTMENT,
            ])
            ->orderByDesc('moved_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (CashMovement $movement) => [
                'type' => $movement->source_type,
                'reference' => 'CSH-'.str_pad((string) $movement->id, 4, '0', STR_PAD_LEFT),
                'description' => $movement->description ?: ($movement->reason ?? 'حركة صندوق'),
                'amount' => $movement->signedAmount(),
                'method' => 'cash',
                'status' => 'paid',
                'date' => $movement->moved_at,
                'href' => null,
                '_ts' => $movement->moved_at?->getTimestamp() ?? 0,
            ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // 7) التنبيهات
    // ══════════════════════════════════════════════════════════════════

    /**
     * بطاقة «يحتاج انتباهك». كل تنبيه مبني على رقم حقيقي من قاعدة البيانات
     * — لا نصوص ثابتة. إن لم يوجد ما يستحق التنبيه تُعاد مصفوفة فارغة
     * (والواجهة تُظهر حالة «كل شيء على ما يرام»).
     *
     * @return list<array{severity:string,icon:string,title:string,description:string,href:?string}>
     */
    public static function alerts(int $pharmacyId): array
    {
        $alerts = [];

        // (1) موردون علينا لهم أموال
        $topSupplier = Supplier::query()
            ->forPharmacy($pharmacyId)
            ->where('is_active', true)
            ->where('current_balance', '>', 0)
            ->orderByDesc('current_balance')
            ->first();

        if ($topSupplier !== null) {
            $alerts[] = [
                'severity' => 'warning',
                'icon' => 'fas fa-truck-ramp-box',
                'title' => __('accounting.overview.alert_supplier_due'),
                'description' => __('accounting.overview.alert_supplier_due_desc', [
                    'supplier' => $topSupplier->name,
                    'amount' => self::money((float) $topSupplier->current_balance),
                ]),
                'href' => null,
            ];
        }

        // (2) أقدم فاتورة غير مسدَّدة بالكامل (متأخرة السداد)
        $oldestUnpaid = Sale::query()
            ->forPharmacy($pharmacyId)
            ->active()
            ->where('remaining', '>', 0)
            ->whereNotNull('customer_id')
            ->with('customer:id,name')
            ->orderBy('sold_at')
            ->first();

        if ($oldestUnpaid !== null && $oldestUnpaid->customer !== null) {
            $days = (int) $oldestUnpaid->sold_at?->diffInDays(Carbon::now());

            $alerts[] = [
                'severity' => 'danger',
                'icon' => 'fas fa-user-clock',
                'title' => __('accounting.overview.alert_customer_overdue'),
                'description' => __('accounting.overview.alert_customer_overdue_desc', [
                    'customer' => $oldestUnpaid->customer->name,
                    'days' => $days,
                ]),
                'href' => route('pharmacy.accounting.sales.show', $oldestUnpaid->number),
            ];
        }

        // (3) انخفاض الصندوق
        $cash = self::cashBalance($pharmacyId);

        if ($cash < self::LOW_CASH_THRESHOLD) {
            $alerts[] = [
                'severity' => 'danger',
                'icon' => 'fas fa-wallet',
                'title' => __('accounting.overview.alert_low_cash'),
                'description' => __('accounting.overview.alert_low_cash_desc', [
                    'amount' => self::money($cash),
                ]),
                'href' => null,
            ];
        }

        return $alerts;
    }

    /**
     * مخزون منخفض/نافد — تنبيه تشغيلي حقيقي من المخزون لا من المحاسبة.
     * يُعرض في نفس البطاقة لأنه «يحتاج انتباهك».
     *
     * @return list<array{severity:string,icon:string,title:string,description:string,href:?string}>
     */
    public static function inventoryAlerts(int $pharmacyId, int $limit = 3): array
    {
        $rows = \App\Models\PharmacyMedicine::query()
            ->where('pharmacy_id', $pharmacyId)
            ->where('quantity', '<=', \App\Models\PharmacyMedicine::LOW_STOCK_THRESHOLD)
            ->with('medicine:id,trade_name')
            ->orderBy('quantity')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($pm) => [
            'severity' => $pm->quantity <= 0 ? 'danger' : 'warning',
            'icon' => 'fas fa-pills',
            'title' => $pm->quantity <= 0
                ? __('accounting.overview.alert_out_of_stock')
                : __('accounting.overview.alert_low_stock'),
            'description' => ($pm->medicine->trade_name ?? 'دواء').' — '.
                __('accounting.overview.alert_stock_remaining', ['count' => (int) $pm->quantity]),
            'href' => null,
        ])->all();
    }

    // ══════════════════════════════════════════════════════════════════
    // 8) التقارير الإضافية (الأرباح · أفضل الأصناف · طرق الدفع)
    // ══════════════════════════════════════════════════════════════════

    /**
     * أفضل الأصناف مبيعًا في نطاق — الكمية والإيراد لكل صنف.
     *
     * ── عن أي عمود نجمع؟ ──────────────────────────────────────────────
     * بـ `medicine_name` **المخزَّن على بند الفاتورة** (لقطة)، لا بالـ
     * `medicine_id`. السبب: صنف قد يُحذف من الكتالوج أو يُعاد ربطه، واللقطة
     * هي الحقيقة التاريخية. هذا يعني أن تعديل الاسم في الكتالوج لا يشقّ
     * تقريرًا قديمًا إلى نصفين.
     *
     * @return list<array{medicine_name:string,quantity:int,revenue:float}>
     */
    public static function topSellingItems(int $pharmacyId, ?CarbonInterface $from = null, ?CarbonInterface $to = null, int $limit = 10): array
    {
        $items = SaleItem::query()
            ->whereHas('sale', function ($q) use ($pharmacyId, $from, $to) {
                $q->forPharmacy($pharmacyId)->active();

                // بلا نطاق ⇒ كل الفواتير (لا شرط زمني).
                if ($from !== null && $to !== null) {
                    $q->between($from, $to);
                }
            })
            ->selectRaw('medicine_name, SUM(quantity) as qty, SUM(line_total) as revenue')
            ->groupBy('medicine_name')
            ->orderByDesc('qty')
            ->limit($limit)
            ->get();

        return $items->map(fn ($row) => [
            'medicine_name' => (string) $row->medicine_name,
            'quantity' => (int) $row->qty,
            'revenue' => round((float) $row->revenue, 2),
        ])->all();
    }

    /**
     * تقسيم المبيعات حسب طريقة الدفع — لاكتشاف الاعتماد على الآجل.
     *
     * `$from`/`$to` = null ⇒ بلا فلتر زمني (كل الفواتير).
     *
     * @return list<array{method:string,count:int,total:float,percentage:float}>
     */
    public static function salesByPaymentMethod(int $pharmacyId, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $query = Sale::query()
            ->forPharmacy($pharmacyId)
            ->active();

        if ($from !== null && $to !== null) {
            $query->between($from, $to);
        }

        $rows = $query
            ->selectRaw('payment_method, COUNT(*) as cnt, SUM(total) as total')
            ->groupBy('payment_method')
            ->orderByDesc('total')
            ->get();

        $grand = (float) $rows->sum('total');

        return $rows->map(fn ($row) => [
            'method' => (string) $row->payment_method,
            'count' => (int) $row->cnt,
            'total' => round((float) $row->total, 2),
            'percentage' => $grand > 0 ? round((((float) $row->total) / $grand) * 100, 1) : 0.0,
        ])->all();
    }

    /**
     * **هامش الربح التقريبي** — تحذير مهم قبل استخدام هذا الرقم:
     *
     * لا يوجد عمود «سعر التكلفة» على بند الفاتورة (`sale_items`)، ولم يُطلب
     * في المواصفات. لذا هذا الرقم ليس ربحًا حقيقيًا، بل **الفرق بين المبيعات
     * والمشتريات والمصروفات** في نفس الفترة. هو مؤشر سيولة/تدفق، لا محاسبة
     * تكلفة. الواجهة تُسمّيه «الصافي» لا «الربح» عند عرض التفصيل.
     *
     * لو أُريد ربحًا حقيقيًا لاحقًا: نضيف `unit_cost` إلى `sale_items`
     * ونلتقطه من `pharmacy_medicines` وقت البيع (نفس مبدأ اللقطة).
     *
     * @return array{sales:float,purchases:float,expenses:float,net:float}
     */
    public static function profitIndicator(int $pharmacyId, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $sales = self::sumSales($pharmacyId, $from, $to)['total'];

        // المشتريات/المصروفات تُحسب بنفس القاعدة: بلا نطاق ⇒ كل السجل.
        $purchases = $from !== null && $to !== null
            ? self::sumPurchases($pharmacyId, $from, $to)
            : round((float) Purchase::query()->forPharmacy($pharmacyId)->active()->sum('total'), 2);

        $expenses = $from !== null && $to !== null
            ? self::sumExpenses($pharmacyId, $from, $to)
            : round((float) Expense::query()->forPharmacy($pharmacyId)->active()->sum('amount'), 2);

        return [
            'sales' => $sales,
            'purchases' => $purchases,
            'expenses' => $expenses,
            'net' => round($sales - $purchases - $expenses, 2),
        ];
    }

    /**
     * مقارنة فترة بفترة سابقة — تُظهر نسبة التغيّر (السالب يعني تراجعًا).
     *
     * `$from`/`$to` = null ⇒ بلا نطاق، فلا معنى للمقارنة: نُعيد الأرقام
     * الحالية وأصفار المقارنة بدل الفشل. سلوك صريح لا انهيار.
     */
    public static function compareSales(int $pharmacyId, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        if ($from === null || $to === null) {
            $current = self::sumSales($pharmacyId)['total'];

            return [
                'current' => $current,
                'previous' => 0.0,
                'change_percent' => 0.0,
                'direction' => 'flat',
            ];
        }

        $days = max(1, (int) $from->diffInDays($to) + 1);

        $current = self::sumSales($pharmacyId, $from, $to)['total'];
        $previous = self::sumSales(
            $pharmacyId,
            $from->copy()->subDays($days),
            $from->copy()->subSecond()
        )['total'];

        $change = $previous > 0
            ? round((($current - $previous) / $previous) * 100, 1)
            : ($current > 0 ? 100.0 : 0.0);

        return [
            'current' => $current,
            'previous' => $previous,
            'change_percent' => $change,
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat'),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    // 9) التصنيفات الافتراضية
    // ══════════════════════════════════════════════════════════════════

    /**
     * تصنيفات المصروفات المتاحة للصيدلية.
     *
     * ── لماذا لا نعتمد على السيدر فقط؟ ────────────────────────────────
     * صيدلية جديدة قد تُنشأ بعد السيدر، أو يُعطَّل تصنيف. هذه الدالة تُرجع
     * ما هو موجود فعلاً، وإن كانت القائمة فارغة **تُنشئ التصنيفات الافتراضية
     * كسولًا** ثم تُرجعها — حتى لا تظهر شاشة المصروفات فارغة أبدًا بلا سبب.
     *
     * @return \Illuminate\Support\Collection<int, ExpenseCategory>
     */
    public static function expenseCategories(int $pharmacyId): Collection
    {
        $existing = ExpenseCategory::query()
            ->forPharmacy($pharmacyId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        self::seedDefaultExpenseCategories($pharmacyId);

        return ExpenseCategory::query()
            ->forPharmacy($pharmacyId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * التصنيفات الافتراضية بالمفاتيح المطابقة حرفيًا لملف الترجمة
     * `accounting.expenses.category.*` — فلا نص عربي مكتوب في الكود.
     *
     * مفاتيح الترجمة الموجودة: salaries · rent · electricity · water ·
     * internet · transport · maintenance · taxes · other
     *
     * @return list<array{key:string,sort_order:int}>
     */
    public static function defaultExpenseCategoryKeys(): array
    {
        return [
            ['key' => 'salaries', 'sort_order' => 10],
            ['key' => 'rent', 'sort_order' => 20],
            ['key' => 'electricity', 'sort_order' => 30],
            ['key' => 'water', 'sort_order' => 40],
            ['key' => 'internet', 'sort_order' => 50],
            ['key' => 'transport', 'sort_order' => 60],
            ['key' => 'maintenance', 'sort_order' => 70],
            ['key' => 'taxes', 'sort_order' => 80],
            ['key' => 'other', 'sort_order' => 90],
        ];
    }

    /**
     * إنشاء التصنيفات الافتراضية لصيدلية. idempotent: `updateOrCreate`
     * على `(pharmacy_id, key)` — تشغيله مرتين لا يضاعف شيئًا.
     */
    public static function seedDefaultExpenseCategories(int $pharmacyId): int
    {
        $created = 0;

        foreach (self::defaultExpenseCategoryKeys() as $row) {
            $category = ExpenseCategory::withTrashed()->updateOrCreate(
                ['pharmacy_id' => $pharmacyId, 'key' => $row['key']],
                [
                    'name_ar' => __('accounting.expenses.category.'.$row['key']),
                    'name_en' => null,
                    'is_active' => true,
                    'sort_order' => $row['sort_order'],
                    'deleted_at' => null,
                ]
            );

            if ($category->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    // ══════════════════════════════════════════════════════════════════
    // داخلي
    // ══════════════════════════════════════════════════════════════════

    /** @return array{0:CarbonInterface,1:CarbonInterface} */
    private static function todayRange(): array
    {
        return [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()];
    }

    /**
     * تجميع المبيعات في نطاق. كل الفواتير **غير الملغاة** فقط.
     *
     * `$from`/`$to` = null ⇒ بلا فلتر زمني (كل الفواتير). هذا يخدم شاشة
     * السجل الكامل ويُبقي التوقيع آمنًا (لا TypeError على null).
     *
     * @return array{total:float,count:int,paid:float,remaining:float,discount:float}
     */
    private static function sumSales(int $pharmacyId, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $query = Sale::query()
            ->forPharmacy($pharmacyId)
            ->active();

        if ($from !== null && $to !== null) {
            $query->between($from, $to);
        }

        $row = $query
            ->selectRaw(
                'COALESCE(SUM(total),0) as total, COUNT(*) as cnt, '.
                'COALESCE(SUM(paid),0) as paid, COALESCE(SUM(remaining),0) as remaining, '.
                'COALESCE(SUM(discount),0) as discount'
            )
            ->first();

        return [
            'total' => round((float) ($row->total ?? 0), 2),
            'count' => (int) ($row->cnt ?? 0),
            'paid' => round((float) ($row->paid ?? 0), 2),
            'remaining' => round((float) ($row->remaining ?? 0), 2),
            'discount' => round((float) ($row->discount ?? 0), 2),
        ];
    }

    private static function sumPurchases(int $pharmacyId, CarbonInterface $from, CarbonInterface $to): float
    {
        return round((float) Purchase::query()
            ->forPharmacy($pharmacyId)
            ->active()
            ->whereBetween('purchased_at', [$from, $to])
            ->sum('total'), 2);
    }

    private static function sumExpenses(int $pharmacyId, CarbonInterface $from, CarbonInterface $to): float
    {
        return round((float) Expense::query()
            ->forPharmacy($pharmacyId)
            ->active()
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->sum('amount'), 2);
    }

    /** إجمالي الذمم المدينة + الدائنة معًا (لـKPI واحد مدمج). */
    private static function outstandingTotal(int $pharmacyId): float
    {
        return self::customerReceivablesTotal($pharmacyId);
    }

    /** تنسيق مبلغ موحّد بالشيكل — يُستخدم داخل النصوص المترجمة. */
    public static function money(float $amount): string
    {
        return number_format($amount, 2).' '.self::CURRENCY;
    }
}
