<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'descricao' => $this->chave_padrao ? $this->localizedDefaultName() : $this->descricao,
            'horario' => substr((string) $this->horario, 0, 5),
            'ordem' => $this->ordem,
            'is_default' => $this->chave_padrao !== null,
            'archived_at' => $this->archived_at?->toISOString(),
        ];
    }

    private function localizedDefaultName(): string
    {
        return match ($this->chave_padrao) {
            'cafe_da_manha' => __('messages.meal_breakfast'),
            'almoco' => __('messages.meal_lunch'),
            'lanche_da_tarde' => __('messages.meal_afternoon_snack'),
            'jantar' => __('messages.meal_dinner'),
            'ceia' => __('messages.meal_evening_snack'),
            default => $this->descricao,
        };
    }
}
