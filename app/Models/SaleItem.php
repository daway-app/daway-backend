<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سطر فاتورة بيع.
 *
 * ⚠️ `medicine_name` و `unit_price` **سنابشوت** لا مرآة: يُنسخان وقت البيع
 * ويبقيان ثابتين أبدًا. لو تغيّر سعر الدواء في الكتالوج غدًا، يجب أن تبقى
 * فاتورة اليوم بقيمتها. `medicine_id` مجرد إشارة اختيارية للربط.
 */
class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'medicine_id',
        'pharmacy_medicine_id',
        'medicine_name',
        'barcode',
        'unit_price',
        'quantity',
        'line_discount',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'quantity' => 'integer',
            'line_discount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function pharmacyMedicine(): BelongsTo
    {
        return $this->belongsTo(PharmacyMedicine::class);
    }

    /**
     * مجموع السطر — المصدر الوحيد للحساب، يُستدعى في الـService عند الإنشاء.
     * لا يُحسب في الاستعلامات: القيمة مخزّنة في `line_total`.
     */
    public static function computeLineTotal(float $unitPrice, int $quantity, float $lineDiscount): float
    {
        return round(($unitPrice * $quantity) - $lineDiscount, 2);
    }
}
