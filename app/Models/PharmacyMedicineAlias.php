<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مرادف اسم دواء خاص بصيدلية واحدة.
 *
 * يُنشأ فقط بعد تأكيد صريح من الصيدلي أن كتابة معيّنة تعني دواءً محدداً.
 * النطاق صيدلية واحدة عن قصد — لا نسمح لتأكيد صيدلية بتغيير سلوك الكتالوج
 * العام لبقية الصيدليات.
 */
class PharmacyMedicineAlias extends Model
{
    use HasFactory;

    protected $table = 'pharmacy_medicine_aliases';

    protected $fillable = [
        'pharmacy_id',
        'medicine_id',
        'alias',
    ];

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }
}
