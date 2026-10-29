<?php

namespace App\Services;

use App\Models\Order;
use App\Models\SubOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Rider Delivery — منطق دورة حياة التوصيل على الطلب الأب.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * نموذج الملكية (قرارات معتمدة)
 * ════════════════════════════════════════════════════════════════════════════
 *  · الطلب الأب يُسند إلى **مندوب واحد**؛ الطلبات الفرعية لا تُسند منفردة.
 *  · الصيدلية تملك `pending..ready`؛ المندوب يملك `picked_up → out_for_delivery → delivered`.
 *  · `picked_up` حالة مستقلة عن `out_for_delivery`.
 *  · الاستلام يُتتبّع لكل `SubOrder`، والأب لا يصبح `picked_up` إلا بعد استلام
 *    كل الطلبات الفرعية **النشطة** (غير الملغاة).
 *  · إعادة الإسناد مسموحة حتى بدء الاستلام؛ فك الإسناد مسموح قبل بدء الاستلام فقط.
 *
 * كل كتابة داخل معاملة مع `lockForUpdate` على صف الطلب (وعلى الطلب الفرعي عند
 * الاستلام) — لمنع التسابق على نفس الصف. كل انتقال يُسجّل في
 * `order_status_histories` مع هوية المنفّذ عبر `addStatus(..., $actor)`.
 *
 * القيم المُعادة موحّدة: `['ok' => bool, 'status' => int, 'message' => string, 'data' => array]`
 * ليبنيها الكنترولر كـ JSON بأسلوب المشروع (`success/message/data`).
 */
class DeliveryLifecycleService
{
    public function __construct(private readonly PaymentFulfillmentGate $paymentGate) {}

    /**
     * هل بدأ المندوب الاستلام فعلياً؟ (يمنع إعادة الإسناد/الفك لاحقاً)
     */
    public function pickupStarted(Order $order): bool
    {
        if (in_array($order->status, Order::DELIVERY_PHASE, true)) {
            return true;
        }

        return $order->subOrders()->whereNotNull('picked_up_at')->exists();
    }

