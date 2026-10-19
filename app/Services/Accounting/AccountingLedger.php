<?php

namespace App\Services\Accounting;

use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Support\LowStockNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * دفتر المحاسبة — نقطة الكتابة الوحيدة لكل حركة مالية.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ **قاعدة صارمة:** لا يكتب أي Controller في جداول المحاسبة مباشرة.
 * كل عملية تمرّ من هنا، والسبب:
 *
 *   1. **الذرّية (atomicity).** الفاتورة + أسطرها + دفتر الصندوق + خصم المخزون
 *      + رصيد العميل — خمس كتابات يجب أن تنجح كلها أو تفشل كلها. لو كتب
 *      Controller الفاتورة ثم فشل خصم المخزون، صار لدينا مخزون وهمي وفاتورة
 *      بلا بضاعة. الـtransaction هنا هو الحماية.
 *
 *   2. **اتساق الأرصدة.** `customers.current_balance` و`cash_movements` مشتقّان
 *      من نفس العملية. فصلهما في أماكن مختلفة يسمح بانحراف صامت — أسوأ عطل
 *      ممكن في نظام محاسبة.
 *
 *   3. **قابلية إعادة الحساب.** لأن الرصيد مجموع حركات (لا قيمة مخزّنة بلا
 *      مصدر)، يمكن دائمًا إعادة بناء الواقع من الدفتر.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * الخصم من المخزون: **ذرّي بشرط على القيمة الحالية** (لا قراءة-ثم-كتابة).
 * استخدمنا `UPDATE ... WHERE quantity >= ?` مع فحص عدد الصفوف المتأثرة، وهو
 * الشكل الآمن ضد سباق التزامن (race). النمط البديل — قراءة الكمية في PHP ثم
 * تحديثها — يسمح ببيع نفس العلبة مرتين لو ضغط كاشيران الحفظ معًا.
 */
