<?php

use App\Services\FoodCatalogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $activeVersionId = DB::table('food_catalog_versions')
            ->where('source', 'taco')
            ->where('status', 'active')
            ->value('id');

        if (! $activeVersionId) {
            return;
        }

        // Corrige as categorias exibidas dos alimentos TACO 4 já importados.
        $catalog = app(FoodCatalogService::class);
        DB::table('alimentos')
            ->where('fonte', 'taco_4')
            ->orderBy('id')
            ->eachById(function (object $food) use ($catalog): void {
                DB::table('alimentos')->where('id', $food->id)->update([
                    'grupo_normalizado' => $catalog->normalizeGroup($food->grupo),
                    'grupo_exibicao' => $catalog->normalizeGroupDisplay($food->grupo),
                ]);
            }, 100, 'id');

        // Desativa o catálogo global anterior. Sem isso, o seed legado executado
        // no boot deixa TACO antiga e TACO 4 visíveis ao mesmo tempo.
        DB::table('alimentos')
            ->whereNull('id_usuario')
            ->where(function ($query) use ($activeVersionId): void {
                $query->whereNull('catalog_version_id')->orWhere('catalog_version_id', '!=', $activeVersionId);
            })
            ->update(['status' => 'legacy', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Não reativa catálogos antigos automaticamente: isso poderia restaurar
        // versões obsoletas e recriar duplicidades.
    }
};
