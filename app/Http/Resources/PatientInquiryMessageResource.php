<?php

namespace App\Http\Resources;

use App\Models\PatientInquiryMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
            'media_url' => $this->media_path ? Storage::url($this->media_path) : null,
            'media_type' => $this->media_type,
            'is_read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
            'is_mine' => isset($this->sender_user_id)
                ? $this->sender_user_id === optional(request()->user())->id
                : false,
        ];
    }
}
