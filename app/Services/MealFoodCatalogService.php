<?php

namespace App\Services;

use App\Models\Alimento;
use Illuminate\Support\Str;


class MealFoodCatalogService
{
    /** @return array<string, mixed> */
    public function profile(Alimento $food): array
    {
        $planning = $food->relationLoaded('planningProfile') ? $food->planningProfile : null;
        if ($planning) {
            return [
                'grupo_alimentar' => $food->grupo,
                'familia' => $planning->family,
                'forma_de_consumo' => $planning->consumption_form,
                'preparo' => $planning->preparation,
                'adequado_para_consumo_direto' => $planning->direct_consumption,
                'ingrediente_de_apoio' => $planning->support_ingredient,
                'porcao' => ['min' => (float) $planning->portion_min_g, 'max' => (float) $planning->portion_max_g, 'step' => (float) $planning->portion_step_g],
            ];
        }
        $name = $this->normalize($food->descricao);
        $group = $this->normalize((string) $food->grupo);
        $family = $this->family($name, $group);
        $rawIngredient = $this->isRawIngredient($name, $group, $family);
        $ingredientOnly = $this->isIngredientOnly($name, $group);

        return [
            'grupo_alimentar' => $food->grupo,
            'familia' => $family,
            'forma_de_consumo' => $this->consumptionForm($name, $group, $family),
            'preparo' => $this->preparation($name, $family),
            'adequado_para_consumo_direto' => ! $rawIngredient && ! $ingredientOnly,
            'ingrediente_de_apoio' => $ingredientOnly,
            'porcao' => $this->portionRange($food),
        ];
    }

    public function supportsRole(Alimento $food, string $role): bool
    {
        if (! $food->planTags->contains('slug', $role)) {
            return false;
        }

        $profile = $this->profile($food);
        if (! $profile['adequado_para_consumo_direto']) {
            return false;
        }

        $family = $profile['familia'];
        $name = $this->normalize($food->descricao);
        if ($role === 'prato_base' && Str::contains($name, ['frito', 'frita'])) {
            return false;
        }

        return match ($role) {
            'prato_proteina' => in_array($family, ['carne', 'peixe', 'ovo', 'proteina_vegetal'], true),
            'prato_base' => in_array($family, ['arroz', 'massa', 'tuberculo', 'cuscuz'], true),
            'prato_leguminosa' => $family === 'leguminosa',
            'prato_vegetal' => $family === 'vegetal',
            'acompanhamento' => $family === 'gordura' || str_contains($name, 'farinha de mandioca'),
            'cafe_base' => in_array($family, ['pao_torrada', 'tapioca', 'aveia_cereal', 'cuscuz'], true),
            'cafe_proteina' => in_array($family, ['ovo', 'iogurte', 'queijo', 'leite_liquido'], true),
            'fruta_lanche' => $family === 'fruta',
            'lanche_pratico' => in_array($family, ['fruta', 'iogurte', 'leite_liquido', 'queijo', 'pao_torrada', 'tapioca', 'aveia_cereal', 'castanha'], true),
            default => false,
        };
    }

    public function foodFamily(Alimento $food): string
    {
        return $this->profile($food)['familia'];
    }

