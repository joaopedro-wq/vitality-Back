<?php

namespace App\Services;

use App\Models\Alimento;
use App\Models\FoodAlias;
use App\Models\FoodCatalogVersion;
use App\Models\FoodPlanningProfile;
use App\Models\FoodPlanTag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TacoCatalogImporter
{
    public function __construct(
        private readonly TacoSpreadsheetReader $reader,
        private readonly TacoFoodProfileClassifier $classifier,
        private readonly FoodCatalogService $catalog,
    ) {}

    /** @return array<string,mixed> */
    public function preview(string $path): array
    {
        $foods = $this->reader->foods($path);
        $profiles = collect($foods)->map(fn (array $food) => $this->classifier->classify($food['group'], $food['description'], $food['protein'], $food['carbs'], $food['fat']));
        return ['foods' => count($foods), 'groups' => collect($foods)->pluck('group')->countBy()->sortKeys()->all(), 'families' => $profiles->pluck('family')->countBy()->sortKeys()->all(), 'pending_review' => $profiles->where('review_status', 'pendente')->count(), 'role_coverage' => $profiles->flatMap(fn (array $profile) => $profile['tags'])->countBy()->sortKeys()->all(), 'checksum' => hash_file('sha256', $path)];
    }

    public function import(string $path, bool $activate = false): FoodCatalogVersion
    {
        $foods = $this->reader->foods($path);
        $preview = $this->preview($path);
        return DB::transaction(function () use ($foods, $preview, $activate) {
            $version = FoodCatalogVersion::firstOrCreate(['source' => 'taco', 'version' => '4a-edicao', 'checksum' => $preview['checksum']], ['status' => 'staging', 'summary' => $preview]);
            $tagIds = FoodPlanTag::query()->pluck('id', 'slug');
            foreach ($foods as $foodData) {
                $profile = $this->classifier->classify($foodData['group'], $foodData['description'], $foodData['protein'], $foodData['carbs'], $foodData['fat']);
                [$display, $detail] = $this->displayName($foodData['description']);
                $food = Alimento::updateOrCreate(['fonte' => 'taco_4', 'source_reference' => $foodData['reference']], ['catalog_version_id' => $version->id, 'descricao' => $foodData['description'], 'nome_exibicao' => $display, 'detalhe_exibicao' => $detail, 'nome_normalizado' => $this->normalized($foodData['description']), 'grupo' => $foodData['group'], 'grupo_normalizado' => $this->catalog->normalizeGroup($foodData['group']), 'grupo_exibicao' => $this->catalog->normalizeGroupDisplay($foodData['group']), 'qtd' => 100, 'proteina' => $foodData['protein'], 'gordura' => $foodData['fat'], 'caloria' => $foodData['calories'], 'carbo' => $foodData['carbs'], 'status' => 'staging', 'source_version' => '4a-edicao', 'source_checksum' => $preview['checksum']]);
                FoodPlanningProfile::updateOrCreate(['alimento_id' => $food->id], ['family' => $profile['family'], 'consumption_form' => $profile['consumption_form'], 'preparation' => $profile['preparation'], 'direct_consumption' => $profile['direct_consumption'], 'support_ingredient' => $profile['support_ingredient'], 'portion_min_g' => $profile['portion']['min'], 'portion_max_g' => $profile['portion']['max'], 'portion_step_g' => $profile['portion']['step'], 'diet_compatibility' => $profile['diets'], 'restriction_compatibility' => $profile['restrictions'], 'confidence' => $profile['confidence'], 'review_status' => $profile['review_status']]);
                $food->planTags()->sync(collect($profile['tags'])->map(fn ($slug) => $tagIds[$slug] ?? null)->filter()->values());
                FoodAlias::query()->where('alimento_id', $food->id)->delete();
                foreach ($profile['aliases'] as $alias) FoodAlias::create(['alimento_id' => $food->id, 'alias' => $alias, 'normalized' => $this->normalized($alias)]);
            }
            $version->update(['summary' => $preview]);
            if ($activate) $this->activate($version);
            return $version->fresh();
        });
    }

    public function activate(FoodCatalogVersion $version): void
    {
        abort_unless($version->source === 'taco', 422, 'Somente uma versão TACO pode ser ativada por este fluxo.');
        Alimento::query()->whereNull('id_usuario')->where(function ($query) use ($version) {
            $query->whereNull('catalog_version_id')->orWhere('catalog_version_id', '!=', $version->id);
        })->update(['status' => 'legacy']);
        Alimento::query()->where('catalog_version_id', $version->id)->update(['status' => 'ativo']);
        FoodCatalogVersion::query()->where('id', '!=', $version->id)->where('status', 'active')->update(['status' => 'archived']);
        $version->update(['status' => 'active', 'activated_at' => now()]);
    }

    /** @return array{string,?string} */
    private function displayName(string $description): array
    {
        $parts = array_map('trim', explode(',', $description, 2));
        return [$parts[0], $parts[1] ?? null];
    }
    private function normalized(string $value): string { return Str::lower(Str::ascii($value)); }
}
