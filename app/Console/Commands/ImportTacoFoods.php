<?php

namespace App\Console\Commands;

use App\Services\FoodCatalogService;
use Illuminate\Console\Command;

class ImportTacoFoods extends Command
{
    protected $signature = 'foods:import-taco';
    protected $description = 'Importa ou atualiza o catálogo global da TACO';

    public function handle(FoodCatalogService $catalog): int
    {
        $result = $catalog->importTaco();
        $this->info("TACO importada: {$result['created']} criados, {$result['updated']} atualizados, {$result['skipped']} ignorados.");
        return self::SUCCESS;
    }
}
