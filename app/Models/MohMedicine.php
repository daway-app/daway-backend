<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * كتالوج أدوية وزارة الصحة الفلسطينية (المستحضرات المسجلة + قائمة الأسعار).
 * يتم تعبئته عبر الأمر: php artisan moh:sync
 */
class MohMedicine extends Model
{
    use HasFactory;

    protected $table = 'moh_medicines';

    protected $fillable = [
        'trade_name',
        'manufacturer',
        'dosage_form',
        'product_class',
        'origin',
        'moh_product_id',
        'generic_name',
        'official_price',
        'packaging',
        'company',
        'availability',
        'moh_drug_id',
        'price_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'official_price' => 'decimal:2',
            'price_updated_at' => 'date',
        ];
    }
}
