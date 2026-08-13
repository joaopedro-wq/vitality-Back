<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Alimento */
class FoodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'descricao' => $this->descricao,
            'proteina' => (float) $this->proteina,
            'gordura' => (float) $this->gordura,
            'carbo' => (float) $this->carbo,
            'caloria' => (float) $this->caloria,
            'qtd' => (float) $this->qtd,
            'fonte' => $this->fonte,
            'source_reference' => $this->source_reference,
            'grupo' => $this->grupo,
            'status' => $this->status,
            'is_favorite' => (bool) ($this->is_favorite ?? false),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
