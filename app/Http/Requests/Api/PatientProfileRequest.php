<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class PatientProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20|unique:users,phone,'.$userId,
            // C4: SecureImageUrl rule تستبعد javascript:/data: و http://
            'avatar_url' => ['sometimes', 'nullable', 'string', 'max:2048', new \App\Rules\SecureImageUrl],
            'birth_date' => 'sometimes|nullable|date',
            'address' => 'sometimes|nullable|string|max:500',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',
        ];
    }
}
