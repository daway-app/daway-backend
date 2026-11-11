<?php

namespace App\Services;

use App\Models\Medicine;
use App\Models\Notification;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * يولّد إشعارات النظام تلقائياً حسب البيانات الفعلية.
 */
class NotificationGenerator
{
    /**
     * ينشئ إشعارات نقص المخزون للمستخدم المحدد.
     *
     * - أصحاب الصيدليات: أدوية صيدليتهم (pharmacy_medicines.quantity)
     * - الأدمن فقط: كل الأدوية ذات الكميات المنخفضة عبر الصيدليات (pharmacy_medicines.quantity)
     *
     * لا يكرر الإشعار لنفس المستخدم + الدواء + النوع.
     *
     * @return bool هل أُنشئ إشعار جديد؟
     */
    public static function syncForUser(User $user): bool
    {
        $created = false;

        $pharmacy = $user->pharmacy()->first();

        if ($pharmacy) {
            // يشمل الصفر عمدًا: النفاد الكامل يُصنَّف `out_of_stock` (نفس
            // تصنيف `LowStockNotifier`) — مواءمة M-2.4 (الخيار A).
            $lowStockRows = $pharmacy->pharmacyMedicines()
                ->with('medicine')
                ->where('quantity', '<=', PharmacyMedicine::LOW_STOCK_THRESHOLD)
                ->get();

            $existingIds = self::existingMedicineIds($user);

            foreach ($lowStockRows as $row) {
                $medicine = $row->medicine;
                if (! $medicine) {
                    continue;
                }

                $type = self::classifyQuantity((int) $row->quantity);
                if ($type === null) {
                    continue;
                }

                if (in_array($medicine->id, $existingIds, true)) {
                    continue;
                }

                self::create(
                    $user,
                    $medicine->id,
                    $type,
                    $type === 'out_of_stock'
                        ? __('layout.notif_out_of_stock', ['name' => $medicine->trade_name])
                        : __('layout.notif_low_stock_pharmacy', [
                            'name' => $medicine->trade_name,
                            'count' => (int) $row->quantity,
                        ]),
                );

                $created = true;
            }
        }

        if ($user->role === 'admin') {
            // الأدوية ذات الكميات المنخفضة إجمالاً عبر الصيدليات (بدل medicines.stock الميت)
            // نأخذ أعلى كمية متبقية لكل دواء كرقم عرض، ونمرر كل دواء مرة واحدة فقط.
            // يشمل الصفر عمدًا: أعلى كمية ≤ 0 ⇒ النفاد في كل الصيدليات ⇒ `out_of_stock`.
            $lowStockMedicines = Cache::remember('global_low_stock_medicines', 300, function () {
                return PharmacyMedicine::query()
                    ->select('medicine_id')
                    ->selectRaw('MAX(quantity) as max_quantity')
                    ->where('quantity', '<=', PharmacyMedicine::LOW_STOCK_THRESHOLD)
                    ->groupBy('medicine_id')
                    ->get()
                    ->map(fn ($row) => [
                        'medicine_id' => $row->medicine_id,
                        'max_quantity' => (int) $row->max_quantity,
                    ])
                    ->all();
            });

            if ($lowStockMedicines !== []) {
                $existingIds = self::existingMedicineIds($user);

                $medicineIds = array_column($lowStockMedicines, 'medicine_id');
                $medicines = Medicine::whereIn('id', $medicineIds)->get()->keyBy('id');

                foreach ($lowStockMedicines as $row) {
                    $medicine = $medicines->get($row['medicine_id']);
                    if (! $medicine) {
                        continue;
                    }

                    $type = self::classifyQuantity((int) $row['max_quantity']);
                    if ($type === null) {
                        continue;
                    }

                    if (in_array($medicine->id, $existingIds, true)) {
                        continue;
                    }

                    self::create(
                        $user,
                        $medicine->id,
                        $type,
                        $type === 'out_of_stock'
                            ? __('layout.notif_out_of_stock', ['name' => $medicine->trade_name])
                            : __('layout.notif_low_stock', [
                                'name' => $medicine->trade_name,
                                'count' => $row['max_quantity'],
                            ]),
                    );

                    $created = true;
                }
            }
        }

        return $created;
    }

