<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سطر فاتورة شراء.
 *
 * الفرق الجوهري عن `SaleItem`: هنا `unit_cost` (تكلفة الشراء) لا سعر البيع،
 * و`quantity` **تزيد** المخزون لا تخصمه. كل الحسابات المالية تبقى في
 * `AccountingLedger` — هذا الصنف بيانات فقط.
 */
class PurchaseItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_id',
        'pharmacy_medicine_id',
        'medicine_id',
        'moh_medicine_id',
        'medicine_name',
        'barcode',
        'unit_cost',
        'quantity',
        'line_discount',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'quantity' => 'integer',
            'line_discount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function pharmacyMedicine(): BelongsTo
    {
        return $this->belongsTo(PharmacyMedicine::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function mohMedicine(): BelongsTo
    {
        return $this->belongsTo(MohMedicine::class);
    }

    /** نفس صيغة `SaleItem::computeLineTotal` — اتساق الحساب بين البيع والشراء. */
    public static function computeLineTotal(float $unitCost, int $quantity, float $lineDiscount): float
    {
        return round(($unitCost * $quantity) - $lineDiscount, 2);
    }
}
