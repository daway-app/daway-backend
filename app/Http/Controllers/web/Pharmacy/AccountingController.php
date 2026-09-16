<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\Sale;
use App\Services\Accounting\AccountingReports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * وحدة المحاسبة — الصفحات (Web).
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **Backend حقيقي موجود** (`AccountingLedger` + `AccountingReports` +
 * `Api\Accounting*`). هذا الـController **لا يكتب** شيئًا: القراءة فقط.
 * كل كتابة تمرّ من `/api/pharmacy/accounting/*` عبر الـLedger.
 *
 * ── لماذا تقرأ الصفحات من نفس خدمات الـAPI؟ ───────────────────────────
 * لأن مصدرين للأرقام = رقمان مختلفان لنفس الفاتورة. `AccountingReports`
 * هي الطبقة الوحيدة للتجميع، والـController والـAPI كلاهما يستهلكها.
 *
 * ── الحالة الابتدائية (SSR) لا النهائية ──────────────────────────────
 * الصفحة تصيّر أرقامًا حقيقية من القاعدة (أول رسم بلا انتظار)، ثم تُحدَّث
 * حيًّا من الـAPI في `accounting-overview.js`. الأرقام المصيَّرة تتجمّد
 * لحظة التصيير، لذا الجلب الحيّ هو ما يجعل «مبيعات اليوم» صحيحة فعلًا.
 *
 * الوصول محكوم بـ middleware في routes/web.php:
 *   auth + role:pharmacy + profile.complete
 */
class AccountingController extends Controller
{
    /**
     * الصيدلية الحالية للمستخدم.
     *
     * ⚠️ البوّابة المعتمدة للمشروع هي `PharmacyContext::forUser()` التي
     * ترجع null بدل أن ترمي. هنا `firstOrFail` مقبول لأن الـmiddleware
     * `profile.complete` يضمن وجود صيدلية مكتملة قبل الوصول للصفحة —
     * ولو وصلنا بلا صيدلية فـ404 هو السلوك الصحيح لا شاشة فارغة.
     */
    private function pharmacy(): Pharmacy
    {
        return Pharmacy::where('user_id', Auth::id())->firstOrFail();
    }

    /** /pharmacy/accounting — النظرة العامة */
    public function overview(): View
    {
        $pharmacy = $this->pharmacy();
        $id = (int) $pharmacy->id;

        $expenseBreakdown = AccountingReports::expenseBreakdown($id);

        $transactions = AccountingReports::recentTransactions($id, 8);

        // تنسيق العرض هنا (لا في الـview): الـview يرسم فقط.
        foreach ($transactions as &$tx) {
            $tx['date_human'] = isset($tx['date']) && $tx['date']
                ? \Illuminate\Support\Carbon::parse($tx['date'])->format('Y-m-d H:i')
                : '—';
            $tx['amount_label'] = ($tx['amount'] < 0 ? '−' : '') . self::money(abs((float) $tx['amount']));
        }
        unset($tx);

        return view('pharmacy.accounting.overview', [
            'pharmacy' => $pharmacy,
            'kpis' => self::formatKpis(AccountingReports::kpis($id)),
            'salesSeries' => AccountingReports::salesSeries($id),
            'salesRanges' => [
                'today' => __('accounting.common.today'),
                '7d' => __('accounting.common.last_7_days'),
                '30d' => __('accounting.common.last_30_days'),
                'month' => __('accounting.common.this_month'),
            ],
            'expenseBreakdown' => $expenseBreakdown,
            'transactions' => $transactions,
            'alerts' => array_merge(
                AccountingReports::alerts($id),
                AccountingReports::inventoryAlerts($id, 2)
            ),
            // الشريط التجريبي يظهر فقط حين لا توجد أي بيانات بعد — رسالة
            // «جرّب بيانات تجريبية» مفيدة حين تكون الشاشة فارغة فعلًا.
            'isDemo' => ! Sale::query()->forPharmacy($id)->exists(),
        ]);
    }

