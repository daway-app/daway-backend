<?php

namespace App\Services\AccountClosure;

use App\Models\AccountClosureRequest;
use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\SubOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * إغلاق حسابات المرضى والصيدليات بأمان.
 *
 * المبادئ المطبقة:
 *  · **لا حذف** لأي سجل مالي. الإغلاق يوقف الوصول ويُخفي الهوية فقط.
 *  · `user_id` يبقى في الطلبات والسجلات المحاسبية (D-1) — الحذف يكسر التقارير.
 *  · إخفاء الهوية يشمل كل مكان تُخزَّن فيه بيانات شخصية، لا `users.name` فقط:
 *    العناوين، ملاحظات الطلب، اسم مُرسل إثبات الدفع.
 *  · إغلاق الصيدلية يحتاج موافقة أدمن (D-3)، ولا يكتمل قبل تسوية كل الالتزامات
 *    بما فيها المبالغ التي لم تُجمَّع بعد في دفعة (D-6).
 *  · سياسة الاحتفاظ منفصلة (config/retention.php) ولا تحذف شيئًا تلقائيًا (D-2).
 */
class AccountClosureService
{
    /** حالات الطلب/الطلب الفرعي التي لا تُعدّ «نشطة». */
    private const CLOSED_STATUSES = ['delivered', 'cancelled', 'rejected', 'refunded'];

    // ------------------------------------------------------------------
    // المريض
    // ------------------------------------------------------------------

    /**
     * ما يمنع الإغلاق الفوري لحساب مريض.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    public function patientBlockers(User $user): array
    {
        $blockers = [];

        $activeOrders = Order::query()
            ->where('user_id', $user->id)
            ->whereNotIn('status', self::CLOSED_STATUSES)
            ->count();

        if ($activeOrders > 0) {
            $blockers[] = ['key' => 'active_orders', 'label' => 'طلبات نشطة', 'count' => $activeOrders];
        }

        if (Schema::hasTable('order_payment_submissions')) {
            $pendingPayments = DB::table('order_payment_submissions as s')
                ->join('orders as o', 'o.id', '=', 's.order_id')
                ->where('o.user_id', $user->id)
                ->whereIn('s.status', ['pending', 'under_review'])
                ->count();

            if ($pendingPayments > 0) {
                $blockers[] = ['key' => 'pending_payments', 'label' => 'إثباتات دفع قيد المراجعة', 'count' => $pendingPayments];
            }
        }

        if (Schema::hasTable('payment_problems')) {
            $openProblems = DB::table('payment_problems as problem')
                ->join('orders as o', 'o.id', '=', 'problem.order_id')
                ->where('o.user_id', $user->id)
                ->where('problem.status', \App\Models\PaymentProblem::STATUS_OPEN)
                ->count();

            if ($openProblems > 0) {
                $blockers[] = ['key' => 'open_payment_problems', 'label' => 'مشكلات دفع قيد المراجعة', 'count' => $openProblems];
            }
        }

        return $blockers;
    }

    /**
     * إغلاق فوري لحساب مريض (D-3) بعد التحقق من الهوية في المتحكم.
     *
     * @throws RuntimeException عند وجود مانع
     */
    public function closePatient(User $user, ?string $reason = null): AccountClosureRequest
    {
        $blockers = $this->patientBlockers($user);

        if ($blockers !== []) {
            throw new AccountClosureBlocked($blockers);
        }

        return DB::transaction(function () use ($user, $reason) {
            // Remove outstanding login codes before the phone number is anonymized.
            if ($user->phone !== null) {
                DB::table('otp_codes')->where('phone', $user->phone)->delete();
            }
            $this->purgePersonalData($user);
            $this->anonymizePersonalData($user);

            $user->forceFill([
                'is_active' => false,
                'closed_at' => now(),
            ])->save();

            // إبطال كل الجلسات: التوكن الحالي وغيره.
            $user->tokens()->delete();

            return AccountClosureRequest::create([
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'status' => AccountClosureRequest::STATUS_APPROVED,
                'requested_by_type' => 'owner',
                'requested_by' => $user->id,
                'reason' => $reason,
                'blockers_at_request' => [],
                'reviewed_at' => now(),
            ]);
        });
    }

