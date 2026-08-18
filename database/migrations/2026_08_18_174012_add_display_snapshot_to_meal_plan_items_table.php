<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Mesmo padrão de `descricao_snapshot`: congela o nome amigável usado no momento em que o plano foi salvo. */
    public function up(): void
    {
        Schema::table('meal_plan_items', function (Blueprint $table) {
            $table->string('nome_exibicao_snapshot')->nullable()->after('descricao_snapshot');
            $table->string('detalhe_exibicao_snapshot')->nullable()->after('nome_exibicao_snapshot');
        });

        DB::statement(<<<'SQL'
            UPDATE meal_plan_items AS item
            SET nome_exibicao_snapshot = COALESCE(alimento.nome_exibicao, alimento.descricao),
                detalhe_exibicao_snapshot = alimento.detalhe_exibicao
            FROM alimentos AS alimento
            WHERE item.food_id = alimento.id
        SQL);
    }

    public function down(): void
    {
        Schema::table('meal_plan_items', function (Blueprint $table) {
            $table->dropColumn(['nome_exibicao_snapshot', 'detalhe_exibicao_snapshot']);
        });
    }
};
