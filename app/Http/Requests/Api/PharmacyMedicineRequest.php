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
            // إما دواء موجود بالكتالوج العام أو عنصر من كتالوج وزارة الصحة (يُضاف تلقائياً)
            'medicine_id' => 'required_without:moh_medicine_id|nullable|integer|exists:medicines,id',
            'moh_medicine_id' => 'required_without:medicine_id|nullable|integer|exists:moh_medicines,id',
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0',
            'is_available' => 'sometimes|boolean',
            // صورة اختيارية — الموبايل يرفعها على Cloudinary ويرسل الرابط مباشرة
            'image_url' => 'nullable|url|max:2048',
        ];
    }
}
