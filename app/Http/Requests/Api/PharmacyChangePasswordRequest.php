<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class PharmacyChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ];
    }

    public function attributes(): array
    {
        return [
            'current_password' => 'كلمة المرور الحالية',
            'password' => 'كلمة المرور الجديدة',
        ];
    }
}
