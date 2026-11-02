<?php

namespace App\Services;

use App\Models\Pharmacy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * حارس حذف الصيدلية.
 *
 * سبب الوجود: `pharmacies` مرتبطة بـ98 علاقة `cascadeOnDelete`، منها دفاتر
 * المحاسبة (customers, suppliers, sales, purchases, expenses, cash_movements,
 * customer_payments, supplier_payments) و`sub_orders`. حذف صيدلية واحدة كان
 * يمحو تاريخها المالي والطلبات نهائيًا بلا أي اعتراض.
 *
 * القاعدة: الحذف مسموح فقط لصيدلية لا تملك أي سجل مالي أو طلب. وجود أي سجل
 * يعني أن الحذف مرفوض، والحل الصحيح هو تعطيل الحساب (`is_active = false`)
 * لا محو البيانات. سياسة الاحتفاظ الكاملة (Soft Delete / أرشفة) لم تُعتمد بعد.
 *
 * الجداول الابنة (sale_items, purchase_items, platform_settlement_items,
 * order_payment_submissions) لا تُذكر هنا لأنها تتبع أباها وتُحذف معه، ووجود
 * الأب كافٍ للحكم.
 */
class PharmacyDeletionGuard
{
    /** @var array<string, string> الجدول => التسمية العربية المعروضة للأدمن */
    public const PROTECTED_TABLES = [
        'sub_orders' => 'طلبات الصيدلية',
        'orders' => 'طلبات',
        'sales' => 'مبيعات',
        'purchases' => 'مشتريات',
        'expenses' => 'مصروفات',
        'cash_movements' => 'حركات الصندوق',
        'customer_payments' => 'مدفوعات عملاء',
        'supplier_payments' => 'مدفوعات موردين',
        'customers' => 'عملاء',
        'suppliers' => 'موردون',
        'refunds' => 'مرتجعات',
        'platform_settlements' => 'تسويات المنصة',
        'platform_settlement_adjustments' => 'تعديلات التسويات',
        'scan_sessions' => 'جلسات فحص',
        'medicine_requests' => 'طلبات أدوية',
    ];

    /**
     * السجلات التي تمنع الحذف، مع عددها.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    public function blockers(Pharmacy $pharmacy): array
    {
        $blockers = [];

        foreach (self::PROTECTED_TABLES as $table => $label) {
            // قواعد بيانات أقدم قد لا تحتوي كل الجداول — لا نُسقط الطلب لهذا.
            if (! Schema::hasTable($table)) {
                continue;
            }

            $count = (int) DB::table($table)->where('pharmacy_id', $pharmacy->id)->count();

            if ($count > 0) {
                $blockers[] = ['key' => $table, 'label' => $label, 'count' => $count];
            }
        }

        return $blockers;
    }

    public function canDelete(Pharmacy $pharmacy): bool
    {
        return $this->blockers($pharmacy) === [];
    }
}
