<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\Accounting\AccountingLedger;
use App\Services\PharmacyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * دورة المشتريات عبر الـAPI — القائمة، التفاصيل، الإنشاء، الإلغاء.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ الحيوية كلها في `AccountingLedger::recordPurchase` / `cancelPurchase`.
 * هذا المتحكم يتحقّق من الشكل فقط ثم يفوّض الكتابة للبوّابة الوحيدة —
 * حتى لا يتفرّق منطق المخزون/الصندوق/رصيد المورد بين مكانين.
 *
 * ⚠️ الرقم (`PUR-0001`) يتولّد على الخادم. لا يُقبل من العميل إلا صراحةً
 * في `number` (مفيد للاستيراد)، وقيد `unique(pharmacy_id, number)` يمنع
 * الازدواج ⇒ لا مضاعفة مخزون من إعادة إرسال نفس الفاتورة.
 */
final class AccountingPurchaseController extends Controller
{
    /**
     * GET /api/pharmacy/accounting/purchases
     */
    public function index(Request $request): JsonResponse
    {
        $pharmacy = $this->pharmacyOr404($request);
        if ($pharmacy instanceof JsonResponse) {
            return $pharmacy;
        }

        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'search' => 'nullable|string|max:100',
            'status' => 'nullable|string|max:20',
            'supplier_id' => 'nullable|integer',
            'range' => 'nullable|string|max:10',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'include_cancelled' => 'nullable|boolean',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);

        $query = Purchase::query()
            ->forPharmacy($pharmacy->id)
            ->with(['supplier:id,name', 'items:id,purchase_id,medicine_name,quantity'])
            ->orderByDesc('purchased_at')
            ->orderByDesc('id');

        if (empty($validated['include_cancelled'])) {
            $query->where('is_cancelled', false);
        }