final class AccountingLedger
{
    /**
     * إنشاء فاتورة بيع كاملة: فاتورة + أسطر + خصم مخزون + دفتر صندوق + رصيد عميل.
     *
     * @param  array{
     *     number?: string, customer_id?: int|null, customer_name?: string|null,
     *     discount?: float, payment_method?: string, paid?: float,
     *     notes?: string|null, sold_at?: \DateTimeInterface|string|null,
     *     items: list<array{
     *         medicine_id?: int|null, pharmacy_medicine_id?: int|null,
     *         medicine_name: string, barcode?: string|null,
     *         unit_price: float, quantity: int, line_discount?: float
     *     }>
     * }  $data
     *
     * @throws InvalidArgumentException عند فاتورة بلا أسطر، أو صنف غير موجود بالمخزون
     */
    public static function recordSale(int $pharmacyId, int $userId, array $data): Sale
    {
        $items = $data['items'] ?? [];

        if (count($items) === 0) {
            throw new InvalidArgumentException('الفاتورة يجب أن تحتوي على صنف واحد على الأقل');
        }

        // معرّفات أصناف المخزون التي خُصمت فعليًا — تُجمَّع داخل المعاملة
        // ويُشعَر عنها **بعد** الـcommit فقط، حتى لا يُرسَل إشعار عن بيع تراجعت.
        $affectedPmIds = [];

        $sale = DB::transaction(function () use ($pharmacyId, $userId, $data, $items, &$affectedPmIds) {
            $soldAt = isset($data['sold_at'])
                ? Carbon::parse($data['sold_at'])
                : Carbon::now();

            // ── 1) حساب المجاميع قبل أي كتابة ─────────────────────────────
            $subtotal = 0.0;
            $prepared = [];

            foreach ($items as $row) {
                $quantity = (int) ($row['quantity'] ?? 1);
                if ($quantity < 1) {
                    throw new InvalidArgumentException('كمية الصنف يجب أن تكون 1 على الأقل');
                }

                $unitPrice = (float) ($row['unit_price'] ?? 0);
                $lineDiscount = (float) ($row['line_discount'] ?? 0);
                $lineTotal = SaleItem::computeLineTotal($unitPrice, $quantity, $lineDiscount);

                $subtotal += $lineTotal;
                $prepared[] = [
                    'row' => $row,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_discount' => $lineDiscount,
                    'line_total' => $lineTotal,
                ];
            }

            $subtotal = round($subtotal, 2);
            $invoiceDiscount = round((float) ($data['discount'] ?? 0), 2);
            $total = round($subtotal - $invoiceDiscount, 2);

            if ($total < 0) {
                throw new InvalidArgumentException('إجمالي الفاتورة لا يمكن أن يكون سالبًا');
            }

            $method = (string) ($data['payment_method'] ?? Sale::METHOD_CASH);

            // الآجل: لا يُدفع شيء وقت البيع. غيره: يُدفع الإجمالي (أو جزء صريح).
            $paid = array_key_exists('paid', $data) && $data['paid'] !== null
                ? round((float) $data['paid'], 2)
                : ($method === Sale::METHOD_CREDIT ? 0.0 : $total);

            if ($paid > $total) {
                // الدفع الزائد ليس خطأ محاسبيًا صارمًا لكنه غالبًا خطأ إدخال.
                $paid = $total;
            }

            $remaining = round($total - $paid, 2);

            // ── 2) خصم المخزون **قبل** إنشاء الفاتورة ─────────────────────
            // الترتيب مقصود: لو المخزون لا يكفي نفشل بلا كتابة أي فاتورة.
            foreach ($prepared as $p) {
                $pmId = $p['row']['pharmacy_medicine_id'] ?? null;
                if ($pmId === null) {
                    continue; // صنف بلا ربط مخزون (بيع من الكتالوج العام) — لا خصم
                }

                static::decrementStockOrFail($pharmacyId, (int) $pmId, $p['quantity']);

                $affectedPmIds[] = (int) $pmId;
            }

            // ── 3) الفاتورة ──────────────────────────────────────────────
            $customer = null;
            if (! empty($data['customer_id'])) {
                $customer = Customer::forPharmacy($pharmacyId)->find($data['customer_id']);
            }

            $sale = Sale::create([
                'pharmacy_id' => $pharmacyId,
                'number' => $data['number'] ?? static::nextSaleNumber($pharmacyId),
                'customer_id' => $customer?->id,
                'customer_name' => $customer?->name ?? ($data['customer_name'] ?? null),
                'subtotal' => $subtotal,
                'discount' => $invoiceDiscount,
                'total' => $total,
                'paid' => $paid,
                'remaining' => $remaining,
                'payment_method' => $method,
                'status' => Sale::deriveStatus($total, $paid),
                'items_count' => count($prepared),
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
                'sold_at' => $soldAt,
            ]);

            // ── 4) الأسطر (سنابشوت) ──────────────────────────────────────
            foreach ($prepared as $p) {
                $row = $p['row'];
                $sale->items()->create([
                    'medicine_id' => $row['medicine_id'] ?? null,
                    'pharmacy_medicine_id' => $row['pharmacy_medicine_id'] ?? null,
                    'medicine_name' => (string) $row['medicine_name'],
                    'barcode' => $row['barcode'] ?? null,
                    'unit_price' => $p['unit_price'],
                    'quantity' => $p['quantity'],
                    'line_discount' => $p['line_discount'],
                    'line_total' => $p['line_total'],
                ]);
            }

            // ── 5) دفتر الصندوق: النقدية فقط تدخل الصندوق ─────────────────
            // البطاقة/التحويل لا تمرّ بالصندوق النقدي لكنها تبقى مدفوعة.
            if ($paid > 0 && static::isCashTender($method)) {
                static::recordCash(
                    $pharmacyId,
                    CashMovement::DIRECTION_IN,
                    $paid,
                    CashMovement::SOURCE_SALE,
                    $sale->id,
                    'فاتورة بيع '.$sale->number,
                    $soldAt,
                    $userId
                );
            }

            // ── 6) رصيد العميل: الآجل فقط يزيد الدين ─────────────────────
            if ($customer !== null && $remaining > 0) {
                static::adjustCustomerBalance($customer, $remaining);
            }

            return $sale->load('items');
        });

        // ── إشعارات المخزون: **بعد الـcommit فقط** ─────────────────────────
        // الموضع مقصود (M-2.5): أي إشعار داخل المعاملة كان قد يُرسَل عن بيع
        // تراجعت. هنا البيع محسوم، وفشل الإشعار يُسجَّل ولا يُسقطه.
        try {
            LowStockNotifier::notifyForMany($pharmacyId, $affectedPmIds);
        } catch (\Throwable $e) {
            Log::error('sale stock notification failed', [
                'pharmacy_id' => $pharmacyId,
                'sale_id' => $sale->id,
                'affected_count' => count($affectedPmIds),
                'error' => $e->getMessage(),
            ]);
        }

        return $sale;
    }

