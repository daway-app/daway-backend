<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class PharmacyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->user()?->id;

        $dayKeys = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'];

        $rules = [
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20|unique:users,phone,'.$userId,
            // C4: SecureImageUrl rule تستبعد javascript:/data: و http://
            'logo_url' => ['sometimes', 'nullable', 'string', 'max:2048', new \App\Rules\SecureImageUrl],
            'address' => 'sometimes|nullable|string|max:500',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',
            'working_hours' => 'sometimes|array',
        ];

        foreach ($dayKeys as $day) {
            $rules["working_hours.{$day}"] = 'nullable|array';
            $rules["working_hours.{$day}.open"] = 'nullable|date_format:H:i';
            $rules["working_hours.{$day}.close"] = "nullable|date_format:H:i|after:working_hours.{$day}.open";
        }

        return $rules;
    }
}
