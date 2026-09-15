<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Supplier;
use App\Services\Accounting\AccountingLedger;
use App\Services\Accounting\AccountingReports;
use App\Services\PharmacyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * الأطراف — العملاء والموردون — والتحصيل/الدفع.
 *
 * المسارات:
 *   GET    /api/pharmacy/accounting/customers
 *   POST   /api/pharmacy/accounting/customers
 *   GET    /api/pharmacy/accounting/customers/{customer}
 *   POST   /api/pharmacy/accounting/customers/{customer}/payments
 *   GET    /api/pharmacy/accounting/suppliers
 *   POST   /api/pharmacy/accounting/suppliers
 *   POST   /api/pharmacy/accounting/suppliers/{supplier}/payments
 *
 * ── لماذا التحصيل endpoint منفصل عن الفاتورة؟ ───────────────────────
 * العميل قد يدفع دفعة عامة لا تخصّ فاتورة بعينها (سدّد 200 من أصل 450
 * موزّعة على ثلاث فواتير). لو ربطنا التحصيل بفاتورة إلزاميًا لما أمكن
 * تسجيل هذا الواقع. لذا: الدفعة تُسجَّل على **العميل**، و`customer_payments.sale_id`
 * يبقى اختياريًا لمن أراد الربط بفاتورة محدّدة.
 *
 * ── لماذا الشرط `pharmacy_id` في كل endpoint؟ ───────────────────────
 * لأن كل هذه الجداول مملوكة للصيدلية. بلا القيد، رقم عميل من صيدلية أخرى
 * يُقرأ ويُعدَّل = ثغرة IDOR صريحة.
 */
class AccountingPartiesController extends Controller
{
    // ══════════════════════════════════════════════════════════════════
    // العملاء
    // ══════════════════════════════════════════════════════════════════

    public function customers(Request $request): JsonResponse
    {
        $pharmacy = $this->pharmacyOr404($request);
        if ($pharmacy instanceof JsonResponse) {
            return $pharmacy;
        }

        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'search' => 'nullable|string|max:100',
            'only_debtors' => 'nullable|boolean',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);

        $query = Customer::query()
            ->forPharmacy($pharmacy->id)
            ->orderByDesc('current_balance')
            ->orderBy('name');

        if (! empty($validated['search'])) {
            $needle = trim((string) $validated['search']);
            $query->where(function ($q) use ($needle) {
                $q->where('name', 'like', "%{$needle}%")
                    ->orWhere('phone', 'like', "%{$needle}%");
            });
        }

        if (! empty($validated['only_debtors'])) {
            $query->where('current_balance', '>', 0);
        }

