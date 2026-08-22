<?php

namespace App\Http\Resources;

use App\Models\PharmacyMedicine;
use App\Support\Image;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PharmacyMedicine
 */
class PharmacyMedicineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $threshold = $this->min_stock !== null ? (int) $this->min_stock : 10;
        $quantity = (int) $this->quantity;

        return [
            'id' => $this->id,
            'medicine_id' => $this->medicine_id,
            'pharmacy_id' => $this->pharmacy_id,
            'price' => $this->price !== null ? (float) $this->price : 0.0,
            'quantity' => $quantity,
            'min_stock' => $this->min_stock !== null ? (int) $this->min_stock : null,
            'is_available' => (bool) $this->is_available,
            'is_low_stock' => $quantity > 0 && $quantity <= $threshold,
            'is_out_of_stock' => $quantity <= 0,
            'medicine' => $this->whenLoaded('medicine', function () {
                $medicine = $this->medicine;

                return [
                    'id' => $medicine?->id,
                    'trade_name' => $medicine?->trade_name,
                    'active_ingredient' => $medicine?->active_ingredient,
                    'image_url' => Image::url($medicine?->image),
                ];
            }),
        ];
    }
}
