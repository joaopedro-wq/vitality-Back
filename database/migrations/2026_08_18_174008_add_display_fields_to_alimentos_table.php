<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Campos de apresentação amigável, separados do dado técnico original
     * (`descricao`, `grupo`, `grupo_normalizado`, `nome_normalizado`), que
     * permanecem intocados para rastreabilidade, matching interno e
     * snapshots nutricionais. Nulos até o backfill (ver
     * `foods:generate-display-names` / `foods:apply-display-names`); o
     * accessor `Alimento::getNomeExibicaoAttribute()` cai para `descricao`
     * enquanto isso.
     */
    public function up(): void
    {
        Schema::table('alimentos', function (Blueprint $table) {
            $table->string('nome_exibicao')->nullable()->after('descricao');
            $table->string('detalhe_exibicao')->nullable()->after('nome_exibicao');
            $table->string('nome_exibicao_normalizado')->nullable()->after('detalhe_exibicao');
            $table->string('grupo_exibicao', 60)->nullable()->after('grupo_normalizado');
            $table->index('nome_exibicao_normalizado');
            $table->index('grupo_exibicao');
        });
    }

    public function down(): void
    {
        Schema::table('alimentos', function (Blueprint $table) {
            $table->dropIndex(['nome_exibicao_normalizado']);
            $table->dropIndex(['grupo_exibicao']);
            $table->dropColumn(['nome_exibicao', 'detalhe_exibicao', 'nome_exibicao_normalizado', 'grupo_exibicao']);
        });
    }
};
