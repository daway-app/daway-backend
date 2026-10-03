<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class PatientInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pharmacy_id' => 'required|exists:pharmacies,id',
            // medicine_id اختياري: المريض يقدر يراسل الصيدلية مباشرة من الخريطة
            // بلا اختيار دواء. الصيدلية تبقى إلزامية — لا معنى لمحادثة بلا طرف.
            'medicine_id' => 'nullable|exists:medicines,id',
            'message' => 'nullable|string|max:1000',
        ];
    }
}
