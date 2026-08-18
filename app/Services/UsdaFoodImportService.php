<?php

namespace App\Services;

use App\Models\Alimento;
use App\Models\AlimentoNutriente;
use App\Models\Nutriente;

class UsdaFoodImportService
{
    private const CORE = ['203' => 'proteina', '204' => 'gordura', '205' => 'carbo', '208' => 'caloria'];

    public function __construct(private readonly FoodCatalogService $catalog) {}

    /** @return array{created:int,updated:int,nutrients:int} */
    public function import(string $path, string $dataset, string $version): array
    {
        if (! file_exists($path)) {
            throw new \RuntimeException("Arquivo USDA não encontrado: {$path}");
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Não foi possível ler o arquivo USDA.');
        }
        $document = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $foods = $document[$dataset === 'sr-legacy' ? 'SRLegacyFoods' : 'FoundationFoods'] ?? null;
        if (! is_array($foods)) {
            throw new \RuntimeException('Formato USDA incompatível com o conjunto informado.');
        }

        $checksum = hash('sha256', $contents);
        $created = $updated = $nutrients = 0;
        foreach ($foods as $data) {
            $fdcId = (string) ($data['fdcId'] ?? '');
            $description = trim((string) ($data['description'] ?? ''));
            if ($fdcId === '' || $description === '') {
                continue;
            }

            $core = $this->coreValues($data['foodNutrients'] ?? []);
            $grupo = $data['foodCategory']['description'] ?? 'USDA '.($data['dataType'] ?? $dataset);
            $attributes = [
                'descricao' => $description,
                'nome_normalizado' => $this->catalog->normalizeName($description),
                'grupo' => $grupo,
                'grupo_normalizado' => $this->catalog->normalizeGroup($grupo),
                'proteina' => $core['proteina'] ?? 0,
                'gordura' => $core['gordura'] ?? 0,
                'carbo' => $core['carbo'] ?? 0,
                'caloria' => $core['caloria'] ?? 0,
                'qtd' => 100,
                'status' => 'ativo',
                'source_version' => $version,
                'source_checksum' => $checksum,
            ];
            $food = Alimento::where('fonte', 'usda')->where('source_reference', $fdcId)->first();
            if ($food) {
                $food->update($attributes);
                $updated++;
            } else {
                $food = Alimento::create($attributes + ['fonte' => 'usda', 'source_reference' => $fdcId, 'id_usuario' => null]);
                $created++;
            }
            $nutrients += $this->syncNutrients($food, $data['foodNutrients'] ?? []);
        }

        return compact('created', 'updated', 'nutrients');
    }

    /** @param array<int, array<string,mixed>> $foodNutrients @return array<string,float> */
    private function coreValues(array $foodNutrients): array
    {
        $values = [];
        foreach ($foodNutrients as $row) {
            $number = (string) data_get($row, 'nutrient.number', '');
            if (isset(self::CORE[$number]) && isset($row['amount'])) {
                $values[self::CORE[$number]] = (float) $row['amount'];
            }
        }

        return $values;
    }

    /** @param array<int, array<string,mixed>> $rows */
    private function syncNutrients(Alimento $food, array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            $definition = $row['nutrient'] ?? null;
            if (! is_array($definition) || ! isset($definition['number'], $definition['name'], $definition['unitName'], $row['amount'])) {
                continue;
            }
            $nutrient = Nutriente::updateOrCreate(
                ['codigo' => 'usda:'.(string) $definition['number']],
                ['nome' => (string) $definition['name'], 'unidade' => $this->unit((string) $definition['unitName']), 'categoria' => $this->category((string) $definition['number'], (string) $definition['name'])],
            );
            AlimentoNutriente::updateOrCreate(
                ['alimento_id' => $food->id, 'nutriente_id' => $nutrient->id],
                ['valor' => (float) $row['amount'], 'tipo_dado' => data_get($row, 'foodNutrientDerivation.code')],
            );
            $count++;
        }

        return $count;
    }

    private function unit(string $unit): string
    {
        return str_replace(['µ', 'Âµ'], 'mc', trim($unit));
    }

    private function category(string $number, string $name): string
    {
        if (in_array($number, ['203', '204', '205', '208', '291', '269'], true)) {
            return 'macro';
        }
        if (in_array($number, ['301', '303', '304', '305', '306', '307', '309', '312', '315'], true)) {
            return 'mineral';
        }
        if (str_contains(strtolower($name), 'vitamin') || in_array($number, ['404', '405', '406', '415', '417', '418'], true)) {
            return 'vitamina';
        }
        if (str_contains(strtolower($name), 'fatty') || $number === '601') {
            return 'lipidio';
        }

        return 'outro';
    }
}
