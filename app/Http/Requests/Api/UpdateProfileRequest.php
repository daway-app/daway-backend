<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // المستخدم لازم يكون مسجل دخول
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'phone_number' => 'sometimes|string|max:20',
            'address' => 'sometimes|string|max:500',
            'birth_date' => 'sometimes|date',
            'emergency_contact' => 'sometimes|string|max:20',
            'avatar' => 'sometimes|image|mimes:jpeg,png,jpg,gif|max:2048', // الصورة اختيارية، بحد أقصى 2MB
        ];
    }
}
