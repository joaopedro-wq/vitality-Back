<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\FoodImage */
class FoodImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'food_id' => $this->alimento_id,
            'food' => FoodResource::make($this->whenLoaded('alimento')),
            'url' => $this->url(),
            'source_url' => $this->source_url,
            'source_license' => $this->source_license,
            'source_license_url' => $this->source_license_url,
            'source_author' => $this->source_author,
            'match_score' => $this->match_score,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
        ];
    }
}
