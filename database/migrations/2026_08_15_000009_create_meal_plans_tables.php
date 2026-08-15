<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('titulo', 120);
            $table->string('style', 20);
            $table->unsignedTinyInteger('meal_count');
            $table->json('preferences');
            $table->json('target');
            $table->json('totals');
            $table->text('warning')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('meal_plan_meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('descricao', 80);
            $table->time('horario');
            $table->json('target');
            $table->json('totals');
            $table->timestamps();
            $table->unique(['meal_plan_id', 'position']);
        });

        Schema::create('meal_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_plan_meal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_id')->constrained('alimentos')->restrictOnDelete();
            $table->string('descricao_snapshot');
            $table->decimal('quantity', 10, 3);
            $table->json('macros');
            $table->timestamps();
        });

        Schema::create('food_plan_tags', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('label', 80);
            $table->timestamps();
        });

        Schema::create('alimento_food_plan_tag', function (Blueprint $table) {
            $table->foreignId('alimento_id')->constrained('alimentos')->cascadeOnDelete();
            $table->foreignId('food_plan_tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['alimento_id', 'food_plan_tag_id']);
        });

        $now = now();
        DB::table('food_plan_tags')->insert(collect([
            'proteina' => 'Fonte de proteína', 'carboidrato' => 'Fonte de carboidrato',
            'leguminosa' => 'Leguminosa', 'vegetal' => 'Vegetal', 'fruta' => 'Fruta',
            'laticinio' => 'Laticínio', 'gordura' => 'Fonte de gordura',
            'rapido' => 'Preparo rápido', 'caseiro' => 'Caseiro', 'economico' => 'Econômico',
        ])->map(fn ($label, $slug) => ['slug' => $slug, 'label' => $label, 'created_at' => $now, 'updated_at' => $now])->values()->all());
    }

    public function down(): void
    {
        Schema::dropIfExists('alimento_food_plan_tag');
        Schema::dropIfExists('food_plan_tags');
        Schema::dropIfExists('meal_plan_items');
        Schema::dropIfExists('meal_plan_meals');
        Schema::dropIfExists('meal_plans');
    }
};
