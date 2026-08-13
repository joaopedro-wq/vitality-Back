<?php

namespace Database\Seeders;

use App\Services\FoodCatalogService;
use Illuminate\Database\Seeder;

class TacoFoodSeeder extends Seeder
{
    /** Importação idempotente: pode rodar em todo deploy sem duplicar alimentos. */
    public function run(): void
    {
        app(FoodCatalogService::class)->importTaco();
    }
}
