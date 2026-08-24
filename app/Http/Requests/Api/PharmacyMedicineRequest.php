<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class PharmacyMedicineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'medicine_id' => 'required|exists:medicines,id',
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0',
            'min_stock' => 'nullable|integer|min:0',
            'is_available' => 'sometimes|boolean',
            // صورة اختيارية — الموبايل يرفعها على Cloudinary ويرسل الرابط مباشرة
            'image_url' => 'nullable|url|max:2048',
        ];
    }
}