    /**
     * حذف البيانات الشخصية البحتة — صراحةً لا بالـcascade، ليبقى السلوك مرئيًا.
     */
    private function purgePersonalData(User $user): void
    {
        foreach ((array) config('retention.patient_purge_tables', []) as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) {
                DB::table($table)->where('user_id', $user->id)->delete();
            }
        }
    }

    /**
     * إخفاء الهوية في كل مكان تُخزَّن فيه (D-1) — لا الاسم وحده.
     */
    private function anonymizePersonalData(User $user): void
    {
        $placeholder = 'حساب محذوف #'.$user->id;

        $user->forceFill([
            'name' => $placeholder,
            'email' => 'deleted+'.$user->id.'@daway.invalid',
            'phone' => null,
            'avatar' => null,
            'address' => null,
            'birth_date' => null,
            'emergency_contact' => null,
            'latitude' => null,
            'longitude' => null,
            'email_verified_at' => null,
            'phone_verified_at' => null,
            'remember_token' => null,
            // كلمة مرور عشوائية: حتى لو أُعيد تفعيل الحساب لا يمكن الدخول بها.
            'password' => bcrypt(bin2hex(random_bytes(24))),
        ])->save();

        // العناوين: تُخفى في مكانها ولا تُحذف — `orders.address_id` cascade،
        // فحذف عنوان مرتبط بطلب **يمحو الطلب نفسه**.
        if (Schema::hasTable('addresses')) {
            DB::table('addresses')->where('user_id', $user->id)->update([
                'recipient_name' => $placeholder,
                'phone' => null,
                'address' => '—',
                'latitude' => null,
                'longitude' => null,
                'updated_at' => now(),
            ]);
        }

        // ملاحظات حرة قد تحتوي بيانات شخصية.
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'notes')) {
            DB::table('orders')->where('user_id', $user->id)->update(['notes' => null]);
        }

        if (Schema::hasTable('sub_orders') && Schema::hasColumn('sub_orders', 'notes')) {
            DB::table('sub_orders')
                ->whereIn('order_id', DB::table('orders')->where('user_id', $user->id)->select('id'))
                ->update(['notes' => null]);
        }

        // اسم مُرسل إثبات الدفع — بيانات شخصية داخل سجل مالي.
        if (Schema::hasTable('order_payment_submissions') && Schema::hasColumn('order_payment_submissions', 'sender_name')) {
            DB::table('order_payment_submissions')
                ->whereIn('order_id', DB::table('orders')->where('user_id', $user->id)->select('id'))
                ->update(['sender_name' => null]);
        }
    }

    // ------------------------------------------------------------------
    // الصيدلية
    // ------------------------------------------------------------------

    /**
     * ما يمنع **إتمام** الإغلاق. الطلب نفسه مسموح دائمًا (D-6).
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    public function pharmacyBlockers(Pharmacy $pharmacy): array
    {
        $blockers = [];

        $activeSubOrders = SubOrder::query()
            ->where('pharmacy_id', $pharmacy->id)
            ->whereNotIn('status', self::CLOSED_STATUSES)
            ->count();

        if ($activeSubOrders > 0) {
            $blockers[] = ['key' => 'active_sub_orders', 'label' => 'طلبات قيد التنفيذ', 'count' => $activeSubOrders];
        }

        if (Schema::hasTable('platform_settlements')) {
            $draftSettlements = DB::table('platform_settlements')
                ->where('pharmacy_id', $pharmacy->id)
                ->where('status', 'draft')
                ->where('net_total', '>', 0)
                ->count();

            if ($draftSettlements > 0) {
                $blockers[] = ['key' => 'unpaid_settlements', 'label' => 'تسويات غير مدفوعة', 'count' => $draftSettlements];
            }
        }

        // D-6 صراحةً: مبالغ لم تُجمَّع بعد في أي دفعة تسوية.
        if (Schema::hasTable('platform_settlement_items')) {
            $ungrouped = SubOrder::query()
                ->where('pharmacy_id', $pharmacy->id)
                ->where('status', SubOrder::STATUS_DELIVERED)
                // An unpaid delivered order can become a payable while payment approval is in flight.
                ->whereNotIn('id', DB::table('platform_settlement_items')->whereNotNull('sub_order_id')->select('sub_order_id'))
                ->count();

            if ($ungrouped > 0) {
                $blockers[] = ['key' => 'ungrouped_dues', 'label' => 'مستحقات لم تُجمَّع في تسوية', 'count' => $ungrouped];
            }
        }

        // Unbatched settlement adjustments remain financial obligations even without an order.
        if (Schema::hasTable('platform_settlement_adjustments')) {
            $unbatchedAdjustments = DB::table('platform_settlement_adjustments')
                ->where('pharmacy_id', $pharmacy->id)->whereNull('settlement_id')->count();
            if ($unbatchedAdjustments > 0) {
                $blockers[] = ['key' => 'unbatched_adjustments', 'label' => 'تعديلات تسوية غير مجمعة', 'count' => $unbatchedAdjustments];
            }
        }

        if (Schema::hasTable('refunds')) {
            $pendingRefunds = DB::table('refunds')
                ->where('pharmacy_id', $pharmacy->id)
                ->whereIn('status', ['pending', 'approved'])
                ->count();

            if ($pendingRefunds > 0) {
                $blockers[] = ['key' => 'pending_refunds', 'label' => 'مرتجعات معلّقة', 'count' => $pendingRefunds];
            }
        }

        return $blockers;
    }

    public function requestPharmacyClosure(Pharmacy $pharmacy, User $actor, ?string $reason = null): AccountClosureRequest
    {
        if ($pharmacy->closed_at !== null) {
            throw new RuntimeException('الحساب مغلق بالفعل.');
        }

        $existing = AccountClosureRequest::query()
            ->where('subject_type', Pharmacy::class)
            ->where('subject_id', $pharmacy->id)
            ->where('status', AccountClosureRequest::STATUS_PENDING)
            ->first();

        if ($existing !== null) {
            return $existing; // طلب مكرر: نُعيد نفسه بدل إنشاء ثانٍ.
        }

        return AccountClosureRequest::create([
            'subject_type' => Pharmacy::class,
            'subject_id' => $pharmacy->id,
            'status' => AccountClosureRequest::STATUS_PENDING,
            'requested_by_type' => 'owner',
            'requested_by' => $actor->id,
            'reason' => $reason,
            // صورة الموانع وقت الطلب — تُعرض للأدمن ولا تمنع الإرسال.
            'blockers_at_request' => $this->pharmacyBlockers($pharmacy),
        ]);
    }

    /**
     * @throws AccountClosureBlocked
     */
    public function approvePharmacyClosure(AccountClosureRequest $request, User $admin, ?string $note = null): void
    {
        DB::transaction(function () use ($request, $admin, $note) {
            // Checkout locks this same row before creating a sub-order.
            $pharmacy = Pharmacy::query()->whereKey($request->subject_id)->lockForUpdate()->firstOrFail();
            $request = AccountClosureRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! $request->isPending() || $pharmacy->closed_at !== null) {
                throw new RuntimeException('الطلب ليس قيد المراجعة.');
            }
            $blockers = $this->pharmacyBlockers($pharmacy);
            if ($blockers !== []) {
                throw new AccountClosureBlocked($blockers);
            }

            $pharmacy->forceFill(['is_active' => false, 'closed_at' => now()])->save();

            // The schema associates one owner through pharmacies.user_id.
            $owner = User::query()->whereKey($pharmacy->user_id)->lockForUpdate()->firstOrFail();
            $ownerWasActive = (bool) $owner->is_active && $owner->closed_at === null;
            if ($ownerWasActive) {
                $owner->forceFill(['is_active' => false, 'closed_at' => now()])->save();
            }
            $owner->tokens()->delete();

            $request->forceFill([
                'owner_was_active' => $ownerWasActive,
                'closed_owner_id' => $owner->id,
                'status' => AccountClosureRequest::STATUS_APPROVED,
                'review_note' => $note,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();
        });
    }

    public function rejectPharmacyClosure(AccountClosureRequest $request, User $admin, string $note): void
    {
        DB::transaction(function () use ($request, $admin, $note) {
            Pharmacy::query()->whereKey($request->subject_id)->lockForUpdate()->firstOrFail();
            $request = AccountClosureRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! $request->isPending()) {
                throw new RuntimeException('الطلب ليس قيد المراجعة.');
            }
            $request->forceFill([
                'status' => AccountClosureRequest::STATUS_REJECTED,
                'review_note' => $note,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();
        });
    }

    /** إعادة فتح صيدلية (الأدمن) — الإغلاق قابل للتراجع. */
    public function reopenPharmacy(Pharmacy $pharmacy): void
    {
        DB::transaction(function () use ($pharmacy) {
            $pharmacy = Pharmacy::query()->whereKey($pharmacy->id)->lockForUpdate()->firstOrFail();
            if ($pharmacy->closed_at === null) {
                throw new RuntimeException('الصيدلية غير مغلقة.');
            }
            $pharmacy->forceFill(['is_active' => true, 'closed_at' => null])->save();

            $closure = AccountClosureRequest::query()
                ->where('subject_type', Pharmacy::class)
                ->where('subject_id', $pharmacy->id)
                ->where('status', AccountClosureRequest::STATUS_APPROVED)
                ->latest('id')->first();

            // A legacy closure without a recorded prior state fails closed.
            if ($closure?->owner_was_active === true && (int) $closure->closed_owner_id === (int) $pharmacy->user_id) {
                User::query()->whereKey($pharmacy->user_id)
                    ->whereNotNull('closed_at')
                    ->update(['is_active' => true, 'closed_at' => null]);
            }
        });
    }
}
