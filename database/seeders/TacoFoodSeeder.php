<?php

namespace Database\Seeders;

use App\Models\FoodCatalogVersion;
use App\Services\FoodCatalogService;
use Illuminate\Database\Seeder;

class TacoFoodSeeder extends Seeder
{
    /** Importação idempotente: pode rodar em todo deploy sem duplicar alimentos. */
    public function run(): void
    {
        // A versão TACO 4 é importada e ativada pelo fluxo administrativo.
        // Nunca reative o catálogo legado no boot quando ela já existir.
        if (FoodCatalogVersion::query()->where('source', 'taco')->where('status', 'active')->exists()) {
            return;
        }

        app(FoodCatalogService::class)->importTaco();
    }
}
