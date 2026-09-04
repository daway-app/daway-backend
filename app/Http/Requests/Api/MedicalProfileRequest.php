<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class MedicalProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'allergies' => 'nullable|array',
            'allergies.*' => 'string|max:100',
            'chronic_diseases' => 'nullable|array',
            'chronic_diseases.*' => 'string|max:100',
            'blood_type' => 'nullable|string|max:10',
            'notes' => 'nullable|string|max:1000',
            'last_local_update' => 'nullable|string',
        ];
    }
}