<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PharmacyMedicine;
use App\Services\Accounting\AccountingReports;
use App\Services\PharmacyContext;
use App\Support\Accounting\BarcodeStatus;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * نظرة عامة المحاسبة — كل الأرقام مشتقّة من جداول المحاسبة الحقيقية.
 *
 * المسار: GET /api/pharmacy/accounting/overview
 *
 * ── لماذا endpoint واحد لا خمسة؟ ────────────────────────────────────
 * صفحة النظرة العامة تُصوّر 6 بطاقات + رسمين + جدول + تنبيهات + تغطية
 * باركود. خمسة طلبات متوازية تعني خمس handshakes وثلاثة أضعاف احتمال فشل
 * جزئي (بطاقة تصل وأخرى لا). طلب واحد = حالة تحميل واحدة، وفشل واحد واضح.
 *
 * ── الفلترة الزمنية ─────────────────────────────────────────────────
 * `?range=today|7d|30d|month` تتحكم في سلاسل الرسم. البطاقات دائمًا "اليوم".
 */
class AccountingOverviewController extends Controller
{
    /** المدى الافتراضي عند غياب `range` أو قيمته غير معروفة. */
    private const DEFAULT_RANGE = 'today';

    /** المفاتيح المسموحة — أي قيمة أخرى تُتجاهل بصمت (نفس سلوك فلاتر الكتالوج). */
    private const ALLOWED_RANGES = ['today', '7d', '30d', 'month'];

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $range = $this->normalizeRange($request->query('range'));
        [$from, $to] = $this->rangeBounds($range);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب نظرة عامة المحاسبة',
            'data' => [
                'kpis' => AccountingReports::kpis($pharmacy->id),

                // السلاسل كلها تُرسل مرة واحدة: تبديل الفترة في الواجهة
                // يصير فوريًا بلا طلب جديد (البيانات صغيرة: 7-30 نقطة).
                'series' => AccountingReports::salesSeries($pharmacy->id),
                'range' => $range,

                'expense_breakdown' => AccountingReports::expenseBreakdown($pharmacy->id),

                'recent_transactions' => AccountingReports::recentTransactions($pharmacy->id, 8),

                'alerts' => array_merge(
                    AccountingReports::alerts($pharmacy->id),
                    AccountingReports::inventoryAlerts($pharmacy->id, 2)
                ),

                // مؤشر السيولة للفترة المختارة (مبيعات − مشتريات − مصروفات).
                'profit_indicator' => AccountingReports::profitIndicator($pharmacy->id, $from, $to),
                'comparison' => AccountingReports::compareSales($pharmacy->id, $from, $to),

                // الأرقام الحقيقية للذمم — تُعرض في البطاقات التفصيلية.
                'receivables' => AccountingReports::receivablesAudit($pharmacy->id),

                // تغطية الباركود: كم صنف في المخزون له باركود فعلاً.
                // هذا الرقم حقيقي من المخزون، لا مفترض.
                'barcode_coverage' => $this->barcodeCoverage($pharmacy->id),
            ],
        ]);
    }

    /**
     * تغطية الباركود في مخزون هذه الصيدلية — المصدر الحقيقي للتغطية.
     *
     * ⚠️ **الباركود لا يسكن في `pharmacy_medicines`** — لا يوجد عمود barcode
     * هناك إطلاقًا. الباركود في جدول `medicine_barcodes` (فريد عالميًا، مربوط
     * بـ`local_medicine_id` → `medicines.id`). أي محاولة لقراءة
     * `pharmacy_medicines.barcode` تفشل بـ`Unknown column`.
     *
     * لذا التغطية تُحسب بـ JOIN حقيقي:
     *   pharmacy_medicines.medicine_id → medicine_barcodes.local_medicine_id
     *
     * والحالات مشتقّة من أعمدة `medicine_barcodes` الفعلية:
     *   · `is_verified = 1` → VERIFIED
     *   · `is_verified = 0` → PENDING (مرتبط لكن بانتظار توثيق)
     *   · لا سطر مطابق    → UNKNOWN (حالة طبيعية، ليست خطأ)
     *
     * @return array{total:int,with_barcode:int,without_barcode:int,percent:float,by_status:array<string,int>}
     */
    private function barcodeCoverage(int $pharmacyId): array
    {
        $total = (int) PharmacyMedicine::query()
            ->where('pharmacy_id', $pharmacyId)
            ->count();

        // عدد سطور المخزون التي دواؤها مرتبط بباركود مؤكَّد فعلاً.
        $verified = (int) PharmacyMedicine::query()
            ->where('pharmacy_id', $pharmacyId)
            ->whereNotNull('medicine_id')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('medicine_barcodes')
                    ->whereColumn('medicine_barcodes.local_medicine_id', 'pharmacy_medicines.medicine_id')
                    ->where('medicine_barcodes.is_verified', true);
            })
            ->count();

        // مرتبط بباركود لكن غير موثَّق بعد.
        $pending = (int) PharmacyMedicine::query()
            ->where('pharmacy_id', $pharmacyId)
            ->whereNotNull('medicine_id')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('medicine_barcodes')
                    ->whereColumn('medicine_barcodes.local_medicine_id', 'pharmacy_medicines.medicine_id')
                    ->where('medicine_barcodes.is_verified', false);
            })
            ->count();

        $withBarcode = $verified + $pending;
        $unknown = max(0, $total - $withBarcode);

        return [
            'total' => $total,
            'with_barcode' => $withBarcode,
            'without_barcode' => $unknown,
            'percent' => $total > 0 ? round(($withBarcode / $total) * 100, 1) : 0.0,
            // المفاتيح هي مفردات BarcodeStatus نفسها — لا نخترع مفردات.
            'by_status' => [
                BarcodeStatus::VERIFIED => $verified,
                BarcodeStatus::PENDING => $pending,
                BarcodeStatus::UNKNOWN => $unknown,
            ],
        ];
    }

    private function normalizeRange(?string $range): string
    {
        if ($range === null || ! in_array($range, self::ALLOWED_RANGES, true)) {
            return self::DEFAULT_RANGE;
        }

        return $range;
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function rangeBounds(string $range): array
    {
        return match ($range) {
            '7d' => [Carbon::today()->subDays(6)->startOfDay(), Carbon::today()->endOfDay()],
            '30d' => [Carbon::today()->subDays(29)->startOfDay(), Carbon::today()->endOfDay()],
            'month' => [Carbon::today()->startOfMonth(), Carbon::today()->endOfDay()],
            default => [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()],
        };
    }
}
