<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CreateFavoriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'medicine_id' => 'sometimes|required_without:pharmacy_id|nullable|integer|exists:medicines,id',
            'pharmacy_id' => 'sometimes|required_without:medicine_id|nullable|integer|exists:pharmacies,id',
        ];
    }
}