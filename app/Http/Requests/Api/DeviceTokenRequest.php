<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class DeviceTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => 'required|string|max:512|unique:device_tokens,token',
            'platform' => 'required|in:android,ios',
            'device_id' => 'required|string|max:191',
        ];
    }
}