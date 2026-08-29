<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class RatingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'pharmacy_id' => [
                'required',
                'exists:pharmacies,id',
                // C2: تقييم واحد لكل (مستخدم، صيدلية) — يمنع spam حتى قبل الوصول إلى DB.
                'unique:ratings,pharmacy_id,NULL,id,user_id,'.$userId,
            ],
            'stars_rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ];
    }
}
