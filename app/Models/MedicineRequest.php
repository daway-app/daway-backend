<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * طلب دواء جديد من صيدلية إلى إدارة للمراجعة والاعتماد.
 *
 * عند الاعتماد يُملا أحد الحقلين فقط (علاقة حصرية):
 *  - approved_moh_medicine_id  → دواء موجود في الكتالوج الرسمي (MOH)
 *  - approved_medicine_id      → دواء محلي canonical في medicines
 *
 * الحقلان معًا فارغان قبل الاعتماد، ولا يكون أحدهما معبئًا مع الآخر بعد.
 */
class MedicineRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'pharmacy_id',
        'requested_by',
        'status',
        'trade_name',
        'trade_name_ar',
        'generic_name',
        'manufacturer',
        'active_ingredient',
        'dosage_form',
        'packaging',
        'origin',
        'company',
        'official_price',
        'barcode',
        'image',
        'category_id',
        'subcategory_id',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
        'approved_moh_medicine_id',
        'approved_medicine_id',
    ];

    protected function casts(): array
    {
        return [
            'official_price' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class);
    }

    public function approvedMohMedicine(): BelongsTo
    {
        return $this->belongsTo(MohMedicine::class, 'approved_moh_medicine_id');
    }

    public function approvedMedicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class, 'approved_medicine_id');
    }
}
