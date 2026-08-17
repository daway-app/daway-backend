<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\PharmacyMedicine;
use App\Models\User;

/**
 * يولّد إشعارات النظام تلقائياً حسب البيانات الفعلية.
 */
class NotificationGenerator
{
    /** الحد الأدنى للمخزون الذي يطلق تنبيه نقص المخزون. */
    public const LOW_STOCK_THRESHOLD = 10;

    /**
     * ينشئ إشعارات نقص المخزون للمستخدم المحدد.
     *
     * - أصحاب الصيدليات: أدوية صيدليتهم (pharmacy_medicines.quantity)
     * - باقي المستخدمين (الأدمن وغيره): كل الأدوية ذات الكميات المنخفضة عبر الصيدليات (pharmacy_medicines.quantity)
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
            $lowStockRows = $pharmacy->pharmacyMedicines()
                ->with('medicine')
                ->where('quantity', '<=', self::LOW_STOCK_THRESHOLD)
                ->get();

            foreach ($lowStockRows as $row) {
                $medicine = $row->medicine;
                if (! $medicine) {
                    continue;
                }

                if (self::existsFor($user, $medicine->id)) {
                    continue;
                }

                self::create($user, $medicine->id, __('layout.notif_low_stock_pharmacy', [
                    'name' => $medicine->trade_name,
                    'count' => $row->quantity,
                ]));

                $created = true;
            }
        }

        // الأدوية ذات الكميات المنخفضة إجمالاً عبر الصيدليات (بدل medicines.stock الميت)
        // نأخذ أعلى كمية متبقية لكل دواء كرقم عرض، ونمرر كل دواء مرة واحدة فقط
        $lowStockMedicines = PharmacyMedicine::query()
            ->select('medicine_id')
            ->selectRaw('MAX(quantity) as max_quantity')
            ->with('medicine')
            ->where('quantity', '<=', self::LOW_STOCK_THRESHOLD)
            ->groupBy('medicine_id')
            ->get();

        foreach ($lowStockMedicines as $row) {
            $medicine = $row->medicine;
            if (! $medicine) {
                continue;
            }

            if (self::existsFor($user, $medicine->id)) {
                continue;
            }

            self::create($user, $medicine->id, __('layout.notif_low_stock', [
                'name' => $medicine->trade_name,
                'count' => $row->max_quantity,
            ]));

            $created = true;
        }

        return $created;
    }

    private static function existsFor(User $user, int $medicineId): bool
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->where('medicine_id', $medicineId)
            ->where('type', 'low_stock')
            ->exists();
    }

    private static function create(User $user, int $medicineId, string $message): void
    {
        Notification::create([
            'user_id' => $user->id,
            'medicine_id' => $medicineId,
            'type' => 'low_stock',
            'message' => $message,
            'is_read' => false,
            'created_at' => now(),
        ]);
    }
}
