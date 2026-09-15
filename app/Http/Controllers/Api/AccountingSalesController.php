<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\Accounting\AccountingLedger;
use App\Services\Accounting\AccountingReports;
use App\Services\PharmacyContext;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * فواتير البيع — القائمة · التفاصيل · الإنشاء · الإلغاء.
 *
 * المسارات (كلها داخل `auth:sanctum` + `role:pharmacy`):
 *   GET    /api/pharmacy/accounting/sales
 *   POST   /api/pharmacy/accounting/sales
 *   GET    /api/pharmacy/accounting/sales/{number}
 *   POST   /api/pharmacy/accounting/sales/{number}/cancel
 *   GET    /api/pharmacy/accounting/sales-summary
 *
 * ── لماذا لا نستخدم Route Model Binding على {sale}؟ ──────────────────
 * لأن رقم الفاتورة هو ما تعرفه الواجهة (تظهره للمستخدم، وتُبنى منه الروابط).
 * الربط بـ id يتطلب من العميل تخزين id خفي، وإن أعيد إنشاء الفاتورة تغيّر
 * الـid بينما الرقم ثابت في الفواتير المطبوعة. لذا نبحث بالرقم + الصيدلية.
 *
 * ── قاعدة أمنية ─────────────────────────────────────────────────────
 * كل بحث يقيّد بـ `pharmacy_id` **في نفس الاستعلام**. لا نبحث ثم نقارن
 * الصيدلية في PHP — لأن ذلك يفتح ثغرة IDOR كلاسيكية لو نُسي الشرط.
 */
class AccountingSalesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'search' => 'nullable|string|max:100',
            'status' => 'nullable|string|max:20',
            'payment_method' => 'nullable|string|max:20',
            'range' => 'nullable|string|max:10',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);

        $query = Sale::query()
            ->forPharmacy($pharmacy->id)
            ->with(['customer:id,name', 'items:id,sale_id,medicine_name,quantity'])
            ->orderByDesc('sold_at')
            ->orderByDesc('id');

        // البحث: رقم الفاتورة أو اسم العميل — كلاهما مفهرس/نصّي.
        if (! empty($validated['search'])) {
            $needle = trim((string) $validated['search']);
            $query->where(function ($q) use ($needle) {
                $q->where('number', 'like', "%{$needle}%")
                    ->orWhere('customer_name', 'like', "%{$needle}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$needle}%"));
            });
        }

        // قيم غير معروفة تُتجاهل بصمت (لا 422) — نفس سلوك فلاتر الكتالوج،
        // حفاظًا على عملاء لا يرسلون Accept: application/json.
        if (! empty($validated['status']) && in_array($validated['status'], Sale::statuses(), true)) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['payment_method']) && in_array($validated['payment_method'], Sale::methods(), true)) {
            $query->where('payment_method', $validated['payment_method']);
        }

        [$from, $to] = $this->resolveDateFilters($validated);
        if ($from !== null && $to !== null) {
            $query->between($from, $to);
        }

        // الإحصاءات تُحسب على **نفس** الاستعلام المفلتر (clone قبل paginate)
        // حتى تتطابق الأرقام مع ما يراه المستخدم حرفيًا.
        $stats = $this->statsFor(clone $query);

        $sales = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب فواتير البيع',
            'data' => collect($sales->items())->map(fn (Sale $sale) => $this->presentSale($sale))->all(),
            'pagination' => [
                'total' => $sales->total(),
                'per_page' => $sales->perPage(),
                'current_page' => $sales->currentPage(),
                'last_page' => $sales->lastPage(),
            ],
            'stats' => $stats,
        ]);
    }

    /**
     * ملخّص الفترة — يعيد الإجماليات لعرضها في ترويسة الشاشة.
     * منفصل عن `index` كي تعمل شاشة التقارير بلا جلب صفوف.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $validated = $request->validate([
            'range' => 'nullable|string|max:10',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        [$from, $to] = $this->resolveDateFilters($validated);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب ملخّص المبيعات',
            'data' => [
                'summary' => AccountingReports::salesSummary($pharmacy->id, $from, $to),
                'comparison' => AccountingReports::compareSales($pharmacy->id, $from, $to),
                'by_payment_method' => AccountingReports::salesByPaymentMethod($pharmacy->id, $from, $to),
                'top_items' => AccountingReports::topSellingItems($pharmacy->id, $from, $to, 10),
                'average_items_per_sale' => AccountingReports::averageItemsPerSale($pharmacy->id, $from, $to),
                'profit_indicator' => AccountingReports::profitIndicator($pharmacy->id, $from, $to),
            ],
        ]);
    }

    /** تفاصيل فاتورة واحدة بالبنود كاملة. */
    public function show(Request $request, string $number): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        // القيد بـ pharmacy_id داخل الاستعلام — لا مقارنة لاحقة.
        $sale = Sale::query()
            ->forPharmacy($pharmacy->id)
            ->where('number', $number)
            ->with(['customer', 'items', 'creator:id,name'])
            ->first();

        if (! $sale) {
            return response()->json(['success' => false, 'message' => 'الفاتورة غير موجودة'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الفاتورة',
            'data' => $this->presentSale($sale, withItems: true),
        ]);
    }

    /**
     * إنشاء فاتورة بيع.
     *
     * ── الحيوية كلها في `AccountingLedger::recordSale` ──────────────────
     * هنا نتحقّق من الشكل فقط (validation)، ثم نفوّض الكتابة للبوّابة الوحيدة.
     * لا نلمس المخزون ولا الصندوق ولا أرصدة العملاء هنا — لو لمسناها لتفرّق
     * منطق الخصم بين مكانين وظهرت فواتير بلا خصم مخزون.
     *
     * ── لماذا نتحقّق من أن الدواء يملك بهذه الصيدلية؟ ──────────────────
     * لو أرسل العميل `pharmacy_medicine_id` لا يملكه، لخصمنا مخزون صيدلية
     * أخرى = ثغرة IDOR على الكتابة. الفحص هنا قبل أي كتابة.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $validated = $request->validate([
            'customer_id' => 'nullable|integer|exists:customers,id',
            'items' => 'required|array|min:1|max:200',
            'items.*.pharmacy_medicine_id' => 'nullable|integer',
            'items.*.medicine_id' => 'nullable|integer',
            'items.*.medicine_name' => 'required|string|max:200',
            'items.*.barcode' => 'nullable|string|max:20',
            'items.*.unit_price' => 'required|numeric|min:0|max:999999',
            'items.*.quantity' => 'required|integer|min:1|max:100000',
            'items.*.line_discount' => 'nullable|numeric|min:0|max:999999',
            'discount' => 'nullable|numeric|min:0|max:999999',
            'paid' => 'nullable|numeric|min:0|max:999999',
            'payment_method' => 'required|string|in:cash,card,bank_transfer,credit',
            'notes' => 'nullable|string|max:1000',
            'sold_at' => 'nullable|date',
        ], [
            'items.required' => 'الفاتورة يجب أن تحتوي على صنف واحد على الأقل',
            'items.*.medicine_name.required' => 'اسم الدواء مطلوب لكل صنف',
            'items.*.unit_price.required' => 'سعر الوحدة مطلوب لكل صنف',
            'items.*.quantity.required' => 'الكمية مطلوبة لكل صنف',
            'payment_method.in' => 'طريقة الدفع غير مدعومة',
            'customer_id.exists' => 'العميل غير موجود',
        ]);

        // العميل يجب أن يخصّ **هذه** الصيدلية.
        if (! empty($validated['customer_id'])) {
            $owns = \App\Models\Customer::query()
                ->forPharmacy($pharmacy->id)
                ->whereKey($validated['customer_id'])
                ->exists();

            if (! $owns) {
                return response()->json([
                    'success' => false,
                    'message' => 'العميل لا يتبع هذه الصيدلية',
                ], 422);
            }
        }

        // كل بند يملك pharmacy_medicine_id يجب أن يكون لهذه الصيدلية.
        $foreignIds = $this->foreignInventoryIds($pharmacy->id, $validated['items']);
        if ($foreignIds !== []) {
            return response()->json([
                'success' => false,
                'message' => 'بعض الأصناف لا تتبع مخزون هذه الصيدلية',
                'data' => ['invalid_pharmacy_medicine_ids' => $foreignIds],
            ], 422);
        }

        try {
            $sale = AccountingLedger::recordSale(
                $pharmacy->id,
                (int) $user->id,
                $validated
            );
        } catch (InvalidArgumentException $e) {
            // أخطاء منطقية متوقّعة (كمية غير كافية، خصم أكبر من المجموع...)
            // برسائل عربية جاهزة من البوّابة — تُعرض للمستخدم كما هي.
            throw ValidationException::withMessages([
                'items' => [$e->getMessage()],
            ]);
        }

        $sale->load(['customer', 'items']);

        return response()->json([
            'success' => true,
            'message' => 'تم حفظ الفاتورة بنجاح',
            'data' => $this->presentSale($sale, withItems: true),
        ], 201);
    }

    /**
     * إلغاء فاتورة — لا حذف.
     *
     * ── لماذا POST وليس DELETE؟ ────────────────────────────────────────
     * الإلغاء ليس حذفًا: يعيد المخزون، يعكس حركة الصندوق، ويعكس دين العميل،
     * ويُبقي الفاتورة في السجل. لو كان DELETE لظنّ المستخدم أن الفاتورة
     * انمحت ثم رآها في التقارير. `POST .../cancel` يقول الحقيقة.
     */
    public function cancel(Request $request, string $number): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $sale = Sale::query()
            ->forPharmacy($pharmacy->id)
            ->where('number', $number)
            ->first();

        if (! $sale) {
            return response()->json(['success' => false, 'message' => 'الفاتورة غير موجودة'], 404);
        }

        if ($sale->isCancelled()) {
            return response()->json([
                'success' => false,
                'message' => 'الفاتورة ملغاة بالفعل',
            ], 409);
        }

        try {
            $sale = AccountingLedger::cancelSale($sale, (int) $user->id);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $sale->load(['customer', 'items']);

        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء الفاتورة وإرجاع المخزون والصندوق',
            'data' => $this->presentSale($sale, withItems: true),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // عرض
    // ══════════════════════════════════════════════════════════════════

    /**
     * تحويل Sale إلى مصفوفة الاستجابة. المفاتيح مطابقة لما تنتظره
     * `resources/js/accounting/accounting-sales.js` حرفيًا.
     */
    private function presentSale(Sale $sale, bool $withItems = false): array
    {
        $payload = [
            'id' => $sale->id,
            'number' => $sale->number,
            'date' => $sale->sold_at?->toIso8601String(),
            'date_human' => $sale->sold_at?->format('Y-m-d H:i'),
            'customer_id' => $sale->customer_id,
            'customer' => $sale->customer_name ?: ($sale->customer->name ?? null),
            'items_count' => (int) $sale->items_count,
            'subtotal' => (float) $sale->subtotal,
            'discount' => (float) $sale->discount,
            'total' => (float) $sale->total,
            'paid' => (float) $sale->paid,
            'remaining' => (float) $sale->remaining,
            'method' => $sale->payment_method,
            'method_label' => __('accounting.payment_methods.'.$sale->payment_method),
            'status' => $sale->status,
            'status_label' => __('accounting.statuses.'.$sale->status),
            'notes' => $sale->notes,
        ];

        if ($withItems) {
            $payload['items'] = $sale->items->map(fn ($item) => [
                'id' => $item->id,
                'medicine_id' => $item->medicine_id,
                'pharmacy_medicine_id' => $item->pharmacy_medicine_id,
                'medicine_name' => $item->medicine_name,
                'barcode' => $item->barcode,
                'unit_price' => (float) $item->unit_price,
                'quantity' => (int) $item->quantity,
                'line_discount' => (float) $item->line_discount,
                'line_total' => (float) $item->line_total,
            ])->all();

            $payload['created_by'] = $sale->creator->name ?? null;
        }

        return $payload;
    }

    /**
     * إحصاءات القائمة المفلترة — تُحسب على نفس شرط `index`.
     *
     * @return array{total:float,count:int,paid:float,remaining:float,discount:float}
     */
    private function statsFor($query): array
    {
        $row = $query
            ->reorder()
            ->selectRaw(
                'COALESCE(SUM(total),0) as total, COUNT(*) as cnt, '.
                'COALESCE(SUM(paid),0) as paid, '.
                'COALESCE(SUM(remaining),0) as remaining, '.
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

    /**
     * فلترة التاريخ: إمّا `range` جاهز، أو `from`/`to` صريحان.
     * `from`/`to` يغلبان `range` لأن المستخدم اختارهما يدويًا.
     *
     * @return array{0:?Carbon,1:?Carbon}
     */
    private function resolveDateFilters(array $validated): array
    {
        if (! empty($validated['from'])) {
            $from = Carbon::parse($validated['from'])->startOfDay();
            $to = ! empty($validated['to'])
                ? Carbon::parse($validated['to'])->endOfDay()
                : Carbon::now()->endOfDay();

            return [$from, $to];
        }

        return match ($validated['range'] ?? null) {
            'today' => [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()],
            '7d' => [Carbon::today()->subDays(6)->startOfDay(), Carbon::today()->endOfDay()],
            '30d' => [Carbon::today()->subDays(29)->startOfDay(), Carbon::today()->endOfDay()],
            'month' => [Carbon::today()->startOfMonth(), Carbon::today()->endOfDay()],
            // بلا فلتر زمني = كل الفواتير (شاشة السجل الكامل).
            default => [null, null],
        };
    }

    /**
     * أي بند يحمل `pharmacy_medicine_id` لا يتبع هذه الصيدلية؟
     *
     * استعلام واحد بـ whereIn — لا استعلام لكل بند (تجنّب N+1 على الكتابة).
     *
     * @return list<int>
     */
    private function foreignInventoryIds(int $pharmacyId, array $items): array
    {
        $ids = collect($items)
            ->pluck('pharmacy_medicine_id')
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $owned = \App\Models\PharmacyMedicine::query()
            ->where('pharmacy_id', $pharmacyId)
            ->whereIn('id', $ids->all())
            ->pluck('id')
            ->all();

        return $ids->diff($owned)->values()->all();
    }
}
