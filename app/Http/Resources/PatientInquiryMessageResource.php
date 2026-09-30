<?php

namespace App\Http\Resources;

use App\Models\PatientInquiryMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientInquiryMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PatientInquiryMessage $this */
        return [
            'id' => $this->id,
            'inquiry_id' => $this->patient_inquiry_id,
            'sender_user_id' => $this->sender_user_id,
            'message' => $this->message,
            'is_read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
