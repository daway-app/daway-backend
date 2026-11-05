<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * حارس حذف حساب المستخدم.
 *
 * سبب الوجود: `Admin\UserController::destroy` كان يحذف المستخدم بلا أي فحص
 * بعد التحقق من أنه ليس حسابه الشخصي. وفي المخطط:
 *
 *   users ──cascade──► orders ──cascade──► order_payment_submissions
 *   users ──cascade──► pharmacies ──cascade──► sub_orders / order_items /
 *                     sales / purchases / refunds / accounting_ledger …
 *
 * أي أن حذف مستخدم واحد قد يمحو طلباته، أو يمحو **تاريخ صيدلية كاملًا**
 * إن كان مالكها. هذا الحارس يمنع ذلك ويعيد الحل الصحيح: التعطيل
 * (`is_active = false`) لا المحو.
 *
 * العلاقة الحميمة الأخرى (favorites, addresses, medical_profiles, device_tokens,
 * reminders, notifications) تُحذف بالـcascade وهي بيانات شخصية بحتة — لا تُحجب
 * الحذف، لأن بقاءها ليس مطلوبًا لأي سجل مالي.
 */
class UserDeletionGuard
{
    public function __construct(private readonly PharmacyDeletionGuard $pharmacies) {}

    /**
     * السجلات التي تمنع الحذف النهائي.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    public function blockers(User $user): array
    {
        $blockers = [];

        // 1) طلبات المريض — تُحذف بالـcascade مع المستخدم.
        if (Schema::hasTable('orders')) {
            $orders = DB::table('orders')->where('user_id', $user->id)->count();

            if ($orders > 0) {
                $blockers[] = ['key' => 'orders', 'label' => 'طلبات العميل', 'count' => $orders];
            }
        }

        // 2) صيدلية يملكها — حذفها يجرّ معه تاريخها المالي والطلبات.
        //    لكن مالك صيدلية **فارغة** يُحذف بلا مانع، تمامًا كسياسة
        //    PharmacyDeletionGuard: الحذف مسموح فقط لما لا سجل له.
        $pharmacy = Pharmacy::where('user_id', $user->id)->first();

        if ($pharmacy instanceof Pharmacy) {
            $pharmacyBlockers = $this->pharmacies->blockers($pharmacy);

            if ($pharmacyBlockers !== []) {
                $blockers[] = [
                    'key' => 'pharmacy',
                    'label' => 'صيدلية مملوكة لهذا الحساب',
                    'count' => 1,
                ];

                // نفس قائمة الحارس الموجود — لا نكرّر منطق الحماية.
                foreach ($pharmacyBlockers as $blocker) {
                    $blockers[] = $blocker;
                }
            }
        }

        return $blockers;
    }

    public function canDelete(User $user): bool
    {
        return $this->blockers($user) === [];
    }
}