    /**
     * تشغيل المزامنة مع خنق: مرة واحدة على الأكثر لكل دقيقة لكل مستخدم.
     *
     * هذه هي نقطة التكامل الموحّدة بين الويب والـAPI — كانت منطق الخنق
     * وكسر الكاش خاصاً بواجهة Blade (`web\Admin\NotificationController::
     * syncNotifications`).
     *
     * آمن ضد التكرار: `syncForUser` يتخطّى أي دواء له إشعار `low_stock`
     * أو `out_of_stock` قائم للمستخدم (**بغضّ النظر عن مقروئيته**) ⇒ لا
     * صفوف مكررة، وتصنيف الحالة مطابق لمسار `LowStockNotifier` الحدثي
     * (M-2.4 — الخيار A: مواءمة التصنيف + التفريد عبر النوعين).
     *
     * ⚠️ الأثر: المولّد (الاستطلاع) لا يُعيد الإشعار عند تحوّل النوع بين
     * النفاد والنقص — المسار الحدثي هو مصدر التحولات (موثّق أدناه).
     *
     * لا يفعل شيئاً للمريض: بلا صيدلية وليس أدمن ⇒ no-op.
     *
     * الفشل يُسجَّل فقط ولا يُسقط استجابة قائمة الإشعارات (قراءة لا يجب أن
     * تفشل بسبب مولّد اختياري).
     */
    public static function syncThrottledFor(User $user): void
    {
        $throttleKey = 'notif_sync_at_'.$user->id;

        if (! Cache::add($throttleKey, true, 60)) {
            return;
        }

        try {
            if (self::syncForUser($user)) {
                Cache::forget('notifications_count_'.$user->id);
                Cache::forget('notifications_feed_'.$user->id);
            }
        } catch (Throwable $e) {
            Log::error('Error syncing notifications: '.$e->getMessage());
        }
    }

    /**
     * تصنيف الكمية إلى نوع الإشعار — **نفس** دلالات `LowStockNotifier`
     * (`notifyIfLowStock`) حتى لا يختلف المساران على تصنيف الحالة:
     *   · `quantity <= 0` ⇒ `out_of_stock` (نفاد كامل)
     *   · `0 < quantity <= LOW_STOCK_THRESHOLD` ⇒ `low_stock`
     *   · أعلى من الحد ⇒ لا إشعار (null)
     */
    private static function classifyQuantity(int $quantity): ?string
    {
        if ($quantity <= 0) {
            return 'out_of_stock';
        }

        if ($quantity <= PharmacyMedicine::LOW_STOCK_THRESHOLD) {
            return 'low_stock';
        }

        return null;
    }

    /**
     * التفريد عبر **النوعين معًا** (`low_stock` و`out_of_stock`) لكل مستخدم
     * ودواء — بموافقة M-2.4 (الخيار A).
     *
     * ⚠️ الأثر المقصود: المولّد (مسار الاستطلاع) لا يُعيد الإشعار عند تغيّر
     * النوع بين النفاد والنقص، بخلاف المسار الحدثي `LowStockNotifier` الذي
     * يُنشئ إشعارًا جديدًا لكل تحول (موثّق ومُختبَر في
     * `LowStockNotificationDedupTest`). المسار الحدثي يبقى هو مصدر التحولات.
     */
    private static function existingMedicineIds(User $user): array
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->whereIn('type', ['low_stock', 'out_of_stock'])
            ->whereNotNull('medicine_id')
            ->pluck('medicine_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private static function create(User $user, int $medicineId, string $type, string $message): void
    {
        // عبر الموزّع لا بـ`Notification::create` مباشرة: الإشعار المولَّد
        // كان يبقى في قاعدة البيانات دون أي push، فلا يصل إلى الهاتف.
        app(\App\Services\Notifications\NotificationDispatcher::class)->createAndPush($user, [
            'medicine_id' => $medicineId,
            'type' => $type,
            'message' => $message,
        ]);
    }
}