    /**
     * إلغاء فاتورة: يعكس كل آثارها (مخزون + صندوق + رصيد عميل).
     *
     * ⚠️ لا نحذف الفاتورة — نغيّر حالتها فقط. `stock_restored` غير مخزّن:
     * حماية من الإلغاء المزدوج هي فحص `status` نفسه داخل الـtransaction.
     *
     * @throws InvalidArgumentException لو ملغاة أصلًا
     */
    public static function cancelSale(Sale $sale, int $userId): Sale
    {
        if ($sale->isCancelled()) {
            throw new InvalidArgumentException('الفاتورة ملغاة مسبقًا');
        }

        return DB::transaction(function () use ($sale, $userId) {
            // إعادة القفل: الفاتورة قد تكون تغيّرت بين القراءة والكتابة
            $fresh = Sale::whereKey($sale->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->isCancelled()) {
                throw new InvalidArgumentException('الفاتورة ملغاة مسبقًا');
            }

            // فاتورة أُجري عليها إرجاع لا تُلغى: الإرجاع أعاد بعض المخزون
            // وألغى بعض الدين، والإلغاء سيعيد **كل** المخزون ويقلب الدين ⇒
            // مضاعفة. نرفض بدل كتابة واقع غير موجود.
            if ((float) $fresh->refunded_amount > 0) {
                throw new InvalidArgumentException('لا يمكن إلغاء فاتورة أُجري عليها إرجاع');
            }

            // ── 1) إرجاع المخزون ─────────────────────────────────────────
            foreach ($fresh->items()->get() as $item) {
                if ($item->pharmacy_medicine_id === null) {
                    continue;
                }

                DB::table('pharmacy_medicines')
                    ->where('id', $item->pharmacy_medicine_id)
                    ->where('pharmacy_id', $fresh->pharmacy_id)
                    ->increment('quantity', (int) $item->quantity);
            }

            // ── 2) عكس حركة الصندوق المرتبطة ─────────────────────────────
            static::reverseCashFor(
                $fresh->pharmacy_id,
                CashMovement::SOURCE_SALE,
                $fresh->id,
                $userId,
                'إلغاء فاتورة '.$fresh->number
            );

            // ── 3) عكس دين العميل ────────────────────────────────────────
            if ($fresh->customer_id !== null && (float) $fresh->remaining > 0) {
                $customer = Customer::forPharmacy($fresh->pharmacy_id)->find($fresh->customer_id);
                if ($customer !== null) {
                    static::adjustCustomerBalance($customer, -1 * (float) $fresh->remaining);
                }
            }

            $fresh->update([
                'status' => Sale::STATUS_CANCELLED,
                'notes' => trim(($fresh->notes ? $fresh->notes."\n" : '').'أُلغيت'),
            ]);

            return $fresh;
        });
    }

