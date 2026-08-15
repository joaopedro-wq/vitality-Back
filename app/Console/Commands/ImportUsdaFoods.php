<?php

namespace App\Console\Commands;

use App\Services\UsdaFoodImportService;
use Illuminate\Console\Command;

class ImportUsdaFoods extends Command
{
    protected $signature = 'foods:import-usda {--dataset=foundation : foundation ou sr-legacy} {--file= : Caminho absoluto do JSON USDA} {--release= : Versão da fonte}';

    protected $description = 'Importa Foundation Foods ou SR Legacy da USDA FoodData Central';

    public function handle(UsdaFoodImportService $importer): int
    {
        $dataset = (string) $this->option('dataset');
        if (! in_array($dataset, ['foundation', 'sr-legacy'], true)) {
            $this->error('dataset deve ser foundation ou sr-legacy.');

            return self::FAILURE;
        }
        $defaults = [
            'foundation' => [storage_path('app/imports/usda/foundation-2026-04-30/FoodData_Central_foundation_food_json_2026-04-30.json'), '2026-04'],
            'sr-legacy' => [storage_path('app/imports/usda/sr-legacy-2018-04/FoodData_Central_sr_legacy_food_json_2018-04.json'), '2018-04'],
        ];
        [$defaultPath, $defaultVersion] = $defaults[$dataset];
        $result = $importer->import((string) ($this->option('file') ?: $defaultPath), $dataset, (string) ($this->option('release') ?: $defaultVersion));
        $this->info("USDA {$dataset}: {$result['created']} criados, {$result['updated']} atualizados, {$result['nutrients']} nutrientes sincronizados.");

        return self::SUCCESS;
    }
}
