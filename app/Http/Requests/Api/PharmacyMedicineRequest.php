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
            // C4: SecureImageUrl rule تستبعد javascript:/data: و http://
            'image_url' => ['nullable', 'string', 'max:2048', new \App\Rules\SecureImageUrl],
            // إثراء بيانات الكتالوج: اختياري في التحديث، لا يُمسح قيمة موجودة
            'trade_name' => [
                'sometimes',
                'string',
                'max:150',
                'not_regex:/[\x{0600}-\x{06FF}]/u',
            ],
            'trade_name_ar' => [
                'nullable',
                'string',
                'max:150',
                'regex:/[\x{0600}-\x{06FF}]/u',
            ],
            'active_ingredient' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
