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
            'descricao' => $this->descricao,
            'horario' => substr((string) $this->horario, 0, 5),
            'ordem' => $this->ordem,
            'is_default' => $this->chave_padrao !== null,
            'archived_at' => $this->archived_at?->toISOString(),
        ];
    }
}
