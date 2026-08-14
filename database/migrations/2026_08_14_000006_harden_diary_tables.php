<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refeicaos', function (Blueprint $table) {
            $table->string('chave_padrao', 40)->nullable();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->unique(['id_usuario', 'chave_padrao'], 'refeicaos_usuario_chave_unique');
            $table->index(['id_usuario', 'archived_at', 'ordem'], 'refeicaos_usuario_ativos_index');
        });

        Schema::table('registros', function (Blueprint $table) {
            $table->timestampTz('consumed_at')->nullable();
            $table->string('descricao_refeicao_snapshot')->nullable();
            $table->time('horario_refeicao_snapshot')->nullable();
            $table->index(['id_usuario', 'data'], 'registros_usuario_data_index');
            $table->index(['id_usuario', 'id_refeicao', 'consumed_at'], 'registros_usuario_refeicao_consumed_index');
        });

        Schema::table('registro_alimentos', function (Blueprint $table) {
            $table->json('nutrientes_snapshot')->nullable();
            $table->decimal('qtd', 12, 3)->change();
        });

        $this->backfillMealKeysAndEntries();
        $this->mergeDuplicateEntryItems();

        Schema::table('registro_alimentos', function (Blueprint $table) {
            $table->unique(['registro_id', 'alimento_id'], 'registro_alimentos_registro_alimento_unique');
        });
    }

    public function down(): void
    {
        Schema::table('registro_alimentos', function (Blueprint $table) {
            $table->dropUnique('registro_alimentos_registro_alimento_unique');
            $table->float('qtd')->change();
            $table->dropColumn('nutrientes_snapshot');
        });

        Schema::table('registros', function (Blueprint $table) {
            $table->dropIndex('registros_usuario_data_index');
            $table->dropIndex('registros_usuario_refeicao_consumed_index');
            $table->dropColumn(['consumed_at', 'descricao_refeicao_snapshot', 'horario_refeicao_snapshot']);
        });

        Schema::table('refeicaos', function (Blueprint $table) {
            $table->dropIndex('refeicaos_usuario_ativos_index');
            $table->dropUnique('refeicaos_usuario_chave_unique');
            $table->dropColumn(['chave_padrao', 'ordem', 'archived_at']);
        });
    }

    private function backfillMealKeysAndEntries(): void
    {
        $defaults = [
            'cafe_da_manha' => ['Café da manhã', '08:00:00', 1],
            'almoco' => ['Almoço', '11:30:00', 2],
            'lanche_da_tarde' => ['Lanche da tarde', '16:00:00', 3],
            'jantar' => ['Jantar', '20:00:00', 4],
            'ceia' => ['Ceia', '22:00:00', 5],
        ];

        DB::table('refeicaos')->orderBy('id')->each(function (object $meal) use ($defaults) {
            $normalized = mb_strtolower(trim($meal->descricao));
            foreach ($defaults as $key => [$description, $time, $position]) {
                if ($normalized === mb_strtolower($description)) {
                    DB::table('refeicaos')->where('id', $meal->id)->update([
                        'chave_padrao' => $key,
                        'ordem' => $position,
                    ]);
                    return;
                }
            }
        });

        $nutrientCache = [];
        DB::table('registros')->orderBy('id')->each(function (object $entry) use (&$nutrientCache) {
            $meal = DB::table('refeicaos')->where('id', $entry->id_refeicao)->first();
            $time = $meal?->horario ?? '12:00:00';
            $local = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $entry->data.' '.$time, 'America/Sao_Paulo');
            DB::table('registros')->where('id', $entry->id)->update([
                'consumed_at' => $local->utc(),
                'descricao_refeicao_snapshot' => $meal?->descricao,
                'horario_refeicao_snapshot' => $meal?->horario,
            ]);

            DB::table('registro_alimentos')->where('registro_id', $entry->id)->orderBy('id')->each(function (object $item) use (&$nutrientCache) {
                if (! array_key_exists($item->alimento_id, $nutrientCache)) {
                    $nutrientCache[$item->alimento_id] = DB::table('alimento_nutrientes')
                        ->join('nutrientes', 'nutrientes.id', '=', 'alimento_nutrientes.nutriente_id')
                        ->where('alimento_nutrientes.alimento_id', $item->alimento_id)
                        ->orderBy('nutrientes.codigo')
                        ->get(['nutrientes.codigo', 'nutrientes.nome', 'nutrientes.unidade', 'alimento_nutrientes.valor'])
                        ->map(fn (object $nutrient) => [
                            'codigo' => $nutrient->codigo,
                            'nome' => $nutrient->nome,
                            'unidade' => $nutrient->unidade,
                            'valor' => (float) $nutrient->valor,
                        ])->all();
                }

                DB::table('registro_alimentos')->where('id', $item->id)->update([
                    'nutrientes_snapshot' => json_encode($nutrientCache[$item->alimento_id]),
                ]);
            });
        });
    }

    private function mergeDuplicateEntryItems(): void
    {
        DB::table('registro_alimentos')->orderBy('registro_id')->orderBy('alimento_id')->get()
            ->groupBy(fn (object $item) => $item->registro_id.'-'.$item->alimento_id)
            ->each(function ($items) {
                if ($items->count() < 2) {
                    return;
                }

                $first = $items->first();
                DB::table('registro_alimentos')->where('id', $first->id)->update([
                    'qtd' => $items->sum(fn (object $item) => (float) $item->qtd),
                ]);
                DB::table('registro_alimentos')->whereIn('id', $items->skip(1)->pluck('id'))->delete();
            });
    }
};
