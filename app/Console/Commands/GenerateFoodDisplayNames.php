<?php

namespace App\Console\Commands;

use App\Models\Alimento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gera, via Gemini, um nome amigável (nome_exibicao) e um detalhe
 * complementar (detalhe_exibicao) para cada alimento do catálogo, no
 * locale pedido (`--locale`).
 *
 * `pt-BR` (padrão) parte da `descricao` técnica original (TACO em
 * português com vírgulas, ou USDA em inglês). `en-US` parte do
 * `nome_exibicao` pt-BR já gerado/revisado (mais limpo que a `descricao`
 * técnica, principalmente pros itens TACO) e pede uma tradução natural.
 *
 * Não grava direto no banco: acumula tudo em
 * `database/data/food_display_names.json` (pt-BR) ou
 * `database/data/food_display_names_en-US.json`, versionado no repo, para
 * revisão humana antes de `foods:apply-display-names`. Idempotente/
 * retomável — alimentos já presentes no JSON são pulados a menos que
 * `--force` seja usado.
 */
class GenerateFoodDisplayNames extends Command
{
    protected $signature = 'foods:generate-display-names
        {--locale=pt-BR : pt-BR (a partir da descricao técnica) ou en-US (traduz o nome_exibicao pt-BR já gerado)}
        {--chunk=25 : Quantos alimentos por chamada à IA}
        {--force : Regera mesmo os que já estão no JSON}
        {--limit= : Limita a quantidade total de alimentos processados (para teste)}';

    protected $description = 'Gera sugestões de nome_exibicao/detalhe_exibicao via IA, no locale pedido, e grava em database/data/ para revisão';

    public function handle(): int
    {
        $locale = $this->option('locale');
        if (! in_array($locale, ['pt-BR', 'en-US'], true)) {
            $this->error('Opcao --locale invalida. Use "pt-BR" ou "en-US".');

            return self::FAILURE;
        }

        if (! config('gemini.enabled') || ! config('gemini.api_key')) {
            $this->error('IA não configurada (GEMINI_API_KEY / MEAL_PLAN_AI_ENABLED).');

            return self::FAILURE;
        }

        $path = base_path($this->outputPath($locale));
        $existing = File::exists($path) ? json_decode(File::get($path), true) : [];
        $existing = is_array($existing) ? $existing : [];

        $query = Alimento::query()->orderBy('id');
        if ($locale === 'en-US') {
            // Só faz sentido traduzir pra inglês o que já tem nome amigável pt-BR revisado.
            $query->whereNotNull('nome_exibicao');
        }
        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }
        $foods = $query->get(['id', 'descricao', 'nome_exibicao', 'detalhe_exibicao', 'fonte', 'grupo']);

        $pending = $this->option('force')
            ? $foods
            : $foods->reject(fn (Alimento $food) => isset($existing[$food->id]));

        if ($pending->isEmpty()) {
            $this->info('Nada a gerar — todos os alimentos já têm entrada no JSON. Use --force para regerar.');

            return self::SUCCESS;
        }

        $this->info("Gerando nomes amigáveis ({$locale}) para {$pending->count()} alimentos (de {$foods->count()} no total)...");
        $bar = $this->output->createProgressBar($pending->count());

        $failures = 0;
        foreach ($pending->chunk((int) $this->option('chunk')) as $rawChunk) {
            $chunk = $rawChunk->keyBy('id');
            try {
                $results = $this->askChunk($chunk, $locale);
                foreach ($results as $result) {
                    $id = (int) ($result['id'] ?? 0);
                    if (! $id || ! $chunk->has($id)) {
                        continue;
                    }
                    $fallbackNome = $locale === 'en-US' ? $chunk[$id]->nome_exibicao : $chunk[$id]->descricao;
                    $existing[$id] = [
                        'descricao_original' => $chunk[$id]->descricao,
                        'fonte' => $chunk[$id]->fonte,
                        'nome_exibicao' => trim((string) ($result['nome_exibicao'] ?? '')) ?: $fallbackNome,
                        'detalhe_exibicao' => trim((string) ($result['detalhe_exibicao'] ?? '')) ?: null,
                    ];
                }
                // Grava incrementalmente — uma falha no meio não perde o progresso já feito.
                File::ensureDirectoryExists(dirname($path));
                File::put($path, json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            } catch (\Throwable $exception) {
                $failures++;
                Log::warning('food_display_names.chunk_failed', ['locale' => $locale, 'error' => $exception->getMessage(), 'ids' => $chunk->pluck('id')->all()]);
                $this->newLine();
                $this->warn('Falhou um lote (ids '.$chunk->pluck('id')->implode(',').'): '.$exception->getMessage());
            }
            $bar->advance($rawChunk->count());
        }
        $bar->finish();
        $this->newLine();
        $this->info("Concluído. {$failures} lote(s) falharam e podem ser retomados rodando o comando de novo.");
        $this->info('Revise '.$this->outputPath($locale).' antes de rodar foods:apply-display-names --locale='.$locale);

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function outputPath(string $locale): string
    {
        return $locale === 'en-US'
            ? 'database/data/food_display_names_en-US.json'
            : 'database/data/food_display_names.json';
    }

    /** @param \Illuminate\Support\Collection<int, Alimento> $chunk keyed by alimento id */
    private function askChunk(\Illuminate\Support\Collection $chunk, string $locale): array
    {
        $input = $locale === 'en-US'
            ? [
                'tarefa' => 'traduzir_nomes_alimentos_en',
                'papel' => 'Você é um editor de conteúdo nutricional bilíngue. Para cada alimento, traduza o nome amigável pt-BR (nome_exibicao_pt) e o detalhe (detalhe_exibicao_pt) pra um inglês natural (US), gerando nome_exibicao e detalhe_exibicao em inglês.',
                'regras' => [
                    'nome_exibicao (inglês) deve ser curto, natural, na ordem que um falante de inglês usaria (ex.: "Beef chuck", não "Chuck of beef").',
                    'Não remova informação que diferencie o alimento nutricionalmente (corte, tipo, parte do animal, integral/desnatado etc. deve aparecer em nome_exibicao ou detalhe_exibicao).',
                    'Não transforme o alimento em outro alimento nem mude o que ele é.',
                    'detalhe_exibicao (inglês) é o complemento: preparo, estado, corte secundário (ex.: "raw", "frozen, roasted"). Deixe "" quando o nome principal já é autoexplicativo.',
                    'Nunca invente informação que não esteja no nome_exibicao_pt/detalhe_exibicao_pt/descricao_original fornecidos.',
                ],
                'alimentos' => $chunk->map(fn (Alimento $food) => [
                    'id' => $food->id,
                    'nome_exibicao_pt' => $food->nome_exibicao,
                    'detalhe_exibicao_pt' => $food->detalhe_exibicao,
                    'descricao_original' => $food->descricao,
                    'fonte' => $food->fonte,
                ])->values()->all(),
            ]
            : [
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