        if (! empty($validated['search'])) {
            $needle = trim((string) $validated['search']);
            $query->where(function ($q) use ($needle) {
                $q->where('number', 'like', "%{$needle}%")
                    ->orWhere('supplier_name', 'like', "%{$needle}%")
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$needle}%"));
            });
        }

        // قيم غير معروفة تُتجاهل بصمت — نفس سلوك بقية فلاتر المحاسبة.
        if (! empty($validated['status']) && in_array($validated['status'], Purchase::statuses(), true)) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['supplier_id'])) {
            $query->where('supplier_id', (int) $validated['supplier_id']);
        }

        [$from, $to] = $this->resolveDateFilters($validated);
        if ($from !== null && $to !== null) {
            $query->whereBetween('purchased_at', [$from, $to]);
        }

        // الإحصاءات على **نفس** الفلتر المعروض.
        $stats = $this->statsFor(clone $query);

        $purchases = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب فواتير الشراء',
            'data' => collect($purchases->items())->map(fn (Purchase $p) => $this->present($p))->all(),
            'pagination' => [
                'total' => $purchases->total(),
                'per_page' => $purchases->perPage(),
                'current_page' => $purchases->currentPage(),
                'last_page' => $purchases->lastPage(),
            ],
            'stats' => $stats,
        ]);
    }

    /**
     * GET /api/pharmacy/accounting/purchases/{number}
     */
    public function show(Request $request, string $number): JsonResponse
    {
        $pharmacy = $this->pharmacyOr404($request);
        if ($pharmacy instanceof JsonResponse) {
            return $pharmacy;
        }

        // القيد بـ pharmacy_id داخل الاستعلام — لا مقارنة لاحقة (لا IDOR).
        $purchase = Purchase::query()
            ->forPharmacy($pharmacy->id)
            ->where('number', $number)
            ->with(['supplier', 'items', 'creator:id,name'])
            ->first();

        if ($purchase === null) {
            return response()->json(['success' => false, 'message' => 'فاتورة الشراء غير موجودة'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم جلب فاتورة الشراء',
            'data' => $this->present($purchase, withItems: true),
        ]);
    }

    /**
     * POST /api/pharmacy/accounting/purchases
     */
    public function store(Request $request): JsonResponse
    {
        $pharmacy = $this->pharmacyOr404($request);
        if ($pharmacy instanceof JsonResponse) {
            return $pharmacy;
        }

        $validated = $request->validate([
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'supplier_name' => 'nullable|string|max:150',
            'number' => 'nullable|string|max:30',
            'items' => 'required|array|min:1|max:200',
            'items.*.pharmacy_medicine_id' => 'nullable|integer',
            'items.*.medicine_id' => 'nullable|integer',
            'items.*.moh_medicine_id' => 'nullable|integer',
            'items.*.medicine_name' => 'required|string|max:200',
            'items.*.barcode' => 'nullable|string|max:32',
            'items.*.unit_cost' => 'required|numeric|min:0|max:999999',
            'items.*.quantity' => 'required|integer|min:1|max:100000',
            'items.*.line_discount' => 'nullable|numeric|min:0|max:999999',
            'discount' => 'nullable|numeric|min:0|max:999999',
            'paid' => 'nullable|numeric|min:0|max:999999',
            'payment_method' => 'required|string|in:cash,card,bank_transfer,credit',
            'notes' => 'nullable|string|max:1000',
            'purchased_at' => 'nullable|date',
        ], [
            'items.required' => 'فاتورة الشراء يجب أن تحتوي على صنف واحد على الأقل',
            'items.*.medicine_name.required' => 'اسم الدواء مطلوب لكل صنف',
            'items.*.unit_cost.required' => 'تكلفة الوحدة مطلوبة لكل صنف',
            'items.*.quantity.required' => 'الكمية مطلوبة لكل صنف',
            'payment_method.in' => 'طريقة الدفع غير مدعومة',
            'supplier_id.exists' => 'المورّد غير موجود',
        ]);

        // المورّد (إن أُرسل) يجب أن يخصّ هذه الصيدلية — قبل أي كتابة.
        if (! empty($validated['supplier_id'])) {
            $owns = Supplier::query()
                ->forPharmacy($pharmacy->id)
                ->whereKey($validated['supplier_id'])
                ->exists();

            if (! $owns) {
                return response()->json([
                    'success' => false,
                    'message' => 'المورّد لا يتبع هذه الصيدلية',
                ], 422);
            }
        }

        try {
            $purchase = AccountingLedger::recordPurchase(
                $pharmacy->id,
                (int) $request->user()->id,
                $validated
            );
        } catch (InvalidArgumentException $e) {
            // أخطاء منطقية متوقّعة من البوّابة (صنف لا يخص المخزون، مجاميع
            // سالبة...) — تُعاد **بنفس غلاف الـAPI** الذي تعيده كل بقية
            // المسارات هنا (`{success,message,data}`).
            //
            // ⚠️ `ValidationException` كان يُنتج `{message,errors}` بلا
            // مفتاح `success`، فيكسر العقد على العميل: التطبيق يقرأ
            // `success` أولًا فلا يجد شيئًا، ويختلف شكل الخطأ حسب ما إذا
            // فشل التحقق في الطلب أم في الدفتر. الغلاف الآن واحد.
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => ['errors' => ['items' => [$e->getMessage()]]],
            ], 422);
        }

        $purchase->load(['supplier', 'items', 'creator:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'تم حفظ فاتورة الشراء بنجاح',
            'data' => $this->present($purchase, withItems: true),
        ], 201);
    }

    /**
     * POST /api/pharmacy/accounting/purchases/{number}/cancel
     */
    public function cancel(Request $request, string $number): JsonResponse
    {
        $pharmacy = $this->pharmacyOr404($request);
        if ($pharmacy instanceof JsonResponse) {
            return $pharmacy;
        }

        $purchase = Purchase::query()
            ->forPharmacy($pharmacy->id)
            ->where('number', $number)
            ->first();

        if ($purchase === null) {
            return response()->json(['success' => false, 'message' => 'فاتورة الشراء غير موجودة'], 404);
        }

        if ($purchase->isCancelled()) {
            return response()->json([
                'success' => false,
                'message' => 'فاتورة الشراء ملغاة بالفعل',
            ], 409);
        }

        try {
            $purchase = AccountingLedger::cancelPurchase($purchase, (int) $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $purchase->load(['supplier', 'items', 'creator:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء فاتورة الشراء وعكس أثرها',
            'data' => $this->present($purchase, withItems: true),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // عرض
    // ══════════════════════════════════════════════════════════════════

    private function present(Purchase $purchase, bool $withItems = false): array
    {
        $payload = [
            'id' => $purchase->id,
            'number' => $purchase->number,
            'date' => $purchase->purchased_at?->toIso8601String(),
            'date_human' => $purchase->purchased_at?->format('Y-m-d H:i'),
            'supplier_id' => $purchase->supplier_id,
            'supplier' => $purchase->supplier_name ?: ($purchase->supplier->name ?? null),
            'items_count' => $purchase->items->count(),
            'subtotal' => (float) $purchase->subtotal,
            'discount' => (float) $purchase->discount,
            'total' => (float) $purchase->total,
            'paid' => (float) $purchase->paid,
            'remaining' => (float) $purchase->remaining,
            'method' => $purchase->payment_method,
            'method_label' => __('accounting.payment_methods.'.$purchase->payment_method),
            'status' => $purchase->status,
            'status_label' => __('accounting.statuses.'.$purchase->status),
            'notes' => $purchase->notes,
            'is_cancelled' => $purchase->isCancelled(),
            'cancelled_at' => $purchase->cancelled_at?->toIso8601String(),
        ];

        if ($withItems) {
            $payload['items'] = $purchase->items->map(fn ($item) => [
                'id' => $item->id,
                'pharmacy_medicine_id' => $item->pharmacy_medicine_id,
                'medicine_id' => $item->medicine_id,
                'moh_medicine_id' => $item->moh_medicine_id,
                'medicine_name' => $item->medicine_name,
                'barcode' => $item->barcode,
                'unit_cost' => (float) $item->unit_cost,
                'quantity' => (int) $item->quantity,
                'line_discount' => (float) $item->line_discount,
                'line_total' => (float) $item->line_total,
            ])->all();

            $payload['created_by'] = $purchase->creator->name ?? null;
        }

        return $payload;
    }

    /**
     * @return array{total: float, count: int, paid: float, remaining: float, discount: float}
     */
    private function statsFor($query): array
    {
        $row = $query
            ->reorder()
            ->selectRaw('COALESCE(SUM(total),0) as total, COUNT(*) as cnt, COALESCE(SUM(paid),0) as paid, COALESCE(SUM(remaining),0) as remaining, COALESCE(SUM(discount),0) as discount')
            ->first();

        return [
            'total' => round((float) ($row->total ?? 0), 2),
            'count' => (int) ($row->cnt ?? 0),
            'paid' => round((float) ($row->paid ?? 0), 2),
            'remaining' => round((float) ($row->remaining ?? 0), 2),
            'discount' => round((float) ($row->discount ?? 0), 2),
        ];
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
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

    /** @return \App\Models\Pharmacy|JsonResponse */
    private function pharmacyOr404(Request $request)
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);

        if ($pharmacy === null) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        return $pharmacy;
    }
}
