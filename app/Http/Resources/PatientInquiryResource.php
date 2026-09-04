<?php

namespace App\Http\Resources;

use App\Models\PatientInquiry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientInquiryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PatientInquiry $this */
        return [
            'id' => $this->id,
            'status' => $this->status,
            'message' => $this->message,
            'reply' => $this->reply,
            'availability_status' => $this->availability_status,
            'replied_at' => $this->replied_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'pharmacy' => $this->whenLoaded('pharmacy', fn () => [
                'id' => $this->pharmacy->id,
                'pharmacy_name' => $this->pharmacy->pharmacy_name,
            ]),
            'medicine' => $this->whenLoaded('medicine', fn () => $this->medicine ? [
                'id' => $this->medicine->id,
                'trade_name' => $this->medicine->trade_name,
                'active_ingredient' => $this->medicine->active_ingredient,
            ] : null),
        ];
    }
}
