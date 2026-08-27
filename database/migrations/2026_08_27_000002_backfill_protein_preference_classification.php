<?php

use App\Services\TacoFoodProfileClassifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $classifier = app(TacoFoodProfileClassifier::class);
        $restrictionIds = DB::table('food_restrictions')
            ->whereIn('slug', ['sem_carne_vermelha', 'sem_aves', 'sem_frutos_do_mar'])
            ->pluck('id', 'slug');
        $slugByKey = [
            'carne_vermelha' => $restrictionIds['sem_carne_vermelha'] ?? null,
            'aves' => $restrictionIds['sem_aves'] ?? null,
            'fruto_do_mar' => $restrictionIds['sem_frutos_do_mar'] ?? null,
        ];

        DB::table('alimentos')
            ->select(['id', 'grupo', 'descricao', 'proteina', 'carbo', 'gordura'])
            ->orderBy('id')
            ->chunkById(200, function ($foods) use ($classifier, $slugByKey): void {
                $profiles = DB::table('food_planning_profiles')
                    ->whereIn('alimento_id', $foods->pluck('id'))
                    ->get(['id', 'alimento_id', 'restriction_compatibility'])
                    ->keyBy('alimento_id');
                $pivotRows = [];

                foreach ($foods as $food) {
                    $classification = $classifier->classify(
                        (string) $food->grupo,
                        (string) $food->descricao,
                        (float) $food->proteina,
                        (float) $food->carbo,
                        (float) $food->gordura,
                    );
                    $novasChaves = collect($classification['restrictions'])
                        ->only(['carne_vermelha', 'aves', 'fruto_do_mar'])
                        ->all();

                    $profile = $profiles->get($food->id);
                    if ($profile) {
                        $atual = json_decode((string) $profile->restriction_compatibility, true) ?? [];
                        DB::table('food_planning_profiles')
                            ->where('id', $profile->id)
                            ->update(['restriction_compatibility' => json_encode([...$atual, ...$novasChaves])]);

                        continue;
                    }

                    // Sem profile (ex.: importado via USDA): a restrição vira uma linha na pivot
                    // só quando o alimento SATISFAZ ela ('compativel') — é assim que o outro
                    // caminho de MealPlanFeasibilityService::supportsPreferences() já funciona
                    // pras 5 restrições antigas (vegetariano, sem_lactose etc.).
                    foreach ($novasChaves as $chave => $valor) {
                        $restrictionId = $slugByKey[$chave] ?? null;
                        if ($restrictionId && $valor === 'compativel') {
                            $pivotRows[] = ['alimento_id' => $food->id, 'food_restriction_id' => $restrictionId];
                        }
                    }
                }

                if ($pivotRows) {
                    DB::table('alimento_food_restriction')->insertOrIgnore($pivotRows);
                }
            });
    }

    public function down(): void
    {
        // Reverter classificação não é seguro (o restriction_compatibility pode ter sido tocado
        // por reimportações depois) — down intencionalmente vazio, como as migrations de
        // classificação irmãs (2026_08_22_000013_classify_imported_food_plan_tags.php).
    }
};
