<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Services\Accounting\AccountingLedger;
use App\Services\Accounting\AccountingReports;
use App\Services\PharmacyContext;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * الصندوق — الرصيد · الحركات · الإيداع/السحب/التسوية.
 *
 * المسارات:
 *   GET  /api/pharmacy/accounting/cash
 *   POST /api/pharmacy/accounting/cash/adjustments
 *
 * ── لماذا لا نكتب رصيدًا مباشرة أبدًا؟ ───────────────────────────────
 * لا يوجد endpoint «عيّن الرصيد = X». لأن هذا يمحو الفرق بلا أثر: لو كان
 * الصندوق 1000 وسجّلنا 1200، فأين ذهب الـ200؟ تصبح الأرقام لاحقًا بلا
 * تفسير. البديل: `adjustment` بسبب إلزامي (`reason`) — الفرق يصير **صفًّا**
 * له سبب، ويبقى الأثر. هذا هو الفرق بين محاسبة ونظام أرقام.
 *
 * ── ما يظهر في الرصيد ───────────────────────────────────────────────
 * `cash_movements` هو دفتر الصندوق الوحيد. كل بيع نقدي، كل مصروف، كل دفعة
 * مورد، كل تحصيل عميل نقدي، وكل إيداع/سحب/تسوية يكتب صفًّا هنا. الرصيد
 * مجموعها — فلا يمكن أن ينحرف عن الواقع لأن لا أحد يكتبه يدويًا.
 */
