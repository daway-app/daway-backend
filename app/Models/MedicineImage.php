<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صورة مرتبطة بالمنتج — URL + مصدر + حالة تحقق؛ لا Base64 ولا تنزيل.
 */
class MedicineImage extends Model
{
    protected $fillable = [
        'moh_medicine_id',
        'local_medicine_id',
        'image_url',
        'image_type',
        'source',
        'source_reference',
        'is_verified',
    ];

    protected function casts(): array
    {
        return ['is_verified' => 'boolean'];
    }

    public function mohMedicine(): BelongsTo
    {
        return $this->belongsTo(MohMedicine::class);
    }
}
