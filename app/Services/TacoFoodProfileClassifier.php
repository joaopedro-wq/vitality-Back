<?php

namespace App\Services;

use Illuminate\Support\Str;

/** Deterministic, reviewable rules applied to every TACO item during import. */
class TacoFoodProfileClassifier
{
    /** @return array{family:string,consumption_form:string,preparation:string,direct_consumption:bool,support_ingredient:bool,portion:array{min:float,max:float,step:float},diets:array<string,string>,restrictions:array<string,string>,confidence:float,review_status:string,tags:list<string>,aliases:list<string>} */
    public function classify(string $group, string $description, float $protein, float $carbs, float $fat): array
    {
        $name = $this->normalize($description);
        $group = $this->normalize($group);
        $family = $this->family($group, $name);
        $support = Str::contains($name, ['farinha crua', 'amido', 'fecula', 'fécula', 'mistura em po', 'mistura em pó', 'tempero', 'caldo']);
        $raw = Str::contains($name, 'cru') && ! in_array($family, ['fruta', 'vegetal'], true);
        $direct = ! $support && ! $raw;
        $diets = $this->diets($family, $group, $name);
        $restrictions = $this->restrictions($family, $group, $name);
        $confidence = in_array($family, ['outro', 'preparacao'], true) || $this->hasUnknownRestriction($restrictions) ? .65 : .98;

        return [
            'family' => $family,
            'consumption_form' => $support ? 'ingrediente' : ($raw ? 'exige_preparo' : ($family === 'vegetal' && Str::contains($name, 'cru') ? 'cru_apropriado' : 'pronto_para_consumo')),
            'preparation' => $this->preparation($name, $family),
            'direct_consumption' => $direct,
            'support_ingredient' => $support,
            'portion' => $this->portion($family),
            'diets' => $diets,
            'restrictions' => $restrictions,
            'confidence' => $confidence,
            'review_status' => $confidence < .9 ? 'pendente' : 'automatico',
            'tags' => $this->tags($group, $name, $family, $protein, $carbs, $fat, $direct),
            'aliases' => $this->aliases($description),
        ];
    }

    private function family(string $group, string $name): string
    {
        return match (true) {
            // A presença de "soja" no nome não transforma qualquer item em proteína
            // vegetal: óleo e pipoca de soja são, respectivamente, gordura e cereal.
            Str::contains($name, 'pipoca') => 'cereal',
            Str::contains($name, ['óleo', 'oleo']) => 'gordura',
            Str::contains($name, ['tofu', 'proteína texturizada', 'proteina texturizada', 'proteína de soja', 'proteina de soja']) => 'proteina_vegetal',
            Str::contains($group, 'frutas') => 'fruta', Str::contains($group, ['verduras', 'hortalicas']) => 'vegetal',
            Str::contains($group, 'leguminosas') => 'leguminosa', Str::contains($group, 'pescados') => 'peixe',
            Str::contains($group, 'ovos') => 'ovo', Str::contains($group, 'carnes') => 'carne',
            Str::contains($group, ['nozes', 'sementes']) => 'castanha',
            Str::contains($group, 'leite') && Str::contains($name, 'iogurte') => 'iogurte',
            Str::contains($group, 'leite') && Str::contains($name, ['queijo', 'ricota', 'requeijao']) => 'queijo',
            Str::contains($group, 'leite') => 'leite_liquido', Str::contains($name, ['aveia', 'granola']) => 'aveia_cereal',
            Str::contains($name, ['pao', 'pão', 'torrada']) => 'pao_torrada', Str::contains($name, 'tapioca') => 'tapioca',
            Str::contains($name, 'cuscuz') => 'cuscuz', Str::contains($name, 'arroz') => 'arroz',
            Str::contains($name, ['macarrao', 'macarrão']) => 'massa', Str::contains($name, ['batata', 'mandioca', 'inhame', 'cará']) => 'tuberculo',
            Str::contains($group, 'gorduras') => 'gordura',
            Str::contains($name, ['castanha', 'amendoim', 'noz']) => 'castanha', Str::contains($group, ['preparacoes', 'pratos prontos']) => 'preparacao',
            Str::contains($group, 'cereais') => 'cereal',
            default => 'outro',
        };
    }

    /** @return array<string,string> */
    private function diets(string $family, string $group, string $name): array
    {
        $animal = in_array($family, ['carne', 'peixe'], true);
        $vegan = $animal || in_array($family, ['ovo', 'iogurte', 'queijo', 'leite_liquido'], true) ? 'incompativel' : 'compativel';
        $unknown = in_array($family, ['outro', 'preparacao'], true) || Str::contains($name, ['mel', 'gelatina']);

        return ['onivora' => 'compativel', 'vegetariana' => $animal ? 'incompativel' : ($unknown ? 'desconhecido' : 'compativel'), 'vegana' => $unknown ? 'desconhecido' : $vegan];
    }

