<?php

namespace App\Http\Controllers\web\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Support\Accounting\AccountingMockData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * وحدة المحاسبة — Frontend-only.
 * ==========================================================
 * ⚠️ لا يوجد Backend محاسبة في Daway. هذا الـController يجهّز بيانات
 * عرضية من AccountingMockData ويرسم الـviews. **لا يكتب شيئًا في قاعدة
 * البيانات، ولا يستدعي أي API مُختلَق.** كل الأفعال الكتابية في الواجهة
 * معطّلة أو تُحلّ محليًا في JS مع رسالة توضيحية.
 *
 * عند إضافة الجداول الحقيقية: استبدل مصادر البيانات هنا فقط.
 *
 * الوصول محكوم بـ middleware في routes/web.php:
 *   auth + role:pharmacy + profile.complete  (نفس مسارات الصيدلية الحالية)
 */
class AccountingController extends Controller
{
    /**
     * الصيدلية الحالية للمستخدم — نمط متّبع في كل Controllers الصيدلية.
     */
    private function pharmacy(): Pharmacy
    {
        return Pharmacy::where('user_id', Auth::id())->firstOrFail();
    }

    /** /pharmacy/accounting */
    public function overview(): View
    {
        $pharmacy = $this->pharmacy();

        return view('pharmacy.accounting.overview', [
            'pharmacy' => $pharmacy,
            'kpis' => AccountingMockData::kpis(),
            'salesSeries' => AccountingMockData::salesSeries(),
            'salesRanges' => [
                'today' => __('accounting.common.today'),
                '7d' => __('accounting.common.last_7_days'),
                '30d' => __('accounting.common.last_30_days'),
                'month' => __('accounting.common.this_month'),
            ],
            'expenseBreakdown' => AccountingMockData::expenseBreakdown(),
            'transactions' => AccountingMockData::recentTransactions(),
            'alerts' => AccountingMockData::alerts(),
            'isDemo' => true,
        ]);
    }

    /** /pharmacy/accounting/sales */
    public function sales(Request $request): View
    {
        $pharmacy = $this->pharmacy();

        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');
        $method = (string) $request->query('method', 'all');
        $range = (string) $request->query('range', 'all');

        $allowedStatus = ['all', 'paid', 'partially_paid', 'unpaid', 'refunded', 'cancelled'];
        $allowedMethod = ['all', 'cash', 'card', 'bank_transfer', 'credit', 'other'];
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

        // الفلترة على بيانات mock تطابق الفلترة التي ستجري لاحقًا في SQL
        // (نفس المفاتيح ومعانيها) ⇒ لا تغيير في الـview عند الربط.
        $rows = collect(AccountingMockData::sales(limit: 200))
            ->when($q !== '', function ($c) use ($q) {
                $needle = mb_strtolower($q);

                return $c->filter(fn ($r) => str_contains(mb_strtolower($r['number']), $needle)
                    || str_contains(mb_strtolower((string) $r['customer']), $needle));
            })
            ->when($status !== 'all', fn ($c) => $c->where('status', $status))
            ->when($method !== 'all', fn ($c) => $c->where('method', $method))
            ->when($range === 'today', fn ($c) => $c->filter(fn ($r) => $r['date']->isToday()))
            ->when($range === '7d', fn ($c) => $c->filter(fn ($r) => $r['date']->gte(now()->subDays(7))))
            ->when($range === '30d', fn ($c) => $c->filter(fn ($r) => $r['date']->gte(now()->subDays(30))))
            ->when($range === 'month', fn ($c) => $c->filter(fn ($r) => $r['date']->gte(now()->startOfMonth())))
            ->values();

        // ترقيم صفحات حقيقي على المجموعة (لا تحميل كل السجلات في الصفحة)
        $perPage = 10;
        $page = max(1, (int) $request->query('page', 1));
        $items = $rows->forPage($page, $perPage);
        $total = $rows->count();

        return view('pharmacy.accounting.sales', [
            'pharmacy' => $pharmacy,
            'sales' => $items,
            'q' => $q,
            'status' => $status,
            'method' => $method,
            'range' => $range,
            'pagination' => new \Illuminate\Pagination\LengthAwarePaginator(
                $items,
                $total,
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            ),
            'summary' => [
                'count' => $total,
                'total' => (float) $rows->sum('total'),
                'paid' => (float) $rows->sum('paid'),
                'remaining' => (float) $rows->sum('remaining'),
            ],
            'isDemo' => true,
        ]);
    }

    /** /pharmacy/accounting/sales/create — شاشة البيع (POS) */
    public function saleCreate(): View
    {
        $pharmacy = $this->pharmacy();

        return view('pharmacy.accounting.sale-create', [
            'pharmacy' => $pharmacy,
            'posCatalog' => AccountingMockData::posCatalog(),
            'customers' => AccountingMockData::customerNames(),
            'paymentMethods' => [
                'cash' => __('accounting.payment_methods.cash'),
                'card' => __('accounting.payment_methods.card'),
                'bank_transfer' => __('accounting.payment_methods.bank_transfer'),
                'credit' => __('accounting.payment_methods.credit'),
            ],
            'searchEndpoint' => url('/api/medicines/search'),
            'barcodeEndpoint' => url('/api/medicines/barcode'),
            'isDemo' => true,
        ]);
    }

    /** /pharmacy/accounting/sales/{number} — تفاصيل الفاتورة */
    public function saleShow(string $number): View
    {
        $pharmacy = $this->pharmacy();

        $sale = collect(AccountingMockData::sales(limit: 200))
            ->firstWhere('number', $number);

        abort_if($sale === null, 404, __('accounting.invoice.not_found'));

        return view('pharmacy.accounting.invoice', [
            'pharmacy' => $pharmacy,
            'sale' => $sale,
            'isDemo' => true,
        ]);
    }
}
