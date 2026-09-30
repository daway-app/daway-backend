<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class PatientInquiryMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => 'nullable|string|max:1000',
            'media' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'media_type' => 'nullable|string|in:image',
        ];
    }

    public function messages(): array
    {
        return [
            'message.required' => 'يجب إرسال رسالة أو صورة واحدة على الأقل.',
            'media.required' => 'يجب إرسال رسالة أو صورة واحدة على الأقل.',
        ];
    }

    protected function passedValidation(): void
    {
        if (! $this->hasFile('media') && ! $this->input('message')) {
            throw ValidationException::withMessages([
                'message' => 'يجب إرسال رسالة نصية أو صورة واحدة على الأقل.',
                'media' => 'يجب إرسال رسالة نصية أو صورة واحدة على الأقل.',
            ]);
        }
    }
}
