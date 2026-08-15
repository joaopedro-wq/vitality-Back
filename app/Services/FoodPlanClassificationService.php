<?php

namespace App\Services;

use Illuminate\Support\Str;

/** Regras auditáveis para classificar o catálogo TACO legado em tags de plano. */
class FoodPlanClassificationService
{
    private const PROCESSED = ['achocolatado', 'acucar', 'açúcar', 'adocante', 'biscoito', 'bolacha', 'chocolate', 'cocada', 'doce', 'gel', 'refrigerante', 'salgadinho', 'wafer', 'chantilly', 'maionese', 'margarina', 'calda', 'xarope'];
    private const QUICK = ['aveia', 'atum', 'iogurte', 'leite', 'ovo', 'pao', 'pão', 'queijo', 'sardinha', 'tapioca', 'cuscuz', 'conserva', 'enlatada'];
    private const ECONOMIC = ['arroz', 'aveia', 'batata', 'cenoura', 'couve', 'feijao', 'feijão', 'frango', 'fuba', 'fubá', 'lentilha', 'leite', 'macarrao', 'macarrão', 'milho', 'ovo', 'repolho', 'sardinha', 'tomate'];

    /** @return list<string> */
    public function classify(string|null $group, string $description, float $protein, float $carbs, float $fat): array
    {
        $group = $this->normalize($group ?? '');
        $name = $this->normalize($description);
        $tags = [];
        $processed = $this->contains($name, self::PROCESSED);

        if ($group === 'carnes e derivados' || $group === 'pescados e frutos do mar' || $group === 'ovos e derivados') {
            $tags[] = 'proteina';
        } elseif ($group === 'cereais e derivados') {
            $tags[] = 'carboidrato';
        } elseif ($group === 'verduras, hortalicas e derivados') {
            $tags[] = 'vegetal';
            if ($carbs >= 12) $tags[] = 'carboidrato';
        } elseif ($group === 'frutas e derivados') {
            $tags[] = 'fruta';
        } elseif ($group === 'leguminosas e derivados') {
            $tags[] = 'leguminosa';
            if ($protein >= 15) $tags[] = 'proteina';
            if ($fat >= 20) $tags[] = 'gordura';
        } elseif ($group === 'leite e derivados') {
            $tags[] = 'laticinio';
            if ($protein >= 8) $tags[] = 'proteina';
        } elseif ($group === 'gorduras e oleos') {
            $tags[] = 'gordura';
        } else {
            $tags = array_merge($tags, $this->dominantRoles($protein, $carbs, $fat));
        }

        $foundationGroups = ['carnes e derivados', 'cereais e derivados', 'frutas e derivados', 'gorduras e oleos', 'leguminosas e derivados', 'leite e derivados', 'ovos e derivados', 'pescados e frutos do mar', 'verduras, hortalicas e derivados'];
        $isBase = in_array($group, $foundationGroups, true) && ! $processed && ! str_contains($name, 'alcool') && ! str_contains($name, 'aguardente') && ! str_contains($name, 'cerveja');
        if ($isBase) {
            $tags[] = 'base_alimentar';
            $tags[] = 'caseiro';
        } else {
            $tags[] = 'complemento';
        }

        if (($group === 'frutas e derivados' && ! $processed) || $this->contains($name, self::QUICK)) {
            $tags[] = 'rapido';
        }
        if ($isBase && $this->contains($name, self::ECONOMIC)) {
            $tags[] = 'economico';
        }

        return array_values(array_unique($tags));
    }

    /** @return list<string> */
    private function dominantRoles(float $protein, float $carbs, float $fat): array
    {
        $tags = [];
        if ($protein >= 10 && $protein >= $carbs * .45) $tags[] = 'proteina';
        if ($carbs >= 15 && $carbs >= $protein && $carbs >= $fat) $tags[] = 'carboidrato';
        if ($fat >= 10 && $fat >= $protein && $fat >= $carbs) $tags[] = 'gordura';
        return $tags;
    }

    private function normalize(string $value): string { return Str::lower(Str::ascii($value)); }
    private function contains(string $name, array $needles): bool { foreach ($needles as $needle) if (str_contains($name, $this->normalize($needle))) return true; return false; }
}
