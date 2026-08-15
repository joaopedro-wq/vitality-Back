<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plan_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('diet_type', 30)->default('onivora');
            $table->json('restriction_slugs')->nullable();
            $table->json('preferences')->nullable();
            $table->timestamps();
        });

        Schema::create('food_restrictions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('label', 100);
            $table->string('type', 20);
            $table->timestamps();
        });

        Schema::create('alimento_food_restriction', function (Blueprint $table) {
            $table->foreignId('alimento_id')->constrained('alimentos')->cascadeOnDelete();
            $table->foreignId('food_restriction_id')->constrained()->cascadeOnDelete();
            $table->primary(['alimento_id', 'food_restriction_id']);
        });

        DB::table('food_restrictions')->insert(collect([
            ['vegetariano', 'Adequado para vegetariano', 'diet'],
            ['vegano', 'Adequado para vegano', 'diet'],
            ['sem_lactose', 'Sem lactose', 'intolerance'],
            ['sem_gluten', 'Sem glúten', 'intolerance'],
            ['sem_ovo', 'Sem ovo', 'allergy'],
            ['sem_amendoim', 'Sem amendoim', 'allergy'],
            ['sem_crustaceos', 'Sem crustáceos', 'allergy'],
        ])->map(fn (array $restriction) => [
            'slug' => $restriction[0], 'label' => $restriction[1], 'type' => $restriction[2],
            'created_at' => now(), 'updated_at' => now(),
        ])->all());

        Schema::create('meal_plan_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30)->default('gemini');
            $table->string('model', 100)->nullable();
            $table->json('preferences');
            $table->json('payload');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        Schema::table('meal_plans', function (Blueprint $table) {
            $table->string('generation_provider', 30)->default('rules')->after('style');
            $table->string('generation_model', 100)->nullable()->after('generation_provider');
            $table->string('generation_version', 30)->nullable()->after('generation_model');
        });
    }

    public function down(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->dropColumn(['generation_provider', 'generation_model', 'generation_version']);
        });
        Schema::dropIfExists('meal_plan_drafts');
        Schema::dropIfExists('alimento_food_restriction');
        Schema::dropIfExists('food_restrictions');
        Schema::dropIfExists('meal_plan_profiles');
    }
};
