<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * باركود مرادف عبر الكتالوجات — unique عالمياً على barcode (نفس المنتج الفيزيائي).
 * الإضافة إلزامية عبر providers بسِجل المصدر — لا تخمين.
 */
class MedicineBarcode extends Model
{
    protected $fillable = [
        'moh_medicine_id',
        'local_medicine_id',
        'barcode_raw',
        'barcode',
        'barcode_type',
        'source',
        'source_reference',
        'confidence',
        'is_verified',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:3',
            'is_verified' => 'boolean',
        ];
    }

    public function mohMedicine(): BelongsTo
    {
        return $this->belongsTo(MohMedicine::class);
    }

    public function localMedicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }
}