    /** @return array{min: float, max: float, step: float} */
    public function portionRange(Alimento $food, ?string $role = null): array
    {
        $planning = $food->relationLoaded('planningProfile') ? $food->planningProfile : null;
        if ($planning) return ['min' => (float) $planning->portion_min_g, 'max' => (float) $planning->portion_max_g, 'step' => (float) $planning->portion_step_g];
        $name = $this->normalize($food->descricao);
        $group = $this->normalize((string) $food->grupo);
        $family = $this->family($name, $group);

        $range = match ($family) {
            'pao_torrada' => ['min' => 25.0, 'max' => 120.0, 'step' => 5.0],
            'tapioca', 'cuscuz' => ['min' => 40.0, 'max' => 220.0, 'step' => 5.0],
            'aveia_cereal' => ['min' => 20.0, 'max' => 100.0, 'step' => 5.0],
            'fruta' => ['min' => 70.0, 'max' => 300.0, 'step' => 10.0],
            'iogurte', 'leite_liquido' => ['min' => 120.0, 'max' => 350.0, 'step' => 10.0],
            'queijo' => ['min' => 20.0, 'max' => 80.0, 'step' => 5.0],
            'ovo' => ['min' => 40.0, 'max' => 180.0, 'step' => 5.0],
            'carne', 'peixe', 'proteina_vegetal' => ['min' => 60.0, 'max' => 250.0, 'step' => 5.0],
            'arroz', 'massa', 'tuberculo' => ['min' => 50.0, 'max' => 300.0, 'step' => 10.0],
            'leguminosa' => ['min' => 60.0, 'max' => 220.0, 'step' => 10.0],
            'vegetal' => ['min' => 50.0, 'max' => 300.0, 'step' => 10.0],
            'gordura' => ['min' => 3.0, 'max' => 20.0, 'step' => 1.0],
            'castanha' => ['min' => 10.0, 'max' => 40.0, 'step' => 5.0],
            default => ['min' => 20.0, 'max' => 250.0, 'step' => 5.0],
        };

        if ($role === 'acompanhamento' && $family !== 'vegetal') {
            return ['min' => 3.0, 'max' => 30.0, 'step' => 1.0];
        }

        return $range;
    }

    public function normalizeQuantity(Alimento $food, float $quantity, ?string $role = null): float
    {
        $range = $this->portionRange($food, $role);
        $clamped = min($range['max'], max($range['min'], $quantity));

        return round(round($clamped / $range['step']) * $range['step'], 1);
    }

    public function quantityIsRealistic(Alimento $food, float $quantity, ?string $role = null): bool
    {
        $range = $this->portionRange($food, $role);

        return $quantity >= $range['min'] && $quantity <= $range['max'];
    }

    public function scoreForStyle(Alimento $food, string $style): float
    {
        $name = $this->normalize($food->descricao);
        $family = $this->foodFamily($food);

        return match ($style) {
            'rapido' => in_array($family, ['fruta', 'iogurte', 'leite_liquido', 'pao_torrada', 'aveia_cereal', 'queijo', 'ovo', 'tapioca'], true) ? 45.0 : 0.0,
            'caseiro' => in_array($family, ['arroz', 'leguminosa', 'vegetal', 'carne', 'peixe', 'tuberculo', 'cuscuz'], true) ? 45.0 : 0.0,
            'economico' => Str::contains($name, ['arroz', 'feijao', 'frango', 'ovo', 'banana', 'aveia', 'batata', 'mandioca', 'couve', 'cenoura', 'leite']) ? 45.0 : 0.0,
            default => 0.0,
        };
    }

