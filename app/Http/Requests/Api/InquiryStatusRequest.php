<?php

namespace App\Http\Requests\Api;

use App\Models\PatientInquiry;
use Illuminate\Foundation\Http\FormRequest;

class InquiryStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'sometimes|in:'.implode(',', PatientInquiry::STATUSES),
            'reply' => 'sometimes|nullable|string|max:1000',
            'availability_status' => 'sometimes|nullable|in:'.implode(',', PatientInquiry::AVAILABILITY_STATUSES),
        ];
    }
}
