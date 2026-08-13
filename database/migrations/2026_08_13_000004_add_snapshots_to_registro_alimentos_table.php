<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registro_alimentos', function (Blueprint $table) {
            $table->string('descricao_snapshot')->nullable()->after('qtd');
            $table->decimal('qtd_base_snapshot', 10, 3)->nullable()->after('descricao_snapshot');
            $table->decimal('proteina_snapshot', 10, 3)->nullable()->after('qtd_base_snapshot');
            $table->decimal('gordura_snapshot', 10, 3)->nullable()->after('proteina_snapshot');
            $table->decimal('carbo_snapshot', 10, 3)->nullable()->after('gordura_snapshot');
            $table->decimal('caloria_snapshot', 10, 3)->nullable()->after('carbo_snapshot');
        });

        // PostgreSQL exige `FROM` para referenciar a tabela de origem no UPDATE.
        DB::statement(<<<'SQL'
            UPDATE registro_alimentos AS registro
            SET descricao_snapshot = alimento.descricao,
                qtd_base_snapshot = alimento.qtd,
                proteina_snapshot = alimento.proteina,
                gordura_snapshot = alimento.gordura,
                carbo_snapshot = alimento.carbo,
                caloria_snapshot = alimento.caloria
            FROM alimentos AS alimento
            WHERE registro.alimento_id = alimento.id
        SQL);
    }

    public function down(): void
    {
        Schema::table('registro_alimentos', function (Blueprint $table) {
            $table->dropColumn([
                'descricao_snapshot', 'qtd_base_snapshot', 'proteina_snapshot',
                'gordura_snapshot', 'carbo_snapshot', 'caloria_snapshot',
            ]);
        });
    }
};