        $customers = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب العملاء',
            'data' => collect($customers->items())->map(fn (Customer $c) => $this->presentCustomer($c))->all(),
            'pagination' => [
                'total' => $customers->total(),
                'per_page' => $customers->perPage(),
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
            ],
            'stats' => [
                'total_debt' => AccountingReports::customerReceivablesTotal($pharmacy->id),
                'customers_count' => Customer::query()->forPharmacy($pharmacy->id)->where('is_active', true)->count(),
                'debtors_count' => Customer::query()->forPharmacy($pharmacy->id)->where('is_active', true)->where('current_balance', '>', 0)->count(),
            ],
        ]);
    }

    public function storeCustomer(Request $request): JsonResponse
    {
        $pharmacy = $this->pharmacyOr404($request);
        if ($pharmacy instanceof JsonResponse) {
            return $pharmacy;
        }

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'phone' => 'nullable|string|max:25',
            'email' => 'nullable|email|max:150',
            'notes' => 'nullable|string|max:500',
            'credit_limit' => 'nullable|numeric|min:0|max:9999999',
        ], [
            'name.required' => 'اسم العميل مطلوب',
            'email.email' => 'البريد الإلكتروني غير صحيح',
        ]);

        // فرادة الهاتف **داخل الصيدلية** فقط. لو وُجد عميل بنفس الرقم
        // نرجعه بلا خطأ — لأن الهدف العملي «أعطني عميل هذا الرقم»،
        // وإنشاء نسخة ثانية يقسم دينه على حسابين.
        if (! empty($validated['phone'])) {
            $existing = Customer::query()
                ->forPharmacy($pharmacy->id)
                ->where('phone', $validated['phone'])
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => true,
                    'message' => 'العميل مسجَّل مسبقًا بنفس الرقم',
                    'data' => $this->presentCustomer($existing),
                ]);
            }
        }

        $customer = Customer::create([
            'pharmacy_id' => $pharmacy->id,
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'credit_limit' => $validated['credit_limit'] ?? 0,
            'current_balance' => 0,
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إضافة العميل',
            'data' => $this->presentCustomer($customer),
        ], 201);
    }

    /** تفاصيل عميل: الفواتير والدفعات وسجلّه. */
    public function showCustomer(Request $request, Customer $customer): JsonResponse
    {
        $pharmacy = $this->pharmacyOr404($request);
        if ($pharmacy instanceof JsonResponse) {
            return $pharmacy;
        }

        if ((int) $customer->pharmacy_id !== (int) $pharmacy->id) {
            return response()->json(['success' => false, 'message' => 'العميل غير موجود'], 404);
        }

        $sales = $customer->sales()
            ->orderByDesc('sold_at')
            ->limit(50)
            ->get()
            ->map(fn ($sale) => [
                'number' => $sale->number,
                'date' => $sale->sold_at?->toDateString(),
                'total' => (float) $sale->total,
                'paid' => (float) $sale->paid,
                'remaining' => (float) $sale->remaining,
                'status' => $sale->status,
                'status_label' => __('accounting.statuses.'.$sale->status),
            ])->all();

        $payments = $customer->payments()
            ->active()
            ->orderByDesc('paid_at')
            ->limit(50)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'method' => $p->payment_method,
                'date' => $p->paid_at?->toDateString(),
                'reference' => $p->reference,
            ])->all();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب بيانات العميل',
            'data' => array_merge($this->presentCustomer($customer), [
                'sales' => $sales,
                'payments' => $payments,
            ]),
        ]);
    }

    /** تسجيل دفعة من عميل — تُخفّض دينه وتزيد الصندوق (إن كانت نقدية). */
    public function storeCustomerPayment(Request $request, Customer $customer): JsonResponse
    {
        $pharmacy = $this->pharmacyOr404($request);
        if ($pharmacy instanceof JsonResponse) {
            return $pharmacy;
        }

        if ((int) $customer->pharmacy_id !== (int) $pharmacy->id) {
            return response()->json(['success' => false, 'message' => 'العميل غير موجود'], 404);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:999999',
            'payment_method' => 'required|string|in:cash,card,bank_transfer',
            'sale_id' => 'nullable|integer|exists:sales,id',
            'reference' => 'nullable|string|max:60',
            'description' => 'nullable|string|max:500',
            'paid_at' => 'nullable|date',
        ], [
            'amount.min' => 'مبلغ الدفعة يجب أن يكون أكبر من صفر',
            'payment_method.in' => 'طريقة الدفع غير مدعومة',
        ]);

        // الفاتورة (لو أُرسلت) يجب أن تكون لهذا العميل وهذه الصيدلية.
        if (! empty($validated['sale_id'])) {
            $ownsSale = \App\Models\Sale::query()
                ->forPharmacy($pharmacy->id)
                ->whereKey($validated['sale_id'])
                ->where('customer_id', $customer->id)
                ->exists();

            if (! $ownsSale) {
                return response()->json([
                    'success' => false,
                    'message' => 'الفاتورة لا تتبع هذا العميل',
                ], 422);
            }
        }

        try {
            $payment = AccountingLedger::recordCustomerPayment(
                $pharmacy->id,
                (int) $request->user()->id,
                $customer,
                $validated
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => [$e->getMessage()]]);
        }

        $customer->refresh();

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الدفعة',
            'data' => [
                'payment' => [
                    'id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'method' => $payment->payment_method,
                    'date' => $payment->paid_at?->toDateString(),
                ],
                'customer' => $this->presentCustomer($customer),
            ],
        ], 201);
    }

    // ══════════════════════════════════════════════════════════════════
    // الموردون
    // ══════════════════════════════════════════════════════════════════

    public function suppliers(Request $request): JsonResponse
    {
        $pharmacy = $this->pharmacyOr404($request);
        if ($pharmacy instanceof JsonResponse) {
            return $pharmacy;
        }

        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:100',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);

        $query = Supplier::query()
            ->forPharmacy($pharmacy->id)
            ->orderByDesc('current_balance')
            ->orderBy('name');

        if (! empty($validated['search'])) {
            $needle = trim((string) $validated['search']);
            $query->where(function ($q) use ($needle) {
                $q->where('name', 'like', "%{$needle}%")
                    ->orWhere('company', 'like', "%{$needle}%")
                    ->orWhere('phone', 'like', "%{$needle}%");
            });
        }

        $suppliers = $query->get();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب الموردين',
            'data' => $suppliers->map(fn (Supplier $s) => $this->presentSupplier($s))->all(),
            'stats' => [
                'total_payables' => AccountingReports::supplierPayablesTotal($pharmacy->id),
                'suppliers_count' => $suppliers->where('is_active', true)->count(),
            ],
        ]);
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        $pharmacy = $this->pharmacyOr404($request);
        if ($pharmacy instanceof JsonResponse) {
            return $pharmacy;
        }

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'company' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:25',
            'email' => 'nullable|email|max:150',
            'notes' => 'nullable|string|max:500',
        ], ['name.required' => 'اسم المورّد مطلوب']);

        if (! empty($validated['phone'])) {
            $existing = Supplier::query()
                ->forPharmacy($pharmacy->id)
                ->where('phone', $validated['phone'])
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => true,
                    'message' => 'المورّد مسجَّل مسبقًا بنفس الرقم',
                    'data' => $this->presentSupplier($existing),
                ]);
            }
        }

        $supplier = Supplier::create([
            'pharmacy_id' => $pharmacy->id,
            'name' => $validated['name'],
            'company' => $validated['company'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'current_balance' => 0,
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إضافة المورّد',
            'data' => $this->presentSupplier($supplier),
        ], 201);
    }

    /** دفعة لمورّد — تُخفّض ما علينا له وتُخرج نقدًا من الصندوق (إن نقدية). */
    public function storeSupplierPayment(Request $request, Supplier $supplier): JsonResponse
    {
        $pharmacy = $this->pharmacyOr404($request);
        if ($pharmacy instanceof JsonResponse) {
            return $pharmacy;
        }

        if ((int) $supplier->pharmacy_id !== (int) $pharmacy->id) {
            return response()->json(['success' => false, 'message' => 'المورّد غير موجود'], 404);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:999999',
            'payment_method' => 'required|string|in:cash,card,bank_transfer',
            'purchase_id' => 'nullable|integer|exists:purchases,id',
            'reference' => 'nullable|string|max:60',
            'description' => 'nullable|string|max:500',
            'paid_at' => 'nullable|date',
        ], ['amount.min' => 'مبلغ الدفعة يجب أن يكون أكبر من صفر']);

        if (! empty($validated['purchase_id'])) {
            $ownsPurchase = \App\Models\Purchase::query()
                ->forPharmacy($pharmacy->id)
                ->whereKey($validated['purchase_id'])
                ->where('supplier_id', $supplier->id)
                ->exists();

            if (! $ownsPurchase) {
                return response()->json([
                    'success' => false,
                    'message' => 'أمر الشراء لا يتبع هذا المورّد',
                ], 422);
            }
        }

        try {
            $payment = AccountingLedger::recordSupplierPayment(
                $pharmacy->id,
                (int) $request->user()->id,
                $supplier,
                $validated
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => [$e->getMessage()]]);
        }

        $supplier->refresh();

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الدفعة للمورّد',
            'data' => [
                'payment' => [
                    'id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'method' => $payment->payment_method,
                    'date' => $payment->paid_at?->toDateString(),
                ],
                'supplier' => $this->presentSupplier($supplier),
            ],
        ], 201);
    }

    // ══════════════════════════════════════════════════════════════════
    // مساعدات
    // ══════════════════════════════════════════════════════════════════

    /**
     * الصيدلية أو استجابة 403/404 جاهزة.
     *
     * نُرجع JsonResponse عند الفشل بدل `abort` كي يبقى نمط الاستجابة موحّدًا
     * `{success:false,message:...}` كما في بقية الـAPI بدل صفحة HTML.
     *
     * @return \App\Models\Pharmacy|JsonResponse
     */
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

    private function presentCustomer(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'notes' => $customer->notes,
            'current_balance' => (float) $customer->current_balance,
            'credit_limit' => (float) $customer->credit_limit,
            'credit_limit_label' => (float) $customer->credit_limit > 0
                ? AccountingReports::money((float) $customer->credit_limit)
                : 'غير محدود',
            'is_active' => (bool) $customer->is_active,
            'exceeds_credit_limit' => $customer->exceedsCreditLimit(),
            'created_at' => $customer->created_at?->toIso8601String(),
        ];
    }

    private function presentSupplier(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'company' => $supplier->company,
            'phone' => $supplier->phone,
            'email' => $supplier->email,
            'notes' => $supplier->notes,
            'current_balance' => (float) $supplier->current_balance,
            'is_active' => (bool) $supplier->is_active,
            'created_at' => $supplier->created_at?->toIso8601String(),
        ];
    }
}
