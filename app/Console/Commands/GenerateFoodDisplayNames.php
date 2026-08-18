<?php

namespace App\Console\Commands;

use App\Models\Alimento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenerateFoodDisplayNames extends Command
{
    protected $signature = 'foods:generate-display-names
        {--chunk=25 : Quantos alimentos por chamada à IA}
        {--force : Regera mesmo os que já estão no JSON}
        {--limit= : Limita a quantidade total de alimentos processados (para teste)}';

    protected $description = 'Gera sugestões de nome_exibicao/detalhe_exibicao via IA e grava em database/data/food_display_names.json para revisão';

    private const OUTPUT_PATH = 'database/data/food_display_names.json';

    public function handle(): int
    {
        if (! config('gemini.enabled') || ! config('gemini.api_key')) {
            $this->error('IA não configurada (GEMINI_API_KEY / MEAL_PLAN_AI_ENABLED).');

            return self::FAILURE;
        }

        $path = base_path(self::OUTPUT_PATH);
        $existing = File::exists($path) ? json_decode(File::get($path), true) : [];
        $existing = is_array($existing) ? $existing : [];

        $query = Alimento::query()->orderBy('id');
        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }
        $foods = $query->get(['id', 'descricao', 'fonte', 'grupo']);

        $pending = $this->option('force')
            ? $foods
            : $foods->reject(fn (Alimento $food) => isset($existing[$food->id]));

        if ($pending->isEmpty()) {
            $this->info('Nada a gerar — todos os alimentos já têm entrada no JSON. Use --force para regerar.');

            return self::SUCCESS;
        }

        $this->info("Gerando nomes amigáveis para {$pending->count()} alimentos (de {$foods->count()} no total)...");
        $bar = $this->output->createProgressBar($pending->count());

        $failures = 0;
        foreach ($pending->chunk((int) $this->option('chunk')) as $rawChunk) {
            $chunk = $rawChunk->keyBy('id');
            try {
                $results = $this->askChunk($chunk);
                foreach ($results as $result) {
                    $id = (int) ($result['id'] ?? 0);
                    if (! $id || ! $chunk->has($id)) {
                        continue;
                    }
                    $existing[$id] = [
                        'descricao_original' => $chunk[$id]->descricao,
                        'fonte' => $chunk[$id]->fonte,
                        'nome_exibicao' => trim((string) ($result['nome_exibicao'] ?? '')) ?: $chunk[$id]->descricao,
                        'detalhe_exibicao' => trim((string) ($result['detalhe_exibicao'] ?? '')) ?: null,
                    ];
                }
                // Grava incrementalmente — uma falha no meio não perde o progresso já feito.
                File::ensureDirectoryExists(dirname($path));
                File::put($path, json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            } catch (\Throwable $exception) {
                $failures++;
                Log::warning('food_display_names.chunk_failed', ['error' => $exception->getMessage(), 'ids' => $chunk->pluck('id')->all()]);
                $this->newLine();
                $this->warn('Falhou um lote (ids '.$chunk->pluck('id')->implode(',').'): '.$exception->getMessage());
            }
            $bar->advance($rawChunk->count());
        }
        $bar->finish();
        $this->newLine();
        $this->info("Concluído. {$failures} lote(s) falharam e podem ser retomados rodando o comando de novo.");
        $this->info('Revise '.self::OUTPUT_PATH.' antes de rodar foods:apply-display-names.');

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param \Illuminate\Support\Collection<int, Alimento> $chunk keyed by alimento id */
    private function askChunk(\Illuminate\Support\Collection $chunk): array
    {
        $input = [
            'tarefa' => 'normalizar_nomes_alimentos',
            'papel' => 'Você é um editor de conteúdo nutricional brasileiro. Para cada alimento da lista, gere um nome principal amigável (nome_exibicao) e, quando fizer sentido, um detalhe complementar (detalhe_exibicao).',
            'regras' => [
                'nome_exibicao deve ser curto, em português natural, com acentuação correta, na ordem que um brasileiro fala (ex.: "Filé de abadejo", não "Abadejo, filé").',
                'Não remova informação que diferencie o alimento nutricionalmente (corte, tipo, parte do animal, se é integral/desnatado etc. deve aparecer em nome_exibicao ou detalhe_exibicao).',
                'Não transforme o alimento em outro alimento nem mude o que ele é.',
                'detalhe_exibicao é o complemento: preparo, estado, corte secundário (ex.: "cru", "congelado, assado"). Deixe "" quando o nome principal já é autoexplicativo.',
                'Alimentos com descrição em inglês (fonte usda) devem ser traduzidos para português natural, mantendo a mesma informação nutricionalmente relevante.',
                'Nunca invente informação que não esteja na descrição original.',
            ],
            'alimentos' => $chunk->map(fn (Alimento $food) => [
                'id' => $food->id,
                'descricao' => $food->descricao,
                'fonte' => $food->fonte,
                'grupo' => $food->grupo,
            ])->values()->all(),
        ];

        $schema = [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'nome_exibicao' => ['type' => 'string'],
                            'detalhe_exibicao' => ['type' => 'string'],
                        ],
                        'required' => ['id', 'nome_exibicao', 'detalhe_exibicao'],
                    ],
                ],
            ],
            'required' => ['items'],
        ];

        $response = Http::acceptJson()
            ->withHeaders(['x-goog-api-key' => config('gemini.api_key')])
            ->timeout(config('gemini.timeout'))
            ->post(config('gemini.endpoint'), [
                'model' => config('gemini.model'),
                'input' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'response_format' => [['type' => 'text', 'mime_type' => 'application/json', 'schema' => $schema]],
            ])
            ->throw()
            ->json();

        $step = collect($response['steps'] ?? [])->reverse()->first(fn ($item) => ($item['type'] ?? null) === 'model_output');
        $raw = data_get($response, 'output_text') ?? data_get($step, 'content.0.text');
        if (! is_string($raw)) {
            throw new \RuntimeException('Gemini não retornou conteúdo estruturado.');
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded['items'] ?? [];
    }
}