    private function family(string $name, string $group): string
    {
        return match (true) {
            str_contains($group, 'frutas') => 'fruta',
            str_contains($group, 'verduras') || str_contains($group, 'hortalicas') => 'vegetal',
            str_contains($group, 'leguminosas') => 'leguminosa',
            str_contains($group, 'pescados') => 'peixe',
            str_contains($group, 'ovos') || Str::contains($name, ['ovo', 'omelete']) => 'ovo',
            str_contains($group, 'carnes') => 'carne',
            Str::contains($name, ['banana', 'maca', 'mamã', 'mamao', 'laranja', 'manga', 'uva', 'pera', 'abacaxi', 'morango', 'melancia', 'melão', 'kiwi', 'goiaba']) => 'fruta',
            Str::contains($name, ['brocolis', 'couve', 'alface', 'tomate', 'cenoura', 'abobrinha', 'pepino', 'repolho', 'beterraba', 'espinafre', 'vagem', 'chuchu']) => 'vegetal',
            Str::contains($name, ['feijao', 'lentilha', 'grao de bico', 'ervilha', 'guandu']) => 'leguminosa',
            Str::contains($name, ['frango', 'carne bovina', 'carne suina', 'carne de porco', 'charque', 'carne seca']) => 'carne',
            Str::contains($name, ['peixe', 'tilapia', 'sardinha', 'atum', 'salmao']) => 'peixe',
            Str::contains($name, ['tofu', 'proteina de soja', 'proteina vegetal']) => 'proteina_vegetal',
            str_contains($name, 'iogurte') => 'iogurte',
            Str::contains($name, ['queijo', 'ricota', 'requeijao']) => 'queijo',
            str_contains($name, 'leite') && ! Str::contains($name, [' em po', ' em pó', ' po ', ' pó ']) => 'leite_liquido',
            Str::contains($name, ['pao', 'pão', 'torrada']) => 'pao_torrada',
            str_contains($name, 'tapioca') => 'tapioca',
            Str::contains($name, ['aveia', 'granola', 'cereal']) => 'aveia_cereal',
            str_contains($name, 'cuscuz') => 'cuscuz',
            str_contains($name, 'arroz') => 'arroz',
            Str::contains($name, ['macarrao', 'macarrão']) => 'massa',
            Str::contains($name, ['batata', 'mandioca', 'inhame', 'cará', 'cará']) => 'tuberculo',
            str_contains($group, 'gorduras') || Str::contains($name, ['azeite', 'óleo', 'oleo']) => 'gordura',
            Str::contains($name, ['castanha', 'amendoim', 'noz']) => 'castanha',
            default => 'outro',
        };
    }

    private function isRawIngredient(string $name, string $group, string $family): bool
    {
        if (! str_contains($name, 'cru')) {
            return false;
        }

        // Fruta crua É a forma normal de consumo (ninguém "prepara" uma banana ou um
        // abacaxi antes de comer) — ao contrário de carne/arroz/etc. crus, que exigem
        // preparo. Sem esse caso especial, toda fruta com "crua"/"cru" na descrição
        // (ex. "Abacate, cru", "Abacaxi, cru") ficava com `adequado_para_consumo_direto
        // = false` e nunca virava candidata em nenhum papel, mesmo já tagueada
        // corretamente em `food_plan_tags` — achado corrigindo `included_food_ids`
        // rejeitando abacate/abacaxi com "não se encaixa em nenhuma refeição".
        if ($family === 'fruta') {
            return false;
        }

        return $family !== 'vegetal' || ! Str::contains($name, ['salada', 'alface', 'tomate', 'pepino', 'cenoura']);
    }

    private function isIngredientOnly(string $name, string $group): bool
    {
        return Str::contains($name, ['farinha de puba', 'farinha crua', 'amido', 'fecula', 'fécula', 'mistura em po', 'mistura em pó'])
            || (str_contains($name, 'leite') && Str::contains($name, [' em po', ' em pó']))
            || str_contains($group, 'acucares');
    }

    private function consumptionForm(string $name, string $group, string $family): string
    {
        if ($this->isRawIngredient($name, $group, $family)) return 'exige_preparo';
        if ($this->isIngredientOnly($name, $group)) return 'ingrediente';
        if ($family === 'vegetal' && str_contains($name, 'cru')) return 'cru_apropriado';

        return 'pronto_para_consumo';
    }

    private function preparation(string $name, string $family): string
    {
        foreach (['cozido', 'grelhado', 'assado', 'refogado', 'cru', 'frito'] as $preparation) {
            if (str_contains($name, $preparation)) return $preparation;
        }

        return match ($family) {
            'carne', 'peixe', 'ovo', 'arroz', 'massa', 'tuberculo', 'leguminosa' => 'preparo_domestico',
            'vegetal' => 'cru_ou_refogado',
            default => 'pronto_para_consumo',
        };
    }

    private function normalize(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }
}