    /**
     * Rider Delivery — إسناد أو إعادة إسناد مندوب لطلب أب.
     */
    public function assignRider(Order $order, User $rider, User $actor): array
    {
        return DB::transaction(function () use ($order, $rider, $actor) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return $this->error(404, 'الطلب غير موجود.');
            }

            if ($rider->role !== 'rider' || ! $rider->is_active) {
                return $this->error(422, 'المندوب غير صالح أو غير مفعّل.');
            }

            if (in_array($locked->status, [Order::STATUS_DELIVERED, Order::STATUS_CANCELLED], true)) {
                return $this->error(422, 'لا يمكن إسناد طلب مكتمل أو ملغى.');
            }

            if ($this->pickupStarted($locked)) {
                return $this->error(422, 'بدأ الاستلام — لا يمكن إعادة الإسناد بعد بدء الاستلام.');
            }

            $denial = $this->paymentGate->denialFor($locked);

            if ($denial !== null) {
                return $denial;
            }

            $previousRiderId = $locked->rider_id;

            $locked->rider_id = $rider->id;
            $locked->assigned_by = $actor->id;
            $locked->assigned_at = now();
            $locked->save();

            return $this->ok('تم إسناد الطلب إلى المندوب بنجاح.', [
                'order_id' => $locked->id,
                'rider_id' => (int) $locked->rider_id,
                'previous_rider_id' => $previousRiderId !== null ? (int) $previousRiderId : null,
                'assigned_at' => $locked->assigned_at?->toDateTimeString(),
                'reassigned' => $previousRiderId !== null && (int) $previousRiderId !== (int) $rider->id,
            ]);
        });
    }

    /**
     * Rider Delivery — فك إسناد الطلب (قبل بدء الاستلام فقط).
     */
    public function unassignRider(Order $order, User $actor): array
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return $this->error(404, 'الطلب غير موجود.');
            }

            if (! $locked->rider_id) {
                return $this->error(422, 'الطلب غير مُسند إلى أي مندوب.');
            }

            if ($this->pickupStarted($locked)) {
                return $this->error(422, 'بدأ الاستلام — لا يمكن فك الإسناد بعد بدء الاستلام.');
            }

            $previousRiderId = (int) $locked->rider_id;
            $previousAssignedAt = $locked->assigned_at?->toDateTimeString();

            $locked->rider_id = null;
            $locked->assigned_by = null;
            $locked->assigned_at = null;
            $locked->save();

            return $this->ok('تم فك إسناد الطلب.', [
                'order_id' => $locked->id,
                'previous_rider_id' => $previousRiderId,
                'previous_assigned_at' => $previousAssignedAt,
            ]);
        });
    }

    /**
     * Rider Delivery — فك إسناد كل طلبات مندوب لم يبدأ استلامها.
     * يُستخدم عند تعطيل مندوب حتى لا يبقى محتجزاً طلبات نشطة.
     *
     * @return int عدد الطلبات المفكوكة
     */
    public function unassignAllForRider(User $rider, User $actor): int
    {
        return DB::transaction(function () use ($rider) {
            $orders = Order::where('rider_id', $rider->id)
                ->whereNotIn('status', [Order::STATUS_DELIVERED, Order::STATUS_CANCELLED])
                ->lockForUpdate()
                ->get();

            $count = 0;

            foreach ($orders as $order) {
                if ($this->pickupStarted($order)) {
                    continue;
                }

                $order->rider_id = null;
                $order->assigned_by = null;
                $order->assigned_at = null;
                $order->save();
                $count++;
            }

            return $count;
        });
    }

    /**
     * هل لدى المندوب طلب توصيل جارٍ (بدأ استلامه ولم يكتمل)؟
     */
    public function hasActiveDelivery(User $rider): bool
    {
        return Order::where('rider_id', $rider->id)
            ->whereIn('status', Order::DELIVERY_PHASE)
            ->exists();
    }

    /**
     * Rider Delivery — استلام طلب فرعي واحد (لكل صيدلية).
     * الأب يصبح `picked_up` فقط لحظة استلام آخر طلب فرعي نشط.
     */
    public function markPickup(Order $order, SubOrder $subOrder, User $rider): array
    {
        return DB::transaction(function () use ($order, $subOrder, $rider) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return $this->error(404, 'الطلب غير موجود.');
            }

            if ((int) $locked->rider_id !== (int) $rider->id) {
                return $this->error(403, 'هذا الطلب غير مُسند إليك.');
            }

            if (in_array($locked->status, [Order::STATUS_DELIVERED, Order::STATUS_CANCELLED], true)) {
                return $this->error(422, 'لا يمكن تعديل طلب مكتمل أو ملغى.');
            }

            $denial = $this->paymentGate->denialFor($locked);

            if ($denial !== null) {
                return $denial;
            }

            $lockedSub = SubOrder::whereKey($subOrder->getKey())->lockForUpdate()->first();

            if (! $lockedSub || (int) $lockedSub->order_id !== (int) $locked->id) {
                return $this->error(404, 'الطلب الفرعي غير موجود ضمن هذا الطلب.');
            }

            if ($lockedSub->status === SubOrder::STATUS_CANCELLED) {
                return $this->error(422, 'لا يمكن استلام طلب فرعي ملغى.');
            }

            if ($lockedSub->picked_up_at) {
                return $this->error(422, 'تم استلام هذا الطلب الفرعي مسبقاً.');
            }

            if ($lockedSub->status !== SubOrder::STATUS_READY) {
                return $this->error(422, 'لا يمكن استلام الطلب الفرعي قبل أن تجهّزه الصيدلية (ready).');
            }

            $lockedSub->picked_up_at = now();
            $lockedSub->save();

            // Timeline للطلب الفرعي — حدث في طبقة التوصيل، فحالة الطلب الفرعي
            // تبقى `ready` (لا يجوز استدعاء addStatus هنا لأنه يغيّر الحالة).
            $lockedSub->statusHistories()->create([
                'order_id' => $lockedSub->order_id,
                'status' => Order::STATUS_PICKED_UP,
                'note' => 'استلم المندوب الطلب الفرعي',
                'status_changed_at' => now(),
                'actor_user_id' => $rider->id,
                'actor_role' => $rider->role,
            ]);

            // هل اكتمل استلام كل الطلبات الفرعية النشطة؟
            $active = $locked->subOrders()->get()
                ->reject(fn (SubOrder $s) => $s->status === SubOrder::STATUS_CANCELLED);

            $allPicked = $active->isNotEmpty()
                && $active->every(fn (SubOrder $s) => $s->picked_up_at !== null);

            if ($allPicked && $locked->status === Order::STATUS_READY) {
                $locked->picked_up_at = now();
                $locked->save();
                $locked->addStatus(Order::STATUS_PICKED_UP, 'استلم المندوب جميع الطلبات الفرعية المطلوبة', $rider);
            }

            return $this->ok('تم تسجيل استلام الطلب الفرعي.', [
                'order_id' => $locked->id,
                'sub_order_id' => $lockedSub->id,
                'sub_order_picked_up_at' => $lockedSub->picked_up_at?->toDateTimeString(),
                'all_picked_up' => $allPicked,
                'order_status' => $locked->fresh()->status,
            ]);
        });
    }

    /**
     * Rider Delivery — الانطلاق نحو المريض (`picked_up → out_for_delivery`).
     */
    public function startDelivery(Order $order, User $rider): array
    {
        return DB::transaction(function () use ($order, $rider) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return $this->error(404, 'الطلب غير موجود.');
            }

            if ((int) $locked->rider_id !== (int) $rider->id) {
                return $this->error(403, 'هذا الطلب غير مُسند إليك.');
            }

            $denial = $this->paymentGate->denialFor($locked);

            if ($denial !== null) {
                return $denial;
            }

            // يجب أن يكون الأب قد استُلم بالكامل (`picked_up`) — لا يكفي
            // `canTransitionTo` لأنها تسمح `ready → out_for_delivery` لمسار
            // الصيدلية التاريخي، وهو غير صالح لتدفق المندوب.
            if ($locked->status !== Order::STATUS_PICKED_UP) {
                return $this->error(422, 'يجب استلام كل الطلبات الفرعية المطلوبة قبل بدء التوصيل.');
            }

            $locked->addStatus(Order::STATUS_OUT_FOR_DELIVERY, 'المندوب في الطريق إلى المريض', $rider);

            return $this->ok('تم بدء التوصيل.', [
                'order_id' => $locked->id,
                'order_status' => $locked->fresh()->status,
            ]);
        });
    }

    /**
     * Rider Delivery — التسليم للمريض (`picked_up|out_for_delivery → delivered`).
     * ممنوع قبل استيفاء الاستلام (تفرضه `canTransitionTo`).
     */
    public function markDelivered(Order $order, User $rider): array
    {
        return DB::transaction(function () use ($order, $rider) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return $this->error(404, 'الطلب غير موجود.');
            }

            if ((int) $locked->rider_id !== (int) $rider->id) {
                return $this->error(403, 'هذا الطلب غير مُسند إليك.');
            }

            $denial = $this->paymentGate->denialFor($locked);

            if ($denial !== null) {
                return $denial;
            }

            if (! $locked->canTransitionTo(Order::STATUS_DELIVERED)) {
                return $this->error(422, 'لا يمكن تسليم الطلب قبل استلامه من الصيدليات.');
            }

            // Records `delivered_at` on every still-active sub-order so the
            // per-pharmacy data stays consistent with the parent's delivery.
            foreach ($locked->subOrders()->lockForUpdate()->get() as $sub) {
                if (in_array($sub->status, [SubOrder::STATUS_CANCELLED, SubOrder::STATUS_DELIVERED], true)) {
                    continue;
                }

                $sub->delivered_at = now();
                $sub->save();
                $sub->addStatus(SubOrder::STATUS_DELIVERED, 'تم التسليم للمريض عبر المندوب', $rider);
            }

            $locked->delivered_at = now();
            $locked->save();
            $locked->addStatus(Order::STATUS_DELIVERED, 'تم تسليم الطلب للمريض', $rider);

            return $this->ok('تم تسليم الطلب بنجاح.', [
                'order_id' => $locked->id,
                'order_status' => $locked->fresh()->status,
                'delivered_at' => $locked->delivered_at?->toDateTimeString(),
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    private function ok(string $message, array $data = []): array
    {
        return ['ok' => true, 'status' => 200, 'message' => $message, 'data' => $data];
    }

    private function error(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message, 'data' => []];
    }
}
