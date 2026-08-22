<?php

use App\Services\FoodPlanClassificationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tagIds = DB::table('food_plan_tags')->pluck('id', 'slug');
        $classifier = app(FoodPlanClassificationService::class);

        DB::table('alimentos')
            ->select(['id', 'grupo', 'descricao', 'proteina', 'carbo', 'gordura'])
            ->orderBy('id')
            ->chunkById(100, function ($foods) use ($classifier, $tagIds): void {
                $rows = [];

                foreach ($foods as $food) {
                    foreach ($classifier->classify($food->grupo, $food->descricao, (float) $food->proteina, (float) $food->carbo, (float) $food->gordura) as $slug) {
                        if (isset($tagIds[$slug])) {
                            $rows[] = ['alimento_id' => $food->id, 'food_plan_tag_id' => $tagIds[$slug]];
                        }
                    }
                }

                if ($rows) {
                    DB::table('alimento_food_plan_tag')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void {}
};
