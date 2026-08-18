<?php

namespace App\Console\Commands;

use App\Models\Alimento;
use App\Services\FoodCatalogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;


class ApplyFoodDisplayNames extends Command
{
    protected $signature = 'foods:apply-display-names {--dry-run : Apenas mostra o que mudaria, sem gravar}';

    protected $description = 'Aplica database/data/food_display_names.json nas colunas nome_exibicao/detalhe_exibicao do catálogo';

    private const SOURCE_PATH = 'database/data/food_display_names.json';

    public function handle(FoodCatalogService $catalog): int
    {
        $path = base_path(self::SOURCE_PATH);
        if (! File::exists($path)) {
            $this->error(self::SOURCE_PATH.' não encontrado. Rode foods:generate-display-names primeiro.');

            return self::FAILURE;
        }

        $entries = json_decode(File::get($path), true);
        if (! is_array($entries)) {
            $this->error('JSON inválido em '.self::SOURCE_PATH);

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
                'nome_exibicao' => $nome,
                'detalhe_exibicao' => $detalhe,
                'nome_exibicao_normalizado' => $catalog->normalizeName($nome),
            ];
            // Raw original, não o accessor: quando a coluna está vazia o
            // accessor cai pra `descricao`, e para os alimentos cujo nome
            // sugerido já é idêntico ao original isso mascara a coluna
            // ainda não gravada.
            if ($food->getRawOriginal('nome_exibicao') !== $nome || $food->getRawOriginal('detalhe_exibicao') !== $detalhe) {
                $updated++;
                if (! $this->option('dry-run')) {
                    $food->forceFill($values)->save();
                }
            }
        }

        $this->info($this->option('dry-run')
            ? "Simulação: {$updated} alimentos mudariam, {$skipped} entradas puladas (sem nome_exibicao ou id inexistente)."
            : "Concluído: {$updated} alimentos atualizados, {$skipped} entradas puladas.");

        return self::SUCCESS;
    }
}
