<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class PharmacyInventoryBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer|exists:pharmacy_medicines,id',
            'items.*.quantity' => 'required|integer|min:0',
            'items.*.is_available' => 'sometimes|boolean',
        ];
    }
}
