<?php

namespace App\Http\Resources;

use App\Models\Rating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RatingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Rating $this */
        return [
            'id' => $this->id,
            'stars_rating' => (int) $this->stars_rating,
            'comment' => $this->comment,
            'created_at' => $this->created_at?->toDateTimeString(),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'pharmacy_id' => $this->pharmacy_id,
        ];
    }
}
