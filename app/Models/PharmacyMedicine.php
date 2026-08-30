<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * هاد الموديل بيمثل جدول pharmacy_medicines.
 * تقنياً هو جدول "ربط" (pivot) بين pharmacies و medicines،
 * بس بما إنه فيه أعمدة زيادة عن مجرد pharmacy_id و medicine_id
 * (زي price و quantity و is_available) — منعاملو كموديل Eloquent
 * كامل ومستقل، مش نستخدم دالة belongsToMany() العادية.
 * هيك منقدر نتعامل مع كل سطر لحاله (نعدل السعر، نحدث الكمية...الخ)
 * بشكل أوضح وأسهل.
 */
class PharmacyMedicine extends Model
{
    use HasFactory;
    use LogsActivity;

    /**
     * الحد الثابت لاعتبار المخزون منخفضاً (لم يعد قابلاً للاختيار لكل دواء).
     */
    public const LOW_STOCK_THRESHOLD = 10;

    protected $table = 'pharmacy_medicines';

    protected $fillable = [
        'pharmacy_id',    // الصيدلية
        'medicine_id',     // الدواء
        'price',            // سعر الدواء بهاي الصيدلية بالتحديد
        'quantity',         // الكمية المتوفرة
        'is_available',     // هل متوفر حالياً أو لأ
        'min_stock',        // حد المخزون المنخفض المخصص لهذا الدواء
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'quantity' => 'integer',
            'min_stock' => 'integer',
            'is_available' => 'boolean',
        ];
    }

    /**
     * علاقة عكسية: هاد السطر تبع صيدلية وحدة بالضبط.
     */
    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    /**
     * علاقة عكسية: هاد السطر تبع دواء وحدة بالضبط.
     */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }
}
