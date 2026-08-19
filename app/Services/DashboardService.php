<?php

namespace App\Services;

use App\Models\Alimento;
use App\Models\MealPlan;
use App\Models\Meta_diaria;
use App\Models\Refeicao;
use App\Models\User;
use App\Models\UserMissionCompletion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    private const TIMEZONE = 'America/Sao_Paulo';

    private const STREAK_SCAN_DAYS = 90;

    private const ADESAO_TOLERANCIA_MINUTOS = 90;

    public function __construct(
        private readonly DiaryEntryService $diary,
        private readonly MetaDiariaService $metas,
    ) {}

    /**
     * Resumo agregado do Painel — um payload só, pensado pra evitar a home do sistema abrir
     * com várias requisições em cascata (dia, planos, meta, recomendação, alimentos).
     */
    public function resumo(User $user): array
    {
        $hoje = CarbonImmutable::now(self::TIMEZONE)->toDateString();
        $meta = $this->metas->vigente($user);

        $porDia = $this->diary->forDateRange(
            $user,
            CarbonImmutable::parse($hoje)->subDays(self::STREAK_SCAN_DAYS - 1)->toDateString(),
            $hoje,
        );

        $consumidoHoje = $porDia->get($hoje, ['caloria' => 0.0, 'proteina' => 0.0, 'carbo' => 0.0, 'gordura' => 0.0]);
        $plano = $this->resolverPlano($user);

        return [
            'date' => $hoje,
            'hoje' => [
                'consumido' => $consumidoHoje,
                'meta' => $meta ? [
                    'caloria' => (float) $meta->meta_calorias,
                    'proteina' => (float) $meta->meta_proteinas,
                    'carbo' => (float) $meta->meta_carboidratos,
                    'gordura' => (float) $meta->meta_gorduras,
                ] : null,
                'percentual' => $meta && $meta->meta_calorias > 0
                    ? (int) round(min($consumidoHoje['caloria'] / $meta->meta_calorias, 1.5) * 100)
                    : 0,
            ],
            'semana' => $this->semana($porDia, $meta, $hoje),
            'proxima_refeicao' => $this->proximaRefeicao($user, $hoje, $plano),
            'plano_ativo' => $this->aderencia($plano, $hoje),
            'mais_consumidos' => $this->maisConsumidos($user, $hoje),
            'progressao' => $this->progressao($user, $porDia, $hoje),
        ];
    }

    /**
     * Plano favoritado pelo usuário (marcado em Dietas — `MealPlanController::favorite()`) é o
     * que o Painel usa. Enquanto ninguém tiver favoritado nenhum, cai no comportamento antigo
     * (mais recente não arquivado) pra não sumir o card do plano de quem ainda não usou a
     * feature nova.
     */
    private function resolverPlano(User $user): ?MealPlan
    {
        return MealPlan::query()
            ->where('user_id', $user->id)
            ->whereNull('archived_at')
            ->whereNotNull('favorited_at')
            ->with('meals.items')
            ->first()
            ?? MealPlan::query()
                ->where('user_id', $user->id)
                ->whereNull('archived_at')
                ->latest('id')
                ->with('meals.items')
                ->first();
    }

    /**
     * "Bateu a meta" = ficou entre 90% e 110% do valor-alvo — evita punir quem passou 1% e não
     * recompensa quem comeu muito pouco. Usado só pro card "hoje"/"sua semana" (sobre nutrição);
     * o sistema de missões (`progressao()`) é sobre hábito de registrar, não sobre acertar meta —
     * ver decisão de escopo no CLAUDE.md, Roadmap, Fase 6.
     */
    private function dentroDaFaixa(float $consumido, float $alvo): bool
    {
        if ($alvo <= 0 || $consumido <= 0) {
            return false;
        }

        $percentual = $consumido / $alvo;

        return $percentual >= 0.9 && $percentual <= 1.1;
    }

    /**
     * @return array<int, array{data: string, percentual: int, dentro_da_meta: bool, caloria: int, proteina: int, carbo: int, gordura: int}>
     */
    private function semana(Collection $porDia, ?Meta_diaria $meta, string $hoje): array
    {
        $dias = [];
        for ($i = 6; $i >= 0; $i--) {
            $data = CarbonImmutable::parse($hoje)->subDays($i)->toDateString();
            $registroDoDia = $porDia->get($data, ['caloria' => 0.0, 'proteina' => 0.0, 'carbo' => 0.0, 'gordura' => 0.0]);
            $caloria = $registroDoDia['caloria'];
            $percentual = $meta && $meta->meta_calorias > 0
                ? (int) round(min($caloria / $meta->meta_calorias, 1.5) * 100)
                : 0;

            $dias[] = [
                'data' => $data,
                'percentual' => $percentual,
                'dentro_da_meta' => $meta ? $this->dentroDaFaixa($caloria, (float) $meta->meta_calorias) : false,

                'caloria' => (int) round($caloria),
                'proteina' => (int) round($registroDoDia['proteina']),
                'carbo' => (int) round($registroDoDia['carbo']),
                'gordura' => (int) round($registroDoDia['gordura']),
            ];
        }

        return $dias;
    }

    private function proximaRefeicao(User $user, string $hoje, ?MealPlan $plano): ?array
    {
        $refeicoes = Refeicao::query()
            ->where('id_usuario', $user->id)
            ->whereNull('archived_at')
            ->orderBy('horario')
            ->get(['id', 'descricao', 'horario', 'chave_padrao']);

        if ($refeicoes->isEmpty()) {
            return null;
        }

        $comRegistroHoje = DB::table('registros')
            ->where('id_usuario', $user->id)
            ->where('data', $hoje)
            ->pluck('id_refeicao')
            ->unique();

        $proxima = $refeicoes->first(fn (Refeicao $refeicao) => ! $comRegistroHoje->contains($refeicao->id));

        if (! $proxima) {
            return null;
        }

        return [
            'meal_id' => $proxima->id,
            'descricao' => $this->nomeDaRefeicao($proxima),
            'horario' => substr((string) $proxima->horario, 0, 5),
            'sugestao_plano' => $this->sugestaoDoPlano($plano, (string) $proxima->horario),
            // Todas as refeições do dia (não só a próxima) — vira a faixa "hoje" no card de
            // missão, além do próprio horário-alvo já usado pra achar $proxima.
            'refeicoes_hoje' => $refeicoes->map(fn (Refeicao $refeicao) => [
                'meal_id' => $refeicao->id,
                'descricao' => $this->nomeDaRefeicao($refeicao),
                'horario' => substr((string) $refeicao->horario, 0, 5),
                'registrado' => $comRegistroHoje->contains($refeicao->id),
            ])->values()->all(),
        ];
    }

    private function nomeDaRefeicao(Refeicao $refeicao): string
    {
        return match ($refeicao->chave_padrao) {
            'cafe_da_manha' => __('messages.meal_breakfast'),
            'almoco' => __('messages.meal_lunch'),
            'lanche_da_tarde' => __('messages.meal_afternoon_snack'),
            'jantar' => __('messages.meal_dinner'),
            'ceia' => __('messages.meal_evening_snack'),
            default => $refeicao->descricao,
        };
    }

    /**
     * Refeição do plano favorito mais próxima do horário informado (±90min, mesma tolerância da
     * aderência) — sem isso o card de missão só dizia "registre X", nunca o que comer nem quanto.
     *
     * @return array{itens: array<int, string>, totais: array{caloria: int, proteina: int, carbo: int, gordura: int}}|null
     */
    private function sugestaoDoPlano(?MealPlan $plano, string $horario): ?array
    {
        if (! $plano || $plano->meals->isEmpty()) {
            return null;
        }

        $minutosAlvo = $this->minutosDoDia($horario);
        $maisProxima = $plano->meals
            ->sortBy(fn ($refeicaoPlano) => abs($this->minutosDoDia($refeicaoPlano->horario) - $minutosAlvo))
            ->first();

        if (! $maisProxima || abs($this->minutosDoDia($maisProxima->horario) - $minutosAlvo) > self::ADESAO_TOLERANCIA_MINUTOS) {
            return null;
        }

        $itens = $maisProxima->items->map(fn ($item) => $item->nome_exibicao_snapshot ?? $item->descricao_snapshot)->filter()->values();

        if ($itens->isEmpty()) {
            return null;
        }

        $totais = $maisProxima->totals ?? [];

        return [
            'itens' => $itens->all(),
            'totais' => [
                'caloria' => (int) round($totais['caloria'] ?? 0),
                'proteina' => (int) round($totais['proteina'] ?? 0),
                'carbo' => (int) round($totais['carbo'] ?? 0),
                'gordura' => (int) round($totais['gordura'] ?? 0),
            ],
        ];
    }

    /**
     * Aderência aproximada dos últimos 7 dias ao plano já resolvido (`resolverPlano`). Sem
     * vínculo real entre `MealPlanMeal` (sem dia da semana) e `Registro`/`Refeicao` no schema —
     * decisão de escopo da Fase 6: casa por proximidade de horário (±90 min), heurística
     * documentada, não uma relação de verdade.
     */
    private function aderencia(?MealPlan $plano, string $hoje): ?array
    {
        if (! $plano || $plano->meals->isEmpty()) {
            return null;
        }

        // Compara hora-do-dia (`horario_refeicao_snapshot`, já um "HH:MM:SS" de parede) em vez
        // de `consumed_at` — evita ter que reconciliar timezone entre o instante gravado e o
        // horário do plano; os dois lados já são hora local por natureza (o horário de uma
        // refeição, não um instante absoluto), então comparar como tal é mais simples e correto
        // que converter timezone só pra desfazer a conversão depois.
        $inicio = CarbonImmutable::parse($hoje)->subDays(6)->toDateString();
        $registrosPorDia = DB::table('registros')
            ->where('id_usuario', $plano->user_id)
            ->whereBetween('data', [$inicio, $hoje])
            ->get(['data', 'horario_refeicao_snapshot'])
            ->groupBy(fn (object $row) => (string) $row->data);

        $totalEsperado = 0;
        $totalBatido = 0;
        $statusHoje = [];

        for ($i = 0; $i <= 6; $i++) {
            $data = CarbonImmutable::parse($hoje)->subDays($i)->toDateString();
            $registrosDoDia = $registrosPorDia->get($data, collect());

            foreach ($plano->meals as $refeicaoPlano) {
                $totalEsperado++;
                $minutosPlano = $this->minutosDoDia($refeicaoPlano->horario);
                $bateu = $registrosDoDia->contains(function (object $registro) use ($minutosPlano) {
                    return abs($this->minutosDoDia($registro->horario_refeicao_snapshot) - $minutosPlano) <= self::ADESAO_TOLERANCIA_MINUTOS;
                });

                if ($bateu) {
                    $totalBatido++;
                }

                if ($i === 0) {
                    $statusHoje[] = [
                        'meal_plan_meal_id' => $refeicaoPlano->id,
                        'descricao' => $refeicaoPlano->descricao,
                        'horario' => substr((string) $refeicaoPlano->horario, 0, 5),
                        'registrado' => $bateu,
                    ];
                }
            }
        }

        return [
            'id' => $plano->id,
            'titulo' => $plano->titulo,
            'aderencia_7d' => $totalEsperado > 0 ? (int) round(($totalBatido / $totalEsperado) * 100) : 0,
            'refeicoes_hoje' => $statusHoje,
        ];
    }

    private function minutosDoDia(?string $horario): int
    {
        if (! $horario) {
            return -self::ADESAO_TOLERANCIA_MINUTOS * 1000; // nunca bate com nada real
        }

        [$horas, $minutos] = array_map('intval', explode(':', substr($horario, 0, 5)));

        return $horas * 60 + $minutos;
    }

    /**
     * @return array<int, array{food_id: int, descricao: string, vezes: int}>
     */
    private function maisConsumidos(User $user, string $hoje): array
    {
        $desde = CarbonImmutable::parse($hoje)->subDays(29)->toDateString();
        $top = $this->diary->mostConsumedFoods($user, $desde, 5);

        if ($top->isEmpty()) {
            return [];
        }

        // `nome_exibicao` é um accessor com fallback pra `descricao` — carregar o model
        // inteiro (não só a coluna via `pluck`) pra esse fallback funcionar.
        $alimentos = Alimento::query()->whereIn('id', $top->pluck('food_id'))->get()->keyBy('id');

        return $top->map(fn (array $item) => [
            'food_id' => $item['food_id'],
            'descricao' => $alimentos->get($item['food_id'])?->nome_exibicao ?? '',
            'vezes' => $item['vezes'],
        ])->values()->all();
    }

    /**
     * Dias consecutivos (a partir de hoje pra trás) com pelo menos um registro no diário —
     * **não** é sobre bater meta calórica (isso é `dentroDaFaixa`/`semana`, assunto separado).
     * `$porDia` (de `DiaryEntryService::forDateRange`) só tem chave pros dias que têm registro
     * (a query agrega via `JOIN` com `registro_alimentos`), então "tem chave" já é exatamente
     * "teve pelo menos um registro naquele dia" — sem precisar de query nova.
     */
    private function streakDeRegistro(Collection $porDia, string $hoje): int
    {
        $dias = 0;
        $cursor = CarbonImmutable::parse($hoje);
        while ($porDia->has($cursor->toDateString())) {
            $dias++;
            $cursor = $cursor->subDay();
        }

        return $dias;
    }

    /**
     * Avalia cada missão do catálogo contra o estado atual do usuário — devolve, por código,
     * a chave de período (`period_key`) que identifica a ocorrência atual (dia/semana/"once"),
     * se ela está cumprida agora, e o progresso (0–100) em direção a ela.
     *
     * @return array<string, array{period_key: string, concluida: bool, progresso: int}>
     */
    private function avaliarMissoes(User $user, Collection $porDia, string $hoje): array
    {
        $segunda = CarbonImmutable::parse($hoje)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();

        $refeicoesAtivas = Refeicao::query()->where('id_usuario', $user->id)->whereNull('archived_at')->count();
        $refeicoesRegistradasHoje = DB::table('registros')
            ->where('id_usuario', $user->id)
            ->where('data', $hoje)
            ->distinct()
            ->count('id_refeicao');

        $diasSemana = 0;
        $cursor = CarbonImmutable::parse($segunda);
        for ($i = 0; $i < 7; $i++) {
            if ($porDia->has($cursor->toDateString())) {
                $diasSemana++;
            }
            $cursor = $cursor->addDay();
        }

        $streakRegistro = $this->streakDeRegistro($porDia, $hoje);
        $progresso = fn (int $atual, int $alvo) => $alvo > 0 ? (int) round(min($atual / $alvo, 1) * 100) : 100;

        return [
            'log_primeira_refeicao' => ['period_key' => $hoje, 'concluida' => $porDia->has($hoje), 'progresso' => $porDia->has($hoje) ? 100 : 0],
            'log_dia_completo' => ['period_key' => $hoje, 'concluida' => $refeicoesAtivas > 0 && $refeicoesRegistradasHoje >= $refeicoesAtivas, 'progresso' => $progresso($refeicoesRegistradasHoje, $refeicoesAtivas)],
            'log_3_dias_semana' => ['period_key' => $segunda, 'concluida' => $diasSemana >= 3, 'progresso' => $progresso($diasSemana, 3)],
            'log_semana_completa' => ['period_key' => $segunda, 'concluida' => $diasSemana >= 7, 'progresso' => $progresso($diasSemana, 7)],
            'marco_streak_4' => ['period_key' => 'once', 'concluida' => $streakRegistro >= 4, 'progresso' => $progresso($streakRegistro, 4)],
            'marco_streak_7' => ['period_key' => 'once', 'concluida' => $streakRegistro >= 7, 'progresso' => $progresso($streakRegistro, 7)],
            'marco_streak_30' => ['period_key' => 'once', 'concluida' => $streakRegistro >= 30, 'progresso' => $progresso($streakRegistro, 30)],
        ];
    }

    /**
     * Avalia o catálogo de missões, persiste qualquer conclusão nova (insert idempotente —
     * `(user_id, mission_code, period_key)` é único, então uma missão diária/semanal já
     * persistida num período anterior não é tocada de novo; reavaliar no período novo é o que faz
     * ela "resetar" — não tem UPDATE nem DELETE aqui) e resolve nível a partir do XP acumulado de
     * toda a história (inclusive missões diárias/semanais de dias passados).
     */
    private function progressao(User $user, Collection $porDia, string $hoje): array
    {
        $avaliacoes = $this->avaliarMissoes($user, $porDia, $hoje);
        $definicoes = collect(MissionCatalog::definicoes())->keyBy('codigo');

        $existentes = UserMissionCompletion::query()->where('user_id', $user->id)->get()
            ->keyBy(fn (UserMissionCompletion $completion) => $completion->mission_code.'|'.$completion->period_key);

        $agora = CarbonImmutable::now(self::TIMEZONE);
        foreach ($avaliacoes as $codigo => $avaliacao) {
            $chave = $codigo.'|'.$avaliacao['period_key'];
            if ($avaliacao['concluida'] && ! $existentes->has($chave)) {
                $novo = UserMissionCompletion::create([
                    'user_id' => $user->id,
                    'mission_code' => $codigo,
                    'period_key' => $avaliacao['period_key'],
                    'xp' => $definicoes[$codigo]['xp'],
                    'completed_at' => $agora,
                ]);
                $existentes->put($chave, $novo);
            }
        }

        $nivelInfo = LevelCatalog::resolver((int) $existentes->sum('xp'));

        $porEscopo = fn (string $escopo) => collect(MissionCatalog::porEscopo($escopo))->map(function (array $definicao) use ($avaliacoes, $existentes) {
            $avaliacao = $avaliacoes[$definicao['codigo']];
            $chave = $definicao['codigo'].'|'.$avaliacao['period_key'];

            return [
                'codigo' => $definicao['codigo'],
                'icone' => $definicao['icone'],
                'titulo' => $definicao['titulo'],
                'xp' => $definicao['xp'],
                'concluida' => $existentes->has($chave),
                'progresso' => $avaliacao['progresso'],
            ];
        })->values()->all();

        return [
            'nivel' => $nivelInfo['nivel'],
            'xp' => $nivelInfo['xp'],
            'xp_proximo_nivel' => $nivelInfo['xp_proximo_nivel'],
            'progresso_percent' => $nivelInfo['progresso_percent'],
            'diarias' => $porEscopo(MissionCatalog::ESCOPO_DIARIA),
            'semanais' => $porEscopo(MissionCatalog::ESCOPO_SEMANAL),
            'marcos' => $porEscopo(MissionCatalog::ESCOPO_MARCO),
        ];
    }
}
