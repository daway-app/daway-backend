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
            'phone' => 'sometimes|string|max:20|digits:10|unique:users,phone,'.$userId,
            'avatar_url' => ['sometimes', 'nullable', 'string', 'max:2048', new \App\Rules\SecureImageUrl],
            'birth_date' => 'sometimes|nullable|date|before_or_equal:today',
            'age' => 'sometimes|nullable|integer|min:1|max:120',
            'address' => 'sometimes|nullable|string|max:500',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',
            'notifications_enabled' => 'sometimes|boolean',
            // terms_accepted / terms_accepted_at مقصودة registration-only:
            // لا تُقبل هنا حتى لا تُعدَّل عبر POST /api/profile/patient.
        ];
    }
}
