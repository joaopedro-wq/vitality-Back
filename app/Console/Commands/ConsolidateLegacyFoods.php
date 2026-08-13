<?php

namespace App\Console\Commands;

use App\Services\FoodCatalogService;
use Illuminate\Console\Command;

class ConsolidateLegacyFoods extends Command
{
    protected $signature = 'foods:consolidate-legacy {--apply : Aplica as alterações; sem a opção apenas gera relatório}';
    protected $description = 'Consolida cópias legadas da TACO e marca itens pessoais para revisão';

    public function handle(FoodCatalogService $catalog): int
    {
        $result = $catalog->consolidateLegacy((bool) $this->option('apply'));
        $mode = $this->option('apply') ? 'aplicado' : 'simulado';
        $this->info("Relatório {$mode}: {$result['merged']} cópias TACO mescláveis; {$result['pending']} itens pessoais pendentes.");
        return self::SUCCESS;
    }
}