    /**
     * تسجيل مصروف.
     */
    public static function recordExpense(int $pharmacyId, int $userId, array $data): Expense
    {
        return DB::transaction(function () use ($pharmacyId, $userId, $data) {
            $amount = round((float) ($data['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw new InvalidArgumentException('مبلغ المصروف يجب أن يكون أكبر من صفر');
            }

            $date = isset($data['expense_date'])
                ? Carbon::parse($data['expense_date'])
                : Carbon::now();

            $method = (string) ($data['payment_method'] ?? 'cash');

            $expense = Expense::create([
                'pharmacy_id' => $pharmacyId,
                'expense_category_id' => $data['expense_category_id'] ?? null,
                'category_key' => $data['category_key'] ?? null,
                'category_name' => $data['category_name'] ?? null,
                'amount' => $amount,
                'description' => $data['description'] ?? null,
                'reference' => $data['reference'] ?? null,
                'payment_method' => $method,
                'expense_date' => $date,
                'created_by' => $userId,
            ]);

            // المصروف النقدي فقط يخرج من الصندوق
            if (static::isCashTender($method)) {
                static::recordCash(
                    $pharmacyId,
                    CashMovement::DIRECTION_OUT,
                    $amount,
                    CashMovement::SOURCE_EXPENSE,
                    $expense->id,
                    $expense->category_name ?: 'مصروف',
                    $date,
                    $userId
                );
            }

            return $expense;
        });
    }

    /** إلغاء مصروف — يعكس حركة الصندوق ولا يحذف السجل. */
    public static function cancelExpense(Expense $expense, int $userId): Expense
    {
        if ($expense->is_cancelled) {
            throw new InvalidArgumentException('المصروف ملغى مسبقًا');
        }

        return DB::transaction(function () use ($expense, $userId) {
            $fresh = Expense::whereKey($expense->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->is_cancelled) {
                throw new InvalidArgumentException('المصروف ملغى مسبقًا');
            }

            static::reverseCashFor(
                $fresh->pharmacy_id,
                CashMovement::SOURCE_EXPENSE,
                $fresh->id,
                $userId,
                'إلغاء مصروف'
            );

            $fresh->update([
                'is_cancelled' => true,
                'cancelled_at' => Carbon::now(),
                'cancelled_by' => $userId,
            ]);

            return $fresh;
        });
    }

    /**
     * تسجيل تحصيل من عميل: ينقص دينه ويزيد الصندوق.
     *
     * إن ارتبطت الدفعة بفاتورة بيع (`sale_id`) تُحدَّث الفاتورة نفسها
     * (paid/remaining/status) داخل نفس المعاملة — نظير فرع الموردين أدناه —
     * وإلا بقيت الفاتورة «غير مدفوعة» إلى الأبد رغم تحصيل المبلغ فعليًا
     * (الصندوق ودَين العميل يتصرفان)، وهو انحراف صامت في التقارير.
     */
    public static function recordCustomerPayment(int $pharmacyId, int $userId, Customer $customer, array $data): CustomerPayment
    {
        if ($customer->pharmacy_id !== $pharmacyId) {
            throw new InvalidArgumentException('العميل لا يخص هذه الصيدلية');
        }

        return DB::transaction(function () use ($pharmacyId, $userId, $customer, $data) {
            $amount = round((float) ($data['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw new InvalidArgumentException('مبلغ الدفعة يجب أن يكون أكبر من صفر');
            }

            $date = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : Carbon::now();
            $method = (string) ($data['payment_method'] ?? 'cash');

            $payment = CustomerPayment::create([
                'pharmacy_id' => $pharmacyId,
                'customer_id' => $customer->id,
                'sale_id' => $data['sale_id'] ?? null,
                'amount' => $amount,
                'payment_method' => $method,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'paid_at' => $date,
                'created_by' => $userId,
            ]);

            // النقد الفعلي فقط يدخل الصندوق. تحصيل البطاقة/التحويل يُسجَّل في
            // `customer_payments` (بوسيلته ومرجعه ووقته) لكنه **لا** يزيد النقد
            // في الدرج — نظير بيع البطاقة/التحويل الذي لا يمرّ بالصندوق.
            // إدخاله هنا كان ينفخ الجرد النقدي كذبًا.
            if (static::isCashTender($method)) {
                static::recordCash(
                    $pharmacyId,
                    CashMovement::DIRECTION_IN,
                    $amount,
                    CashMovement::SOURCE_CUSTOMER_PAYMENT,
                    $payment->id,
                    'تحصيل من '.$customer->name,
                    $date,
                    $userId
                );
            }

            static::adjustCustomerBalance($customer, -1 * $amount);

            // فاتورة البيع (إن أُرسلت) تُحدَّث كما يحصل مع فاتورة الشراء في
            // `recordSupplierPayment`: لا دفعة على فاتورة لن تظهر في ميزانها.
            if (! empty($data['sale_id'])) {
                $sale = Sale::forPharmacy($pharmacyId)
                    ->whereKey($data['sale_id'])
                    ->lockForUpdate()
                    ->first();

                if ($sale === null) {
                    throw new InvalidArgumentException('الفاتورة لا تتبع هذه الصيدلية');
                }

                if ((int) $sale->customer_id !== (int) $customer->id) {
                    throw new InvalidArgumentException('الفاتورة لا تتبع هذا العميل');
                }

                if ($sale->isCancelled() || $sale->status === Sale::STATUS_REFUNDED) {
                    throw new InvalidArgumentException('لا يمكن تسجيل دفعة على فاتورة ملغاة أو مرجوعة');
                }

                // السقف **صافي** الفاتورة (الإجمالي − قيمة البضاعة المرتجعة)،
                // لا الإجمالي التاريخي: لا يعقل أن يسدّد العميل ثمن بضاعة أرجعها.
                $netTotal = $sale->netTotal();
                $newPaid = round((float) $sale->paid + $amount, 2);

                // لا تتجاوز الفاتورة صافيتها: دفعة زائدة تبقى في رصيد العميل
                // (سلفة) لكنها لا تجعل الفاتورة «مدفوعة أكثر من قيمتها».
                if (bccomp((string) $newPaid, (string) $netTotal, 2) > 0) {
                    $newPaid = $netTotal;
                }

                $sale->forceFill([
                    'paid' => $newPaid,
                    'remaining' => round($netTotal - $newPaid, 2),
                    'status' => Sale::deriveRefundAwareStatus(
                        (float) $sale->total,
                        (float) $sale->refunded_amount,
                        $netTotal,
                        $newPaid
                    ),
                ])->save();
            }

            return $payment;
        });
    }

    /**
     * تسجيل دفعة لمورد: تنقص ديننا له وتنقص الصندوق.
     *
     * إن ارتبطت الدفعة بفاتورة شراء (`purchase_id`) تُحدَّث الفاتورة نفسها
     * (paid/remaining/status) داخل نفس المعاملة — وإلا بقيت الفاتورة
     * «غير مدفوعة» إلى الأبد رغم تسجيل الدفعة، وهو انحراف صامت في التقارير.
     */
    public static function recordSupplierPayment(int $pharmacyId, int $userId, Supplier $supplier, array $data): SupplierPayment
    {
        if ($supplier->pharmacy_id !== $pharmacyId) {
            throw new InvalidArgumentException('المورد لا يخص هذه الصيدلية');
        }

        return DB::transaction(function () use ($pharmacyId, $userId, $supplier, $data) {
            $amount = round((float) ($data['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw new InvalidArgumentException('مبلغ الدفعة يجب أن يكون أكبر من صفر');
            }

            $date = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : Carbon::now();
            $method = (string) ($data['payment_method'] ?? 'cash');

            // فاتورة الشراء (إن أُرسلت) يجب أن تخصّ هذه الصيدلية وهذا المورد،
            // وألا تكون ملغاة — وإلا صار الدفع لفاتورة وهمية.
            $purchase = null;
            if (! empty($data['purchase_id'])) {
                $purchase = Purchase::forPharmacy($pharmacyId)
                    ->whereKey($data['purchase_id'])
                    ->lockForUpdate()
                    ->first();

                if ($purchase === null) {
                    throw new InvalidArgumentException('فاتورة الشراء لا تتبع هذه الصيدلية');
                }

                if ((int) $purchase->supplier_id !== (int) $supplier->id) {
                    throw new InvalidArgumentException('فاتورة الشراء لا تتبع هذا المورّد');
                }

                if ($purchase->isCancelled()) {
                    throw new InvalidArgumentException('لا يمكن تسجيل دفعة على فاتورة شراء ملغاة');
                }
            }

            $payment = SupplierPayment::create([
                'pharmacy_id' => $pharmacyId,
                'supplier_id' => $supplier->id,
                'purchase_id' => $purchase?->id,
                'amount' => $amount,
                'payment_method' => $method,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'paid_at' => $date,
                'created_by' => $userId,
            ]);

            if (static::isCashTender($method)) {
                static::recordCash(
                    $pharmacyId,
                    CashMovement::DIRECTION_OUT,
                    $amount,
                    CashMovement::SOURCE_SUPPLIER_PAYMENT,
                    $payment->id,
                    'دفعة إلى '.$supplier->name,
                    $date,
                    $userId
                );
            }

            static::adjustSupplierBalance($supplier, -1 * $amount);

            if ($purchase !== null) {
                $newPaid = round((float) $purchase->paid + $amount, 2);

                // لا تتجاوز الفاتورة إجماليها: دفعة زائدة تبقى في رصيد المورد
                // (سلفة) لكنها لا تجعل الفاتورة «مدفوعة أكثر من قيمتها».
                if ($newPaid > (float) $purchase->total) {
                    $newPaid = (float) $purchase->total;
                }

                $purchase->forceFill([
                    'paid' => $newPaid,
                    'remaining' => round((float) $purchase->total - $newPaid, 2),
                    'status' => Purchase::deriveStatus((float) $purchase->total, $newPaid),
                ])->save();
            }

            return $payment;
        });
    }

    /**
     * إنشاء فاتورة شراء: فاتورة + أسطر + **زيادة** المخزون + صندوق + رصيد مورد.
     *
     * ⚠️ عكس `recordSale` في اتجاهين:
     *   - المخزون **يزيد** (لا يُخصم)، ولا فشل إذا كانت الكمية الحالية صفرًا.
     *   - الصندوق يخرج منه النقد المدفوع (`out`) لا يدخله.
     *
     * التكرار (idempotency): لا يوجد مفتاح تكرار هنا. منع الازدواج يقع على
     * العميل (زر واحد) وعلى قيد `unique(pharmacy_id, number)` — إرسال نفس
     * الرقم مرتين يفشل بدل مضاعفة المخزون.
     *
     * @param  array{
     *     supplier_id?: int|null, number?: string, discount?: float,
     *     payment_method?: string, paid?: float|null, notes?: string|null,
     *     purchased_at?: \DateTimeInterface|string|null,
     *     items: list<array{
     *         pharmacy_medicine_id?: int|null, medicine_id?: int|null,
     *         moh_medicine_id?: int|null, medicine_name: string,
     *         barcode?: string|null, unit_cost: float, quantity: int,
     *         line_discount?: float
     *     }>
     * }  $data
     *
     * @throws InvalidArgumentException
     */
    public static function recordPurchase(int $pharmacyId, int $userId, array $data): Purchase
    {
        $items = $data['items'] ?? [];

        if (count($items) === 0) {
            throw new InvalidArgumentException('فاتورة الشراء يجب أن تحتوي على صنف واحد على الأقل');
        }

        return DB::transaction(function () use ($pharmacyId, $userId, $data, $items) {
            $purchasedAt = isset($data['purchased_at'])
                ? Carbon::parse($data['purchased_at'])
                : Carbon::now();

            // ── 1) المجاميع قبل أي كتابة ─────────────────────────────────
            $subtotal = 0.0;
            $prepared = [];

            foreach ($items as $row) {
                $quantity = (int) ($row['quantity'] ?? 0);
                if ($quantity < 1) {
                    throw new InvalidArgumentException('كمية الصنف يجب أن تكون 1 على الأقل');
                }

                $unitCost = (float) ($row['unit_cost'] ?? 0);
                if ($unitCost < 0) {
                    throw new InvalidArgumentException('تكلفة الوحدة لا يمكن أن تكون سالبة');
                }

                $lineDiscount = (float) ($row['line_discount'] ?? 0);
                if ($lineDiscount < 0) {
                    throw new InvalidArgumentException('خصم السطر لا يمكن أن يكون سالبًا');
                }

                $lineTotal = PurchaseItem::computeLineTotal($unitCost, $quantity, $lineDiscount);
                if ($lineTotal < 0) {
                    throw new InvalidArgumentException('إجمالي السطر لا يمكن أن يكون سالبًا');
                }

                $subtotal += $lineTotal;
                $prepared[] = [
                    'row' => $row,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'line_discount' => $lineDiscount,
                    'line_total' => $lineTotal,
                ];
            }

            $subtotal = round($subtotal, 2);
            $invoiceDiscount = round((float) ($data['discount'] ?? 0), 2);
            $total = round($subtotal - $invoiceDiscount, 2);

            if ($total < 0) {
                throw new InvalidArgumentException('إجمالي الفاتورة لا يمكن أن يكون سالبًا');
            }

            $method = (string) ($data['payment_method'] ?? 'credit');

            // الافتراضي للشراء **آجل** (credit) — عكس البيع النقدي.
            $paid = array_key_exists('paid', $data) && $data['paid'] !== null
                ? round((float) $data['paid'], 2)
                : ($method === 'credit' ? 0.0 : $total);

            if ($paid > $total) {
                $paid = $total;
            }

            $remaining = round($total - $paid, 2);

            // ── 2) المورد (إن أُرسل) يجب أن يخصّ هذه الصيدلية ──────────────
            $supplier = null;
            if (! empty($data['supplier_id'])) {
                $supplier = Supplier::forPharmacy($pharmacyId)->find($data['supplier_id']);

                if ($supplier === null) {
                    throw new InvalidArgumentException('المورد لا يتبع هذه الصيدلية');
                }
            }

            // ── 3) الفاتورة ──────────────────────────────────────────────
            $purchase = Purchase::create([
                'pharmacy_id' => $pharmacyId,
                'supplier_id' => $supplier?->id,
                'supplier_name' => $supplier?->name ?? ($data['supplier_name'] ?? null),
                'number' => $data['number'] ?? static::nextPurchaseNumber($pharmacyId),
                'subtotal' => $subtotal,
                'discount' => $invoiceDiscount,
                'total' => $total,
                'paid' => $paid,
                'remaining' => $remaining,
                'payment_method' => $method,
                'status' => Purchase::deriveStatus($total, $paid),
                'notes' => $data['notes'] ?? null,
                'purchased_at' => $purchasedAt,
                'created_by' => $userId,
            ]);

            // ── 4) الأسطر + زيادة المخزون ─────────────────────────────────
            foreach ($prepared as $p) {
                $row = $p['row'];
                $pmId = $row['pharmacy_medicine_id'] ?? null;

                $purchase->items()->create([
                    'pharmacy_medicine_id' => $pmId,
                    'medicine_id' => $row['medicine_id'] ?? null,
                    'moh_medicine_id' => $row['moh_medicine_id'] ?? null,
                    'medicine_name' => (string) $row['medicine_name'],
                    'barcode' => $row['barcode'] ?? null,
                    'unit_cost' => $p['unit_cost'],
                    'quantity' => $p['quantity'],
                    'line_discount' => $p['line_discount'],
                    'line_total' => $p['line_total'],
                ]);

                if ($pmId === null) {
                    continue; // سطر بلا ربط مخزون (يُربط لاحقًا)
                }

                // زيادة ذرّية مشروطة بالصيدلية — تمنع الكتابة على مخزون غيرك.
                $affected = DB::table('pharmacy_medicines')
                    ->where('id', (int) $pmId)
                    ->where('pharmacy_id', $pharmacyId)
                    ->increment('quantity', $p['quantity']);

                if ($affected === 0) {
                    throw new InvalidArgumentException(
                        'الصنف لا يتبع مخزون هذه الصيدلية: '.$row['medicine_name']
                    );
                }
            }

            // ── 5) الصندوق: النقد المدفوع يخرج فقط ────────────────────────
            if ($paid > 0 && static::isCashTender($method)) {
                static::recordCash(
                    $pharmacyId,
                    CashMovement::DIRECTION_OUT,
                    $paid,
                    CashMovement::SOURCE_PURCHASE,
                    $purchase->id,
                    'فاتورة شراء '.$purchase->number,
                    $purchasedAt,
                    $userId
                );
            }

            // ── 6) رصيد المورد: المتبقي فقط يزيد ما علينا ─────────────────
            if ($supplier !== null && $remaining > 0) {
                static::adjustSupplierBalance($supplier, $remaining);
            }

            return $purchase->load('items');
        });
    }

    /**
     * إلغاء فاتورة شراء: عكس المخزون + الصندوق + رصيد المورد.
     *
     * ⚠️ **لا يُسمح بالإلغاء لو استُهلك المخزون.** عكس شراء 10 علب والمخزون
     * الحالي 3 يجعل الرصيد سالبًا كذبًا. نرفض برسالة واضحة بدل كتابة واقع
     * غير موجود — الإرجاع للمورد عملية أخرى (لم تُنفَّذ بعد).
     *
     * @throws InvalidArgumentException
     */
    public static function cancelPurchase(Purchase $purchase, int $userId): Purchase
    {
        if ($purchase->isCancelled()) {
            throw new InvalidArgumentException('فاتورة الشراء ملغاة مسبقًا');
        }

        return DB::transaction(function () use ($purchase, $userId) {
            $locked = Purchase::whereKey($purchase->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new InvalidArgumentException('فاتورة الشراء غير موجودة');
            }

            // إعادة الفحص تحت القفل — طلبان متزامنان يجب ألا يعكسا مرتين.
            if ($locked->isCancelled()) {
                throw new InvalidArgumentException('فاتورة الشراء ملغاة مسبقًا');
            }

            foreach ($locked->items()->get() as $item) {
                $pmId = $item->pharmacy_medicine_id;
                $qty = (int) $item->quantity;

                if ($pmId === null || $qty <= 0) {
                    continue;
                }

                $affected = DB::table('pharmacy_medicines')
                    ->where('id', (int) $pmId)
                    ->where('pharmacy_id', $locked->pharmacy_id)
                    ->where('quantity', '>=', $qty)
                    ->decrement('quantity', $qty);

                if ($affected === 0) {
                    throw new InvalidArgumentException(
                        'لا يمكن إلغاء فاتورة استُهلك مخزونها — الكمية الحالية أقل من كمية الشراء'
                    );
                }
            }

            static::reverseCashFor(
                $locked->pharmacy_id,
                CashMovement::SOURCE_PURCHASE,
                $locked->id,
                $userId,
                'إلغاء فاتورة شراء '.$locked->number
            );

            // رصيد المورد: نُسقط **المتبقي** فقط. المدفوع خرج نقدًا فعلًا ولا
            // يجوز «إعادته» بقيد عكسي بلا استرداد حقيقي.
            if ($locked->supplier_id !== null && (float) $locked->remaining > 0) {
                $supplier = Supplier::forPharmacy($locked->pharmacy_id)->find($locked->supplier_id);

                if ($supplier !== null) {
                    static::adjustSupplierBalance($supplier, -1 * (float) $locked->remaining);
                }
            }

            $locked->forceFill([
                'is_cancelled' => true,
                'cancelled_at' => Carbon::now(),
                'cancelled_by' => $userId,
                'status' => Purchase::STATUS_CANCELLED,
            ])->save();

            return $locked->load('items');
        });
    }

    /**
     * سحب/إيداع نقدي يدوي (adjustment). `reason` إلزامي منطقيًا لأنه تعديل
     * يدوي على الصندوق بلا مستند.
     */
    public static function recordCashAdjustment(int $pharmacyId, int $userId, string $direction, float $amount, string $reason, ?Carbon $date = null): CashMovement
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('المبلغ يجب أن يكون أكبر من صفر');
        }

        if (! in_array($direction, [CashMovement::DIRECTION_IN, CashMovement::DIRECTION_OUT], true)) {
            throw new InvalidArgumentException('اتجاه الحركة غير صالح');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('سبب التعديل اليدوي مطلوب');
        }

        $source = $direction === CashMovement::DIRECTION_IN
            ? CashMovement::SOURCE_DEPOSIT
            : CashMovement::SOURCE_WITHDRAWAL;

        return static::recordCash(
            $pharmacyId,
            $direction,
            $amount,
            $source,
            null,
            null,
            $date ?? Carbon::now(),
            $userId,
            $reason
        );
    }

    /* ════════════════════════════════════════════════════════════════════
       أدوات داخلية
       ════════════════════════════════════════════════════════════════════ */

    /**
     * هل طريقة الدفع تمرّ بالصندوق النقدي؟
     * البطاقة/التحويل لا تزيد النقد الفعلي في الدرج — إدخالها في الصندوق
     * يجعل الجرد النقدي مخالفًا للواقع، وهو خطأ شائع في أنظمة المحاسبة.
     */
    public static function isCashTender(string $method): bool
    {
        return $method === Sale::METHOD_CASH;
    }

    /**
     * خصم مخزون **ذرّي بشرط**.
     *
     * استعلام واحد يخصم فقط لو الكمية كافية، ثم نفحص عدد الصفوف. هذا يحمي من
     * سباق التزامن بلا قفل صريح: لو تنافس كاشيران، الثاني يجد الكمية ناقصة
     * ويفشل بدل أن يبيع بضاعة غير موجودة.
     *
     * @throws InvalidArgumentException لو الكمية غير كافية
     */
    private static function decrementStockOrFail(int $pharmacyId, int $pharmacyMedicineId, int $quantity): void
    {
        $affected = DB::table('pharmacy_medicines')
            ->where('id', $pharmacyMedicineId)
            ->where('pharmacy_id', $pharmacyId)
            ->where('quantity', '>=', $quantity)
            ->decrement('quantity', $quantity);

        if ($affected === 0) {
            $name = DB::table('pharmacy_medicines')
                ->join('medicines', 'medicines.id', '=', 'pharmacy_medicines.medicine_id')
                ->where('pharmacy_medicines.id', $pharmacyMedicineId)
                ->value('medicines.trade_name');

            throw new InvalidArgumentException(
                'الكمية غير كافية في المخزون'.($name ? ' للدواء: '.$name : '')
            );
        }
    }

    /**
     * إضافة حركة صندوق.
     *
     * ⚠️ `public` لا `private`: `RefundService` يستدعيها لتسجيل حركة
     * الإرجاع (`out`). إبقاؤها خاصة يكسر الإرجاع بـ
     * «Call to private method … from scope RefundService».
     * هذا هو نفس التعديل المقصود في `0cca38d` — أُعيد بعد أن ضاع عند
     * استعادة الملف من نسخة أحدث.
     */
    public static function recordCash(
        int $pharmacyId,
        string $direction,
        float $amount,
        string $sourceType,
        ?int $sourceId,
        ?string $description,
        Carbon $movedAt,
        int $userId,
        ?string $reason = null
    ): CashMovement {
        return CashMovement::create([
            'pharmacy_id' => $pharmacyId,
            'direction' => $direction,
            'amount' => $amount,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'description' => $description,
            'reason' => $reason,
            'created_by' => $userId,
            'moved_at' => $movedAt,
        ]);
    }

    /**
     * عكس حركة/حركات مصدر معيّن داخل الصندوق.
     *
     * يُستخدم عند الإلغاء: نفس المنطق لكل المصادر، فبدل تكراره في كل مسار
     * نقرأ الحركة الأصلية ونُنشئ معاكسها بنفس المبلغ.
     */
    private static function reverseCashFor(int $pharmacyId, string $sourceType, int $sourceId, int $userId, string $reason): void
    {
        $movements = CashMovement::forPharmacy($pharmacyId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->get();

        foreach ($movements as $movement) {
            $opposite = $movement->direction === CashMovement::DIRECTION_IN
                ? CashMovement::DIRECTION_OUT
                : CashMovement::DIRECTION_IN;

            static::recordCash(
                $pharmacyId,
                $opposite,
                (float) $movement->amount,
                CashMovement::SOURCE_ADJUSTMENT,
                null,
                $reason,
                Carbon::now(),
                $userId,
                $reason
            );
        }
    }

    /**
     * تعديل رصيد العميل — يمرّ من هنا فقط ليبقى متسقًا مع الدفتر.
     *
     * ⚠️ `public` لا `private`: `RefundService` يستدعيها لتخفيض رصيد
     * العميل عند إرجاع بيع آجل. نفس مبرّر `recordCash` أعلاه.
     */
    public static function adjustCustomerBalance(Customer $customer, float $delta): void
    {
        DB::table('customers')
            ->where('id', $customer->id)
            ->update(['current_balance' => DB::raw('current_balance + '.(float) $delta)]);
    }

    private static function adjustSupplierBalance(Supplier $supplier, float $delta): void
    {
        DB::table('suppliers')
            ->where('id', $supplier->id)
            ->update(['current_balance' => DB::raw('current_balance + '.(float) $delta)]);
    }

    /**
     * الرقم التالي للفاتورة — بتسلسل داخل الصيدلية.
     *
     * ⚠️ مقصود عدم استخدام عدد الصفوف كرقم: لو أُلغيت/حُذفت فاتورة يتكرر الرقم.
     * نأخذ أعلى رقم فعلي ونتقدّم منه. الـunique `(pharmacy_id, number)` هو
     * الحماية الأخيرة لو تنافس طلبان في نفس اللحظة (يفشل الثاني ويعيد المحاولة).
     */
    public static function nextSaleNumber(int $pharmacyId): string
    {
        $last = Sale::forPharmacy($pharmacyId)
            ->where('number', 'like', 'INV-%')
            ->orderByDesc('id')
            ->value('number');

        $seq = 1001;
        if (is_string($last) && preg_match('/(\d+)$/', $last, $m) === 1) {
            $seq = ((int) $m[1]) + 1;
        }

        return 'INV-'.$seq;
    }

    /** نفس المنطق للمشتريات. */
    public static function nextPurchaseNumber(int $pharmacyId): string
    {
        $last = Purchase::forPharmacy($pharmacyId)
            ->where('number', 'like', 'PUR-%')
            ->orderByDesc('id')
            ->value('number');

        $seq = 1;
        if (is_string($last) && preg_match('/(\d+)$/', $last, $m) === 1) {
            $seq = ((int) $m[1]) + 1;
        }

        return 'PUR-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