    /** /pharmacy/accounting/sales — سجل الفواتير */
    public function sales(Request $request): View
    {
        $pharmacy = $this->pharmacy();
        $id = (int) $pharmacy->id;

        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');
        $method = (string) $request->query('method', 'all');
        $range = (string) $request->query('range', 'all');

        // ⚠️ قيم غير معروفة تُتجاهل بصمت — نفس سلوك `Api\AccountingSalesController`
        // حرفيًا، حتى لا يختلف ما تراه الشاشة عن ما يرجعه الـAPI.
        $allowedStatus = ['all', 'paid', 'partially_paid', 'unpaid', 'refunded', 'cancelled'];
        $allowedMethod = ['all', 'cash', 'card', 'bank_transfer', 'credit'];
        $allowedRange = ['all', 'today', '7d', '30d', 'month'];

        if (! in_array($status, $allowedStatus, true)) {
            $status = 'all';
        }
        if (! in_array($method, $allowedMethod, true)) {
            $method = 'all';
        }
        if (! in_array($range, $allowedRange, true)) {
            $range = 'all';
        }

        $query = Sale::query()
            ->forPharmacy($id)
            ->with(['customer:id,name'])
            ->orderByDesc('sold_at')
            ->orderByDesc('id');

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('number', 'like', "%{$q}%")
                    ->orWhere('customer_name', 'like', "%{$q}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$q}%"));
            });
        }

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($method !== 'all') {
            $query->where('payment_method', $method);
        }

        [$from, $to] = self::rangeBounds($range);
        if ($from !== null) {
            $query->between($from, $to);
        }

        // الإحصاءات على نفس الاستعلام المفلتر (clone قبل paginate) —
        // وإلا اختلف ملخّص الشاشة عن الصفوف المعروضة.
        $statsRow = (clone $query)
            ->reorder()
            ->selectRaw(
                'COALESCE(SUM(total),0) as total, COUNT(*) as cnt, '
                .'COALESCE(SUM(paid),0) as paid, COALESCE(SUM(remaining),0) as remaining'
            )
            ->first();

        $sales = $query->paginate(10)->withQueryString();

        return view('pharmacy.accounting.sales', [
            'pharmacy' => $pharmacy,
            'sales' => collect($sales->items())->map(fn (Sale $s) => self::presentSaleRow($s))->all(),
            'q' => $q,
            'status' => $status,
            'method' => $method,
            'range' => $range,
            'pagination' => $sales,
            'summary' => [
                'count' => (int) ($statsRow->cnt ?? 0),
                'total' => round((float) ($statsRow->total ?? 0), 2),
                'paid' => round((float) ($statsRow->paid ?? 0), 2),
                'remaining' => round((float) ($statsRow->remaining ?? 0), 2),
            ],
            'isDemo' => false,
        ]);
    }

    /** /pharmacy/accounting/sales/create — شاشة البيع (POS) */
    public function saleCreate(): View
    {
        $pharmacy = $this->pharmacy();

        return view('pharmacy.accounting.sale-create', [
            'pharmacy' => $pharmacy,
            'posCatalog' => self::posCatalog((int) $pharmacy->id),
            'paymentMethods' => [
                'cash' => __('accounting.payment_methods.cash'),
                'card' => __('accounting.payment_methods.card'),
                'bank_transfer' => __('accounting.payment_methods.bank_transfer'),
                'credit' => __('accounting.payment_methods.credit'),
            ],
            'searchEndpoint' => url('/api/medicines/search'),
            'barcodeEndpoint' => url('/api/medicines/barcode'),
            // الشريط التجريبي يظهر فقط لو كان المخزون فارغًا
            'isDemo' => ! \App\Models\PharmacyMedicine::query()
                ->where('pharmacy_id', $pharmacy->id)->exists(),
        ]);
    }

    /** /pharmacy/accounting/sales/{number} — تفاصيل الفاتورة */
    public function saleShow(string $number): View
    {
        $pharmacy = $this->pharmacy();

        // القيد بـpharmacy_id داخل الاستعلام — لا مقارنة لاحقة (IDOR-safe).
        $sale = Sale::query()
            ->forPharmacy((int) $pharmacy->id)
            ->where('number', $number)
            ->with(['items', 'customer:id,name'])
            ->first();

        abort_if($sale === null, 404, __('accounting.invoice.not_found'));

        return view('pharmacy.accounting.invoice', [
            'pharmacy' => $pharmacy,
            'sale' => self::presentInvoice($sale),
            'isDemo' => false,
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════
       عرض
       ══════════════════════════════════════════════════════════════════ */

    /**
     * كتالوج نقطة البيع — من **مخزون هذه الصيدلية** لا من كتالوج عام.
     *
     * ⚠️ `id` هو `pharmacy_medicines.id` (ما يُخصم فعلًا)، و`medicine_id`
     * هو `medicines.id` (ما يعيده الـbarcode endpoint بـ`local_medicine_id`).
     * الاثنان ضروريان: الأول للخصم، والثاني للربط بالباركود.
     *
     * @return list<array<string,mixed>>
     */
    private static function posCatalog(int $pharmacyId): array
    {
        return \App\Models\PharmacyMedicine::query()
            ->where('pharmacy_id', $pharmacyId)
            ->with('medicine:id,trade_name,active_ingredient')
            ->orderByDesc('quantity')
            ->limit(500)
            ->get()
            ->filter(fn ($pm) => $pm->medicine !== null)
            ->map(fn ($pm) => [
                'id' => (int) $pm->id,
                'medicine_id' => (int) $pm->medicine_id,
                'barcode' => self::barcodeForMedicine((int) $pm->medicine_id),
                'trade_name' => (string) $pm->medicine->trade_name,
                'active_ingredient' => (string) ($pm->medicine->active_ingredient ?? ''),
                'price' => (float) $pm->price,
                'quantity' => (int) $pm->quantity,
            ])
            ->values()
            ->all();
    }

    /**
     * باركود دواء من `medicine_barcodes` — **لا من `pharmacy_medicines`**
     * (لا عمود barcode هناك إطلاقًا).
     *
     * @param  array<int,string>|null  $cache  خريطة مشتركة لتفادي N+1
     */
    private static function barcodeForMedicine(int $medicineId, ?array &$cache = null): string
    {
        if ($cache === null) {
            $cache = \App\Models\MedicineBarcode::query()
                ->whereNotNull('local_medicine_id')
                ->orderByDesc('is_verified')
                ->pluck('barcode', 'local_medicine_id')
                ->all();
        }

        return (string) ($cache[$medicineId] ?? '');
    }

    /** يحوّل KPI إلى شكل العرض (نصّ جاهز + رابط). */
    private static function formatKpis(array $kpis): array
    {
        return array_map(function (array $kpi): array {
            $kpi['display'] = $kpi['format'] === 'money'
                ? self::money((float) $kpi['value'])
                : (string) $kpi['value'];

            return $kpi;
        }, $kpis);
    }

    /** صف جدول الفواتير — المفاتيح مطابقة لما يتوقّعه الـBlade. */
    private static function presentSaleRow(Sale $sale): array
    {
        return [
            'number' => $sale->number,
            'date' => $sale->sold_at,
            'customer' => $sale->customer_name ?: ($sale->customer->name ?? null),
            // `items` اسم قديم في الـBlade يعني «العدد»
            'items' => (int) $sale->items_count,
            'subtotal' => (float) $sale->subtotal,
            'discount' => (float) $sale->discount,
            'total' => (float) $sale->total,
            'paid' => (float) $sale->paid,
            'remaining' => (float) $sale->remaining,
            'method' => $sale->payment_method,
            'status' => $sale->status,
        ];
    }

    /** فاتورة كاملة بالبنود — للعرض والطباعة. */
    private static function presentInvoice(Sale $sale): array
    {
        return [
            'number' => $sale->number,
            'date' => $sale->sold_at,
            'customer' => $sale->customer_name ?: ($sale->customer->name ?? null),
            'items' => $sale->items->map(fn ($item) => [
                'name' => $item->medicine_name,
                'barcode' => $item->barcode,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_discount' => (float) $item->line_discount,
                'line_total' => (float) $item->line_total,
            ])->all(),
            'items_count' => (int) $sale->items_count,
            'subtotal' => (float) $sale->subtotal,
            'discount' => (float) $sale->discount,
            'total' => (float) $sale->total,
            'paid' => (float) $sale->paid,
            'remaining' => (float) $sale->remaining,
            'method' => $sale->payment_method,
            'status' => $sale->status,
            'notes' => $sale->notes,
        ];
    }

    /**
     * حدود الفلتر الزمني. `all` ⇒ [null,null] (بلا شرط) — لا نرسل null
     * لدالة تتطلّب قيمة (كان يسبّب 500 في تقارير الـAPI).
     *
     * @return array{0:?string,1:?string}
     */
    private static function rangeBounds(string $range): array
    {
        $now = now();

        return match ($range) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            '7d' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            '30d' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            default => [null, null],
        };
    }

    /**
     * تنسيق مبلغ للعرض.
     *
     * ⚠️ لا نستخدم `AccountingMockData::money` — الـMock للتجربة وحدها،
     * وربط شاشة الإنتاج به يجعل حذفه لاحقًا يكسر المحاسبة الحقيقية.
     */
    private static function money(float $value): string
    {
        return AccountingReports::money($value);
    }
}
