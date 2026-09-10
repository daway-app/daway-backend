<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ربط التصنيفات بالأدوية عبر مفاتيح مستقرة:
 *  - moh_product_id / moh_drug_id: مفاتيح أعمال وزارة الصحة (مستقرة عبر moh:import/moh:sync)
 *  - medicine_id: الأدوية المحلية (جدول medicines)
 * ممنوع الاعتماد على moh_medicines.id — غير مستقر عبر المزامنة.
 */
class CategoryMedicineLink extends Model
{
    use HasFactory;

    public const SOURCE_PRODUCT_CLASS = 'product_class';
    public const SOURCE_RULES = 'rules';
    public const SOURCE_ADMIN = 'admin';

    protected $fillable = [
        'category_id',
        'moh_product_id',
        'moh_drug_id',
        'medicine_id',
        'source',
        'confidence',
        'needs_review',
    ];

    protected function casts(): array
    {
        return [
            'needs_review' => 'boolean',
            'confidence' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }
}