    /** @return array<string,string> */
    private function restrictions(string $family, string $group, string $name): array
    {
        $unknownProcessed = in_array($family, ['outro', 'preparacao'], true);
        $gluten = Str::contains($name, ['trigo', 'cevada', 'centeio', 'pao', 'pão', 'macarrao', 'macarrão', 'biscoito', 'bolo']) ? 'incompativel' : (Str::contains($name, 'aveia') ? 'desconhecido' : ($unknownProcessed ? 'desconhecido' : 'compativel'));
        $lactose = in_array($family, ['iogurte', 'queijo', 'leite_liquido'], true) ? 'incompativel' : ($unknownProcessed ? 'desconhecido' : 'compativel');
        $egg = $family === 'ovo' ? 'incompativel' : ($unknownProcessed ? 'desconhecido' : 'compativel');
        $peanut = Str::contains($name, 'amendoim') ? 'incompativel' : ($unknownProcessed ? 'desconhecido' : 'compativel');
        $shellfish = Str::contains($name, ['camarao', 'camarão', 'caranguejo', 'lagosta', 'marisco']) ? 'incompativel' : ($unknownProcessed ? 'desconhecido' : 'compativel');

        return compact('gluten', 'lactose', 'egg', 'peanut', 'shellfish');
    }

    /** @return list<string> */
    private function tags(string $group, string $name, string $family, float $protein, float $carbs, float $fat, bool $direct): array
    {
        $tags = $direct ? ['base_alimentar', 'caseiro'] : ['complemento'];
        $tags = [...$tags, ...match ($family) {
            'fruta' => ['fruta', 'fruta_lanche', 'lanche_pratico', 'rapido'], 'vegetal' => ['vegetal', 'prato_vegetal'],
            'leguminosa' => ['leguminosa', 'prato_leguminosa'], 'carne', 'peixe', 'proteina_vegetal' => ['proteina', 'prato_proteina'],
            'ovo' => ['proteina', 'prato_proteina', 'cafe_proteina'], 'iogurte', 'queijo', 'leite_liquido' => ['laticinio', 'cafe_proteina', 'lanche_pratico'],
            'arroz', 'massa', 'tuberculo' => ['carboidrato', 'prato_base'], 'cereal' => ['carboidrato'], 'aveia_cereal', 'pao_torrada', 'tapioca', 'cuscuz' => ['carboidrato', 'cafe_base', 'lanche_pratico'],
            'gordura', 'castanha' => ['gordura', 'acompanhamento'], default => [],
        }];
        if ($protein >= 10 && ! in_array('proteina', $tags, true)) $tags[] = 'proteina';
        if ($carbs >= 15 && ! in_array('carboidrato', $tags, true)) $tags[] = 'carboidrato';
        if ($fat >= 10 && ! in_array('gordura', $tags, true)) $tags[] = 'gordura';

        return array_values(array_unique($tags));
    }

    /** @return array{min:float,max:float,step:float} */
    private function portion(string $family): array
    {
        return match ($family) {
            'fruta' => ['min' => 70., 'max' => 300., 'step' => 10.], 'aveia_cereal' => ['min' => 20., 'max' => 100., 'step' => 5.],
            'carne', 'peixe', 'proteina_vegetal' => ['min' => 60., 'max' => 250., 'step' => 5.], 'ovo' => ['min' => 40., 'max' => 180., 'step' => 5.],
            'arroz', 'massa', 'tuberculo' => ['min' => 50., 'max' => 300., 'step' => 10.], 'leguminosa' => ['min' => 60., 'max' => 220., 'step' => 10.],
            'vegetal' => ['min' => 50., 'max' => 300., 'step' => 10.], 'gordura' => ['min' => 3., 'max' => 20., 'step' => 1.],
            default => ['min' => 25., 'max' => 250., 'step' => 5.],
        };
    }

    /** @return list<string> */
    private function aliases(string $description): array
    {
        $main = trim(explode(',', $description)[0]);
        return array_values(array_unique(array_filter([$description, $main])));
    }

    private function preparation(string $name, string $family): string
    {
        foreach (['cozido', 'grelhado', 'assado', 'refogado', 'cru', 'frito', 'congelado'] as $value) if (str_contains($name, $value)) return $value;
        return in_array($family, ['carne', 'peixe', 'ovo', 'arroz', 'massa', 'tuberculo', 'leguminosa'], true) ? 'preparo_domestico' : 'pronto_para_consumo';
    }

    private function hasUnknownRestriction(array $restrictions): bool { return in_array('desconhecido', $restrictions, true); }
    private function normalize(string $value): string { return Str::lower(Str::ascii($value)); }
}
