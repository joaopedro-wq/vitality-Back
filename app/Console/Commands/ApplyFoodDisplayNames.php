<?php

namespace App\Console\Commands;

use App\Models\Alimento;
use App\Services\FoodCatalogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Lê `database/data/food_display_names.json` (pt-BR) ou
 * `database/data/food_display_names_en-US.json` (`--locale=en-US`),
 * gerados e revisados a partir de `foods:generate-display-names`, e grava
 * nas colunas correspondentes do catálogo. Idempotente — upsert por id,
 * seguro rodar de novo depois de editar o JSON.
 */
class ApplyFoodDisplayNames extends Command
{
    protected $signature = 'foods:apply-display-names
        {--locale=pt-BR : pt-BR (nome_exibicao/detalhe_exibicao) ou en-US (nome_exibicao_en/detalhe_exibicao_en)}
        {--dry-run : Apenas mostra o que mudaria, sem gravar}';

    protected $description = 'Aplica database/data/food_display_names*.json nas colunas nome_exibicao/detalhe_exibicao do catálogo, no locale pedido';

    public function handle(FoodCatalogService $catalog): int
    {
        $locale = $this->option('locale');
        if (! in_array($locale, ['pt-BR', 'en-US'], true)) {
            $this->error('Opcao --locale invalida. Use "pt-BR" ou "en-US".');

            return self::FAILURE;
        }
        $sourcePath = $locale === 'en-US' ? 'database/data/food_display_names_en-US.json' : 'database/data/food_display_names.json';
        [$nomeCol, $detalheCol, $normalizadoCol] = $locale === 'en-US'
            ? ['nome_exibicao_en', 'detalhe_exibicao_en', 'nome_exibicao_en_normalizado']
            : ['nome_exibicao', 'detalhe_exibicao', 'nome_exibicao_normalizado'];

        $path = base_path($sourcePath);
        if (! File::exists($path)) {
            $this->error($sourcePath.' não encontrado. Rode foods:generate-display-names --locale='.$locale.' primeiro.');

            return self::FAILURE;
        }

        $entries = json_decode(File::get($path), true);
        if (! is_array($entries)) {
            $this->error('JSON inválido em '.$sourcePath);

            return self::FAILURE;
        }

        $updated = $skipped = 0;
        foreach ($entries as $id => $entry) {
            $food = Alimento::find((int) $id);
            $nome = trim((string) ($entry['nome_exibicao'] ?? ''));
            if (! $food || $nome === '') {
                $skipped++;

                continue;
            }
            $detalhe = trim((string) ($entry['detalhe_exibicao'] ?? '')) ?: null;
            $values = [
                $nomeCol => $nome,
                $detalheCol => $detalhe,
                $normalizadoCol => $catalog->normalizeName($nome),
            ];
            // Raw original, não o accessor: quando a coluna está vazia o
            // accessor cai pro fallback (pt-BR/técnico), e para os
            // alimentos cujo nome sugerido já é idêntico ao original isso
            // mascara a coluna ainda não gravada.
            if ($food->getRawOriginal($nomeCol) !== $nome || $food->getRawOriginal($detalheCol) !== $detalhe) {
                $updated++;
                if (! $this->option('dry-run')) {
                    $food->forceFill($values)->save();
                }
            }
        }

        $this->info($this->option('dry-run')
            ? "Simulação ({$locale}): {$updated} alimentos mudariam, {$skipped} entradas puladas (sem nome_exibicao ou id inexistente)."
            : "Concluído ({$locale}): {$updated} alimentos atualizados, {$skipped} entradas puladas.");

        return self::SUCCESS;
    }
}
