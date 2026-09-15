<?php

namespace App\Support;

use App\Models\PharmacyMedicine;

/**
 * التصنيف الموحّد لحالة توفّر دواء في صيدلية معيّنة.
 *
 * استُخرج من MedicineController::availabilityStatus() ليكون مصدراً واحداً
 * يخدم كل من يقرأ التوفر (الـ API الحالي + مساعد الشات) — بدل تكرار
 * القاعدة في أكثر من مكان وتعارضها لاحقاً.
 *
 * القاعدة:
 *  - out_of_stock: الصيدلية غير فعالة، أو السطر غير متاح، أو الكمية صفر.
 *  - low_stock:    الكمية موجبة لكن لا تتجاوز عتبة المخزون المنخفض.
 *  - available:    غير ذلك.
 *
 * ملاحظة: العتبة ثابتة (PharmacyMedicine::LOW_STOCK_THRESHOLD) — عمود
 * `min_stock` مخزَّن لكنه لا يُقرأ في أي منطق توفر حالياً (قرار قائم، غير مُعدَّل هنا).
 */
final class StockStatus
{
    public const AVAILABLE = 'available';

    public const LOW_STOCK = 'low_stock';

    public const OUT_OF_STOCK = 'out_of_stock';

    public static function of(bool $isAvailable, int $quantity, bool $pharmacyActive): string
    {
        if (! $isAvailable || $quantity <= 0 || ! $pharmacyActive) {
            return self::OUT_OF_STOCK;
        }

        if ($quantity <= PharmacyMedicine::LOW_STOCK_THRESHOLD) {
            return self::LOW_STOCK;
        }

        return self::AVAILABLE;
    }

    /**
     * رتبة الحالة للترتيب الحتمي (الأصغر = أفضل).
     * تُستخدم في نمط الترتيب 'best' — الـ LLM لا يشارك في هذا القرار أبداً.
     */
    public static function rank(string $status): int
    {
        return match ($status) {
            self::AVAILABLE => 0,
            self::LOW_STOCK => 1,
            default => 2,
        };
    }
}
