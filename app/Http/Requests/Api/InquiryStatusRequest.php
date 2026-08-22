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
            'status' => 'required|in:'.implode(',', PatientInquiry::STATUSES),
        ];
    }
}