class AccountingCashController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $pharmacy = $this->pharmacyOr404($request);
        if ($pharmacy instanceof JsonResponse) {
            return $pharmacy;
        }

        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'range' => 'nullable|string|max:10',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'direction' => 'nullable|string|in:in,out',
            'source_type' => 'nullable|string|max:30',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 30);

        [$from, $to] = $this->resolveDateFilters($validated);
        $from ??= Carbon::today()->startOfMonth();
        $to ??= Carbon::today()->endOfDay();

        $query = CashMovement::query()
            ->forPharmacy($pharmacy->id)
            ->with('creator:id,name')
            ->whereBetween('moved_at', [$from, $to])
            ->orderByDesc('moved_at')
            ->orderByDesc('id');

        // اتجاه غير معروف يُتجاهل بصمت (لا 422).
        if (! empty($validated['direction'])
            && in_array($validated['direction'], [CashMovement::DIRECTION_IN, CashMovement::DIRECTION_OUT], true)) {
            $query->where('direction', $validated['direction']);
        }

        if (! empty($validated['source_type'])
            && in_array($validated['source_type'], CashMovement::sources(), true)) {
            $query->where('source_type', $validated['source_type']);
        }

        $movements = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب حركات الصندوق',
            'data' => collect($movements->items())->map(fn (CashMovement $m) => [
                'id' => $m->id,
                'direction' => $m->direction,
                'amount' => (float) $m->amount,
                'signed_amount' => $m->signedAmount(),
                'source_type' => $m->source_type,
                'source_id' => $m->source_id,
                'description' => $m->description,
                'reason' => $m->reason,
                'date' => $m->moved_at?->toIso8601String(),
                'date_human' => $m->moved_at?->format('Y-m-d H:i'),
                'created_by' => $m->creator->name ?? null,
            ])->all(),
            'pagination' => [
                'total' => $movements->total(),
                'per_page' => $movements->perPage(),
                'current_page' => $movements->currentPage(),
                'last_page' => $movements->lastPage(),
            ],
            'stats' => [
                // الرصيد الحالي **الحقيقي** — وليس رصيد نهاية الفترة المختارة.
                // الاثنان معروضان: المستخدم يحتاج الاثنين معًا.
                'balance_now' => AccountingReports::cashBalance($pharmacy->id),
                'balance_as_of' => AccountingReports::cashBalanceAsOf($pharmacy->id, $to),
                'period' => AccountingReports::cashFlow($pharmacy->id, $from, $to),
                'low_threshold' => AccountingReports::LOW_CASH_THRESHOLD,
                'is_low' => AccountingReports::cashBalance($pharmacy->id) < AccountingReports::LOW_CASH_THRESHOLD,
            ],
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ]);
    }

    /**
     * إيداع / سحب / تسوية صندوق.
     *
     * `reason` إلزامي — هذا هو الفرق الجوهري عن كتابة رصيد يدويًا.
     */
    public function storeAdjustment(Request $request): JsonResponse
    {
        $pharmacy = $this->pharmacyOr404($request);
        if ($pharmacy instanceof JsonResponse) {
            return $pharmacy;
        }

        $validated = $request->validate([
            'direction' => 'required|string|in:in,out',
            'kind' => 'required|string|in:withdrawal,deposit,adjustment',
            'amount' => 'required|numeric|min:0.01|max:999999',
            'reason' => 'required|string|min:3|max:500',
            'moved_at' => 'nullable|date',
        ], [
            'direction.in' => 'اتجاه الحركة غير صحيح',
            'kind.in' => 'نوع الحركة غير صحيح',
            'amount.min' => 'المبلغ يجب أن يكون أكبر من صفر',
            'reason.required' => 'سبب الحركة مطلوب',
            'reason.min' => 'سبب الحركة قصير جدًا — اكتب سببًا واضحًا',
        ]);

        // التحقّق من التناسق: السحب لا يكون إيداعًا، والتسوية تتبع اتجاهها.
        // الاتجاه يُشتق من النوع كي لا يرسل العميل تعارضًا (withdrawal + in).
        $expectedDirection = $validated['kind'] === 'deposit'
            ? CashMovement::DIRECTION_IN
            : CashMovement::DIRECTION_OUT;

        if ($validated['kind'] !== 'adjustment' && $validated['direction'] !== $expectedDirection) {
            return response()->json([
                'success' => false,
                'message' => 'اتجاه الحركة لا يطابق نوعها',
            ], 422);
        }

        try {
            $movement = AccountingLedger::recordCashAdjustment(
                $pharmacy->id,
                (int) $request->user()->id,
                $validated['direction'],
                (float) $validated['amount'],
                $validated['reason'],
                ! empty($validated['moved_at']) ? Carbon::parse($validated['moved_at']) : null,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => [$e->getMessage()]]);
        }

        // السحب لا يجوز أن يجعل الرصيد سالبًا في الواقع — لكن بعض الأنظمة
        // تسمح بذلك (سحب على الحساب). نُبلّغ المستخدم بالرصيد الناتج بدل
        // منعه، لأن المنع قد يمنع تسجيل واقع حدث فعلاً.
        $newBalance = AccountingReports::cashBalance($pharmacy->id);

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل حركة الصندوق',
            'data' => [
                'movement' => [
                    'id' => $movement->id,
                    'direction' => $movement->direction,
                    'amount' => (float) $movement->amount,
                    'source_type' => $movement->source_type,
                    'reason' => $movement->reason,
                    'date' => $movement->moved_at?->toIso8601String(),
                ],
                'balance_now' => $newBalance,
                'is_low' => $newBalance < AccountingReports::LOW_CASH_THRESHOLD,
                'warning' => $newBalance < 0
                    ? 'تنبيه: رصيد الصندوق صار سالبًا — راجع حركة السحب'
                    : null,
            ],
        ], 201);
    }

    // ══════════════════════════════════════════════════════════════════

    /** @return \App\Models\Pharmacy|JsonResponse */
    private function pharmacyOr404(Request $request)
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);

        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        return $pharmacy;
    }

    /** @return array{0:?Carbon,1:?Carbon} */
    private function resolveDateFilters(array $validated): array
    {
        if (! empty($validated['from'])) {
            return [
                Carbon::parse($validated['from'])->startOfDay(),
                ! empty($validated['to'])
                    ? Carbon::parse($validated['to'])->endOfDay()
                    : Carbon::now()->endOfDay(),
            ];
        }

        return match ($validated['range'] ?? null) {
            'today' => [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()],
            '7d' => [Carbon::today()->subDays(6)->startOfDay(), Carbon::today()->endOfDay()],
            '30d' => [Carbon::today()->subDays(29)->startOfDay(), Carbon::today()->endOfDay()],
            'month' => [Carbon::today()->startOfMonth(), Carbon::today()->endOfDay()],
            default => [null, null],
        };
    }
}
