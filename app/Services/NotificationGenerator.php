<?php

namespace App\Services;

use App\Models\Medicine;
use App\Models\Notification;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

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

                if (in_array($medicine->id, $existingIds, true)) {
                    continue;
                }

                self::create($user, $medicine->id, __('layout.notif_low_stock_pharmacy', [
                    'name' => $medicine->trade_name,
                    'count' => $row->quantity,
                ]));

                $created = true;
            }
        }

        if ($user->role === 'admin') {
            // الأدوية ذات الكميات المنخفضة إجمالاً عبر الصيدليات (بدل medicines.stock الميت)
            // نأخذ أعلى كمية متبقية لكل دواء كرقم عرض، ونمرر كل دواء مرة واحدة فقط
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

                    if (in_array($medicine->id, $existingIds, true)) {
                        continue;
                    }

                    self::create($user, $medicine->id, __('layout.notif_low_stock', [
                        'name' => $medicine->trade_name,
                        'count' => $row['max_quantity'],
                    ]));

                    $created = true;
                }
            }
        }

        return $created;
    }

    private static function existingMedicineIds(User $user): array
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->where('type', 'low_stock')
            ->whereNotNull('medicine_id')
            ->pluck('medicine_id')
            ->map(fn ($id) => (int) $id)
            ->all();
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
