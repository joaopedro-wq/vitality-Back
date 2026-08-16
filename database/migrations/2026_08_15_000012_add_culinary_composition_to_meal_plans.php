<?php

use App\Services\FoodPlanClassificationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('food_plan_tags')->upsert(collect([
            'cafe_base' => 'Base para café da manhã', 'cafe_proteina' => 'Proteína para café da manhã',
            'lanche_pratico' => 'Opção de lanche', 'fruta_lanche' => 'Fruta para lanche',
            'prato_proteina' => 'Proteína do prato', 'prato_base' => 'Base do prato',
            'prato_leguminosa' => 'Leguminosa do prato', 'prato_vegetal' => 'Vegetal do prato', 'acompanhamento' => 'Acompanhamento',
        ])->map(fn ($label, $slug) => ['slug' => $slug, 'label' => $label, 'created_at' => $now, 'updated_at' => $now])->all(), ['slug'], ['label', 'updated_at']);
        $ids = DB::table('food_plan_tags')->pluck('id', 'slug');
        $classifier = app(FoodPlanClassificationService::class);
        DB::table('alimentos')->select(['id', 'grupo', 'descricao', 'proteina', 'carbo', 'gordura'])->orderBy('id')->chunkById(100, function ($foods) use ($classifier, $ids) {
            $rows = [];
            foreach ($foods as $food) {
                foreach ($classifier->classify($food->grupo, $food->descricao, (float) $food->proteina, (float) $food->carbo, (float) $food->gordura) as $slug) {
                    if (isset($ids[$slug])) {
                        $rows[] = ['alimento_id' => $food->id, 'food_plan_tag_id' => $ids[$slug]];
                    }
                }
            }
            if ($rows) {
                DB::table('alimento_food_plan_tag')->insertOrIgnore($rows);
            }
        });
        Schema::table('meal_plan_drafts', function (Blueprint $table) {
            $table->json('previous_payload')->nullable()->after('payload');
        });
        Schema::table('meal_plan_items', function (Blueprint $table) {
            $table->string('culinary_role', 40)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('meal_plan_drafts', function (Blueprint $table) {
            $table->dropColumn('previous_payload');
        });
        Schema::table('meal_plan_items', function (Blueprint $table) {
            $table->dropColumn('culinary_role');
        });
    }
};
