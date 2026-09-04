<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CreateAvailabilityAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'medicine_id' => 'required|integer|exists:medicines,id',
            'pharmacy_id' => 'nullable|integer|exists:pharmacies,id',
        ];
    }
}