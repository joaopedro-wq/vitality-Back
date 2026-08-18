<?php

namespace App\Services;

use App\Models\Alimento;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FoodCatalogService
{
    public function normalizeName(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;

        return trim((string) preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/i', ' ', mb_strtolower($ascii))));
    }

    /**
     * Categorias amigáveis no locale ativo (`app()->getLocale()`), pra
     * exibição. `id` (slug) é sempre derivado do rótulo canônico (pt-BR,
     * o que está gravado em `grupo_exibicao`) — estável e independente do
     * idioma. `label` é traduzido via `translatedGroupLabel()`; sem
     * tradução cadastrada cai pro canônico, nunca fica vazio.
     */
    public function displayGroups(): Collection
    {
        return $this->canonicalGroups()->map(fn (array $group) => [
            'id' => $group['id'],
            'label' => $this->translatedGroupLabel($group['label']),
            'total' => $group['total'],
        ])->values();
    }

    /**
     * Resolve o slug estável de volta pro rótulo canônico (pt-BR) —
     * sempre pt-BR, porque é o que está de fato gravado em `grupo_exibicao`
     * no banco. Uso interno (filtro `categoria[]`), nunca pra exibição.
     */
    public function displayGroupLabelForSlug(string $slug): ?string
    {
        return $this->canonicalGroups()->firstWhere('id', $slug)['label'] ?? null;
    }

    /** Traduz um `grupo_exibicao` canônico (pt-BR) pro locale ativo. Fallback seguro: sem tradução, retorna o canônico — nunca vazio. */
    public function translatedGroupLabel(?string $grupoExibicao, ?string $fallback = null): string
    {
        $canonical = $grupoExibicao ?: ($fallback ?: 'Outros');
        $id = Str::slug($canonical);
        $locale = app()->getLocale();

        return config("food_group_labels.{$id}.{$locale}") ?? $canonical;
    }

    /**
     * Consulta via query builder (`DB::table`), não Eloquent — `grupo_exibicao`
     * tem accessor locale-aware no model (`Alimento::getGrupoExibicaoAttribute()`);
     * ler por `Alimento::query()` aqui devolveria o rótulo já traduzido pro
     * locale ativo em vez do canônico, quebrando a estabilidade do `id`.
     *
     * @return Collection<int, array{id: string, label: string, total: int}>
     */
    private function canonicalGroups(): Collection
    {
        return DB::table('alimentos')
            ->where('status', 'ativo')
            ->whereNotNull('grupo_exibicao')
            ->select('grupo_exibicao')
            ->selectRaw('count(*) as total')
            ->groupBy('grupo_exibicao')
            ->orderBy('grupo_exibicao')
            ->get()
            ->map(fn ($row) => [
                'id' => Str::slug($row->grupo_exibicao),
                'label' => $row->grupo_exibicao,
                'total' => (int) $row->total,
            ])
            ->values();
    }

    /**
     * Normaliza o `grupo` bruto (texto livre, TACO em português ou USDA em
     * inglês) num rótulo curto usado nos filtros do Diário. Ver
     * `config/food_groups.php` pro mapa; sem match cai em "Outros" — nunca
     * fica sem categoria.
     *
     * @param  string  $configKey  Nome do arquivo de config com o mapa
     *                             categoria => lista de `grupo` brutos.
     *                             `food_groups_display` gera o rótulo mais
     *                             granular usado em `grupo_exibicao`.
     */
    public function normalizeGroup(?string $grupo, string $configKey = 'food_groups'): string
    {
        if ($grupo === null || $grupo === '') {
            return 'Outros';
        }

        foreach (config($configKey, []) as $normalizado => $brutos) {
            if (in_array($grupo, $brutos, true)) {
                return $normalizado;
            }
        }

        return 'Outros';
    }

    /**
     * Versão amigável do `grupo`, mais granular que `normalizeGroup()`. Ver
     * `config/food_groups_display.php`.
     */
    public function normalizeGroupDisplay(?string $grupo): string
    {
        return $this->normalizeGroup($grupo, 'food_groups_display');
    }

    /** @return array{created:int,updated:int,skipped:int} */
    public function importTaco(): array
    {
        $path = base_path('taco.json');
        if (! file_exists($path)) {
            throw new \RuntimeException('Arquivo taco.json não encontrado.');
        }
        $items = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $created = $updated = $skipped = 0;

        foreach ($items as $item) {
            $reference = trim((string) ($item['Número'] ?? ''));
            $description = trim((string) ($item['Descrição do Alimento'] ?? ''));
            if ($reference === '' || $description === '') {
                $skipped++;

                continue;
            }
            $grupo = trim((string) ($item['Grupo'] ?? '')) ?: null;
            $values = [
                'descricao' => $description,
                'nome_normalizado' => $this->normalizeName($description),
                'grupo' => $grupo,
                'grupo_normalizado' => $this->normalizeGroup($grupo),
                'grupo_exibicao' => $this->normalizeGroupDisplay($grupo),
                'proteina' => (float) ($item['Proteína(g)'] ?? 0),
                'gordura' => (float) ($item['Lipídeos(g)'] ?? 0),
                'caloria' => (float) ($item['Energia(kcal)'] ?? 0),
                'carbo' => (float) ($item['Carboidrato(g)'] ?? 0),
                'qtd' => 100,
                'status' => 'ativo',
            ];
            $food = Alimento::where('fonte', 'taco')->where('source_reference', $reference)->first();
            if ($food) {
                $food->update($values);
                $updated++;
            } else {
                Alimento::create($values + ['fonte' => 'taco', 'source_reference' => $reference, 'id_usuario' => null]);
                $created++;
            }
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
                if ($apply) {
                    $legacy->update([
                        'fonte' => 'legado', 'status' => 'pendente', 'nome_normalizado' => $normal,
                        'created_by' => $legacy->id_usuario, 'id_usuario' => null,
                    ]);
                }
            }
        }

        return compact('merged', 'pending');
    }
}
