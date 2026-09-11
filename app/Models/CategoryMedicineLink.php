<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ربط الأقسام بالأدوية عبر مفاتيح مستقرة فقط:
 * moh_product_id / moh_drug_id (وليس moh_medicines.id غير المستقر) أو medicine_id للأدوية المحلية.
 */
class CategoryMedicineLink extends Model
{
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
            'confidence' => 'integer',
            'needs_review' => 'boolean',
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
