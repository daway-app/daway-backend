<?php

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Notification $this */
        return [
            'id' => $this->id,
            'type' => $this->type,
            'message' => $this->message,
            'is_read' => (bool) $this->is_read,
            'created_at' => $this->created_at?->toDateTimeString(),
            'medicine' => $this->whenLoaded('medicine', fn () => $this->medicine ? [
                'id' => $this->medicine->id,
                'trade_name' => $this->medicine->trade_name,
            ] : null),
        ];
    }
}
