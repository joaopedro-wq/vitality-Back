<?php

namespace App\Console\Commands;

use App\Models\Alimento;
use App\Services\FoodCatalogService;
use Illuminate\Console\Command;

class NormalizeFoodGroups extends Command
{
    protected $signature = 'foods:normalize-groups
        {--dry-run : Apenas mostra a distribuicao, sem gravar}
        {--map=normalizado : "normalizado" (grupo_normalizado, coarse) ou "exibicao" (grupo_exibicao, granular)}';

    protected $description = 'Recalcula grupo_normalizado ou grupo_exibicao de todo o catalogo a partir dos mapas em config/food_groups*.php';

    public function handle(FoodCatalogService $catalog): int
    {
        $map = $this->option('map');
        if (! in_array($map, ['normalizado', 'exibicao'], true)) {
            $this->error('Opcao --map invalida. Use "normalizado" ou "exibicao".');

            return self::FAILURE;
        }
        $column = $map === 'exibicao' ? 'grupo_exibicao' : 'grupo_normalizado';

        $counts = [];
        $changed = 0;

        Alimento::query()->orderBy('id')->eachById(function (Alimento $food) use ($catalog, $map, $column, &$counts, &$changed): void {
            $normalizado = $map === 'exibicao' ? $catalog->normalizeGroupDisplay($food->grupo) : $catalog->normalizeGroup($food->grupo);
            $counts[$normalizado] = ($counts[$normalizado] ?? 0) + 1;

            if ($food->{$column} !== $normalizado) {
                $changed++;
                if (! $this->option('dry-run')) {
                    $food->update([$column => $normalizado]);
                }
            }
        });

        $this->table(['Categoria', 'Alimentos'], collect($counts)->sortKeys()->map(fn (int $total, string $categoria) => [$categoria, $total])->values()->all());
        $this->info($this->option('dry-run') ? "Simulacao ({$column}): {$changed} alimentos mudariam de categoria." : "Concluido ({$column}): {$changed} alimentos atualizados.");

        return self::SUCCESS;
    }
}
