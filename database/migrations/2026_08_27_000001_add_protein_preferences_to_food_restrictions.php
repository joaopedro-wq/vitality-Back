<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Chips opcionais de preferência de proteína no quiz de Dietas (só exibidos quando o padrão
 * alimentar é "onívora"). `type: 'preference'` é um valor novo na coluna livre `type` (string(20),
 * sem enum no banco) — separa semanticamente de `diet`/`intolerance`/`allergy` e é o que o front
 * usa pra agrupar visualmente esses 3 chips longe dos de intolerância/alergia.
 *
 * A exclusão em si reaproveita 100% o mecanismo de `restriction_slugs` já existente
 * (MealPlanFeasibilityService::supportsPreferences) — só precisa das linhas em `food_restrictions`
 * e da classificação por alimento, feita na migration seguinte.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('food_restrictions')->insert(collect([
            ['sem_carne_vermelha', 'Sem carne vermelha', 'preference'],
            ['sem_aves', 'Sem aves', 'preference'],
            ['sem_frutos_do_mar', 'Sem peixes e frutos do mar', 'preference'],
        ])->map(fn (array $restriction) => [
            'slug' => $restriction[0], 'label' => $restriction[1], 'type' => $restriction[2],
            'created_at' => $now, 'updated_at' => $now,
        ])->all());
    }

    public function down(): void
    {
        DB::table('food_restrictions')->whereIn('slug', ['sem_carne_vermelha', 'sem_aves', 'sem_frutos_do_mar'])->delete();
    }
};
