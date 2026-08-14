<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiaryEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $totals = ['proteina' => 0.0, 'gordura' => 0.0, 'carbo' => 0.0, 'caloria' => 0.0, 'quantidade' => 0.0];
        $items = $this->alimentos->map(function ($food) use (&$totals) {
            $pivot = $food->pivot;
            $quantity = (float) $pivot->qtd;
            $base = (float) ($pivot->qtd_base_snapshot ?? $food->qtd);
            $factor = $base > 0 ? $quantity / $base : 0;
            $macros = [
                'proteina' => (float) ($pivot->proteina_snapshot ?? $food->proteina) * $factor,
                'gordura' => (float) ($pivot->gordura_snapshot ?? $food->gordura) * $factor,
                'carbo' => (float) ($pivot->carbo_snapshot ?? $food->carbo) * $factor,
                'caloria' => (float) ($pivot->caloria_snapshot ?? $food->caloria) * $factor,
            ];

            foreach ($macros as $key => $value) {
                $totals[$key] += $value;
            }
            $totals['quantidade'] += $quantity;

            $nutrients = $pivot->nutrientes_snapshot;
            if (is_string($nutrients)) {
                $nutrients = json_decode($nutrients, true);
            }
            $nutrients = collect($nutrients ?? [])->map(function (array $nutrient) use ($factor) {
                $nutrient['valor'] = round(((float) ($nutrient['valor'] ?? 0)) * $factor, 5);

                return $nutrient;
            })->values()->all();

            return [
                'food_id' => $food->id,
                'descricao' => $pivot->descricao_snapshot ?? $food->descricao,
                'quantity' => round($quantity, 3),
                'macros' => collect($macros)->map(fn (float $value) => round($value, 3))->all(),
                'nutrientes' => $nutrients,
            ];
        })->values();

        return [
            'id' => $this->id,
            'date' => $this->data?->format('Y-m-d'),
            'consumed_at' => $this->consumed_at?->toISOString(),
            'meal' => [
                'id' => $this->id_refeicao,
                'descricao' => $this->descricao_refeicao_snapshot ?? $this->refeicao?->descricao,
                'horario' => $this->horario_refeicao_snapshot ? substr((string) $this->horario_refeicao_snapshot, 0, 5) : substr((string) ($this->refeicao?->horario ?? ''), 0, 5),
            ],
            'items' => $items,
            'totals' => collect($totals)->map(fn (float $value) => round($value, 3))->all(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
