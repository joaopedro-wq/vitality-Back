<?php

namespace App\Services;

use App\Models\Alimento;
use Illuminate\Support\Facades\DB;

class FoodCatalogService
{
    public function normalizeName(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
        return trim((string) preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/i', ' ', mb_strtolower($ascii))));
    }

    /**
     * Normaliza o `grupo` bruto (texto livre, TACO em português ou USDA em
     * inglês) num rótulo curto usado nos filtros do Diário. Ver
     * `config/food_groups.php` pro mapa; sem match cai em "Outros" — nunca
     * fica sem categoria.
     */
    public function normalizeGroup(?string $grupo): string
    {
        if ($grupo === null || $grupo === '') return 'Outros';

        foreach (config('food_groups', []) as $normalizado => $brutos) {
            if (in_array($grupo, $brutos, true)) return $normalizado;
        }

        return 'Outros';
    }

    /** @return array{created:int,updated:int,skipped:int} */
    public function importTaco(): array
    {
        $path = base_path('taco.json');
        if (! file_exists($path)) throw new \RuntimeException('Arquivo taco.json não encontrado.');
        $items = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $created = $updated = $skipped = 0;

        foreach ($items as $item) {
            $reference = trim((string) ($item['Número'] ?? ''));
            $description = trim((string) ($item['Descrição do Alimento'] ?? ''));
            if ($reference === '' || $description === '') { $skipped++; continue; }
            $grupo = trim((string) ($item['Grupo'] ?? '')) ?: null;
            $values = [
                'descricao' => $description,
                'nome_normalizado' => $this->normalizeName($description),
                'grupo' => $grupo,
                'grupo_normalizado' => $this->normalizeGroup($grupo),
                'proteina' => (float) ($item['Proteína(g)'] ?? 0),
                'gordura' => (float) ($item['Lipídeos(g)'] ?? 0),
                'caloria' => (float) ($item['Energia(kcal)'] ?? 0),
                'carbo' => (float) ($item['Carboidrato(g)'] ?? 0),
                'qtd' => 100,
                'status' => 'ativo',
            ];
            $food = Alimento::where('fonte', 'taco')->where('source_reference', $reference)->first();
            if ($food) { $food->update($values); $updated++; }
            else { Alimento::create($values + ['fonte' => 'taco', 'source_reference' => $reference, 'id_usuario' => null]); $created++; }
        }
        return compact('created', 'updated', 'skipped');
    }

    /** @return array{merged:int,pending:int} */
    public function consolidateLegacy(bool $apply): array
    {
        $this->importTaco();
        $merged = $pending = 0;
        $legacyFoods = Alimento::whereNotNull('id_usuario')->get();
        foreach ($legacyFoods as $legacy) {
            $normal = $this->normalizeName($legacy->descricao);
            $canonical = Alimento::where('fonte', 'taco')->where('nome_normalizado', $normal)
                ->where('qtd', $legacy->qtd)->where('proteina', $legacy->proteina)->where('gordura', $legacy->gordura)
                ->where('carbo', $legacy->carbo)->where('caloria', $legacy->caloria)->first();
            if ($canonical) {
                $merged++;
                if ($apply) {
                    DB::transaction(function () use ($legacy, $canonical): void {
                        DB::table('dieta_alimentos')->where('alimento_id', $legacy->id)->update(['alimento_id' => $canonical->id]);
                        DB::table('registro_alimentos')->where('alimento_id', $legacy->id)->update(['alimento_id' => $canonical->id]);
                        $legacy->delete();
                    });
                }
            } else {
                $pending++;
                if ($apply) $legacy->update([
                    'fonte' => 'legado', 'status' => 'pendente', 'nome_normalizado' => $normal,
                    'created_by' => $legacy->id_usuario, 'id_usuario' => null,
                ]);
            }
        }
        return compact('merged', 'pending');
    }
}
