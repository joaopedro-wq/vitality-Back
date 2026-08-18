<?php

namespace App\Console\Commands;

use App\Models\Alimento;
use App\Services\FoodCatalogService;
use Illuminate\Console\Command;

class NormalizeFoodGroups extends Command
{
    protected $signature = 'foods:normalize-groups {--dry-run : Apenas mostra a distribuicao, sem gravar}';

    protected $description = 'Recalcula grupo_normalizado de todo o catalogo a partir do mapa em config/food_groups.php';

    public function handle(FoodCatalogService $catalog): int
    {
        $counts = [];
        $changed = 0;

        Alimento::query()->orderBy('id')->eachById(function (Alimento $food) use ($catalog, &$counts, &$changed): void {
            $normalizado = $catalog->normalizeGroup($food->grupo);
            $counts[$normalizado] = ($counts[$normalizado] ?? 0) + 1;

            if ($food->grupo_normalizado !== $normalizado) {
                $changed++;
                if (! $this->option('dry-run')) {
                    $food->update(['grupo_normalizado' => $normalizado]);
                }
            }
        });

        $this->table(['Categoria', 'Alimentos'], collect($counts)->sortKeys()->map(fn (int $total, string $categoria) => [$categoria, $total])->values()->all());
        $this->info($this->option('dry-run') ? "Simulacao: {$changed} alimentos mudariam de categoria." : "Concluido: {$changed} alimentos atualizados.");

        return self::SUCCESS;
    }
}
