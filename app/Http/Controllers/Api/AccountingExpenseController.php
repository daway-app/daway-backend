<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Services\Accounting\AccountingLedger;
use App\Services\Accounting\AccountingReports;
use App\Services\PharmacyContext;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * المصروفات — القائمة · الإنشاء · الإلغاء · التصنيفات.
 *
 * المسارات:
 *   GET    /api/pharmacy/accounting/expenses
 *   POST   /api/pharmacy/accounting/expenses
 *   POST   /api/pharmacy/accounting/expenses/{expense}/cancel
 *   GET    /api/pharmacy/accounting/expense-categories
 *
 * ── لماذا جدولان لا عمود نصّي؟ ──────────────────────────────────────
 * `expenses.category_key` نصّ حرّ يجعل التقارير تتفتّت: "كهرباء" و"الكهرباء"
 * يصيران تصنيفين. لكن — وبنفس الأهمية — التصنيف **لا يُربط بمفتاح أجنبي
 * إلزامي**: `expense_category_id` قابل للـnull، ويُحفظ `category_key` و
 * `category_name` كلقطة. فلو عُطِّل التصنيف أو أُعيدت تسميته، يبقى التقرير
 * التاريخي صحيحًا بلا انحراف.
 */
class AccountingExpenseController extends Controller
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
            'category' => 'nullable|string|max:40',
            'range' => 'nullable|string|max:10',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'include_cancelled' => 'nullable|boolean',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);

        $query = Expense::query()
            ->forPharmacy($pharmacy->id)
            ->with('creator:id,name')
            ->orderByDesc('expense_date')
            ->orderByDesc('id');

        // الملغى مخفي افتراضيًا. إظهاره اختياري صريح (للمراجعة التدقيقية).
        if (empty($validated['include_cancelled'])) {
            $query->active();
        }

        if (! empty($validated['search'])) {
            $needle = trim((string) $validated['search']);
            $query->where(function ($q) use ($needle) {
                $q->where('description', 'like', "%{$needle}%")
                    ->orWhere('reference', 'like', "%{$needle}%")
                    ->orWhere('category_name', 'like', "%{$needle}%");
            });
        }

        // تصنيف غير معروف ⇒ يُتجاهل بصمت (لا 422). فلترة على اللقطة لا
        // على المفتاح الأجنبي — فتلتقط مصروفات تصنيف عُطِّل لاحقًا.
        if (! empty($validated['category'])) {
            $query->where('category_key', $validated['category']);
        }

        [$from, $to] = $this->resolveDateFilters($validated);
        if ($from !== null && $to !== null) {
            $query->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()]);
        }

        $stats = $this->statsFor(clone $query, $pharmacy->id, $from, $to);

        $expenses = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب المصروفات',
            'data' => collect($expenses->items())->map(fn (Expense $e) => $this->present($e))->all(),
            'pagination' => [
                'total' => $expenses->total(),
                'per_page' => $expenses->perPage(),
                'current_page' => $expenses->currentPage(),
                'last_page' => $expenses->lastPage(),
            ],
            'stats' => $stats,
        ]);
    }

    /** إنشاء مصروف — يمرّ عبر البوّابة الوحيدة. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $validated = $request->validate([
            'category_key' => 'required|string|max:40',
            'amount' => 'required|numeric|min:0.01|max:999999',
            'description' => 'nullable|string|max:500',
            'reference' => 'nullable|string|max:60',
            'payment_method' => 'required|string|in:cash,card,bank_transfer',
            'expense_date' => 'nullable|date',
        ], [
            'category_key.required' => 'تصنيف المصروف مطلوب',
            'amount.required' => 'المبلغ مطلوب',
            'amount.min' => 'المبلغ يجب أن يكون أكبر من صفر',
            'payment_method.in' => 'طريقة الدفع غير مدعومة',
        ]);

        // التصنيف يجب أن يخصّ هذه الصيدلية (لو أُرسل id).
        if (! empty($validated['expense_category_id'])) {
            $owns = \App\Models\ExpenseCategory::query()
                ->forPharmacy($pharmacy->id)
                ->whereKey($validated['expense_category_id'])
                ->exists();

            if (! $owns) {
                return response()->json([
                    'success' => false,
                    'message' => 'التصنيف لا يتبع هذه الصيدلية',
                ], 422);
            }
        }

        try {
            $expense = AccountingLedger::recordExpense(
                $pharmacy->id,
                (int) $user->id,
                $validated
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => [$e->getMessage()]]);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل المصروف',
            'data' => $this->present($expense),
        ], 201);
    }

    /** إلغاء مصروف — يعكس حركة الصندوق، ويُبقيه في السجل. */
    public function cancel(Request $request, Expense $expense): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        // Route binding يجلب بأي pharmacy — القيد هنا إلزامي.
        if ((int) $expense->pharmacy_id !== (int) $pharmacy->id) {
            return response()->json(['success' => false, 'message' => 'المصروف غير موجود'], 404);
        }

        if ($expense->is_cancelled) {
            return response()->json([
                'success' => false,
                'message' => 'المصروف ملغى بالفعل',
            ], 409);
        }

        try {
            $expense = AccountingLedger::cancelExpense($expense, (int) $user->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء المصروف وإرجاع المبلغ للصندوق',
            'data' => $this->present($expense),
        ]);
    }

    /**
     * تصنيفات المصروفات المتاحة.
     *
     * يُنشئ التصنيفات الافتراضية كسولًا إن لم توجد — كي لا تظهر شاشة
     * المصروفات فارغة بلا سبب في صيدلية جديدة.
     */
    public function categories(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $pharmacy = PharmacyContext::forUser($user);
        if (! $pharmacy) {
            return response()->json(['success' => false, 'message' => 'الصيدلية غير موجودة'], 404);
        }

        $categories = AccountingReports::expenseCategories($pharmacy->id);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب تصنيفات المصروفات',
            'data' => $categories->map(fn ($c) => [
                'id' => $c->id,
                'key' => $c->key,
                'label' => $c->name_ar,
                'sort_order' => (int) $c->sort_order,
            ])->values()->all(),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════

    private function present(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'category_key' => $expense->category_key,
            'category_label' => $expense->category_name
                ?: __('accounting.expenses.category.'.$expense->category_key),
            'amount' => (float) $expense->amount,
            'description' => $expense->description,
            'reference' => $expense->reference,
            'payment_method' => $expense->payment_method,
            'method_label' => __('accounting.payment_methods.'.$expense->payment_method),
            'expense_date' => $expense->expense_date?->toDateString(),
            'date_human' => $expense->expense_date?->format('Y-m-d'),
            'is_cancelled' => (bool) $expense->is_cancelled,
            'created_by' => $expense->creator->name ?? null,
        ];
    }

    /**
     * إحصاءات المصروفات المفلترة + التوزيع (للرسم الدائري).
     * التوزيع يُحسب بنفس الفلتر الزمني المعروض — لا على الشهر دائمًا،
     * وإلا اختلف الرسم مع الجدول بلا سبب مفهوم للمستخدم.
     */
    private function statsFor($query, int $pharmacyId, ?Carbon $from, ?Carbon $to): array
    {
        $row = $query
            ->reorder()
            ->selectRaw('COALESCE(SUM(amount),0) as total, COUNT(*) as cnt')
            ->first();

        return [
            'total' => round((float) ($row->total ?? 0), 2),
            'count' => (int) ($row->cnt ?? 0),
            'breakdown' => AccountingReports::expenseBreakdown($pharmacyId, $from, $to),
        ];
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
