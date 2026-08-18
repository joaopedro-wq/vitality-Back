<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tradução em-US do nome amigável, paralela a `nome_exibicao`/
     * `detalhe_exibicao` (pt-BR, coluna já existente). `grupo_exibicao`
     * continua canônico em pt-BR — a tradução de categoria é resolvida via
     * `config/food_group_labels.php`, não precisa de coluna própria.
     * Nulo até o backfill (`foods:generate-display-names --locale=en-US`);
     * o accessor cai pro pt-BR (que por sua vez cai pro técnico) enquanto
     * isso.
     */
    public function up(): void
    {
        Schema::table('alimentos', function (Blueprint $table) {
            $table->string('nome_exibicao_en')->nullable()->after('nome_exibicao_normalizado');
            $table->string('detalhe_exibicao_en')->nullable()->after('nome_exibicao_en');
            $table->string('nome_exibicao_en_normalizado')->nullable()->after('detalhe_exibicao_en');
            $table->index('nome_exibicao_en_normalizado');
        });
    }

    public function down(): void
    {
        Schema::table('alimentos', function (Blueprint $table) {
            $table->dropIndex(['nome_exibicao_en_normalizado']);
            $table->dropColumn(['nome_exibicao_en', 'detalhe_exibicao_en', 'nome_exibicao_en_normalizado']);
        });
    }
};
