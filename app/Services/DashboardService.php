<?php

namespace App\Services;

use App\Models\Alimento;
use App\Models\MealPlan;
use App\Models\Meta_diaria;
use App\Models\Refeicao;
use App\Models\User;
use App\Models\UserBadge;
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
    ) {
    }

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
        $streak = $this->streak($porDia, $meta, $hoje);
        $diasComProteinaBatida = $this->diasComProteinaBatida($porDia, $meta, $hoje);

        $badges = $this->badges($user, [
            'streakDias' => $streak['dias'],
            'diasComProteinaBatida' => $diasComProteinaBatida,
        ]);

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
            'streak' => $streak,
            'semana' => $this->semana($porDia, $meta, $hoje),
            'proxima_refeicao' => $this->proximaRefeicao($user, $hoje, $plano),
            'plano_ativo' => $this->aderencia($plano, $hoje),
            'mais_consumidos' => $this->maisConsumidos($user, $hoje),
            'badges' => $badges,
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
     * @param  Collection<string, array{caloria: float, proteina: float, carbo: float, gordura: float}>  $porDia
     * @return array{dias: int, recorde: int}
     */
    private function streak(Collection $porDia, ?Meta_diaria $meta, string $hoje): array
    {
        if (! $meta || ! $meta->meta_calorias) {
            return ['dias' => 0, 'recorde' => 0];
        }

        $bateu = fn (string $data) => $this->dentroDaFaixa($porDia->get($data)['caloria'] ?? 0.0, (float) $meta->meta_calorias);

        $dias = 0;
        $cursor = CarbonImmutable::parse($hoje);
        while ($bateu($cursor->toDateString())) {
            $dias++;
            $cursor = $cursor->subDay();
        }

        $inicio = CarbonImmutable::parse($hoje)->subDays(self::STREAK_SCAN_DAYS - 1);
        $recorde = 0;
        $atual = 0;
        for ($cursor = $inicio; $cursor->lte(CarbonImmutable::parse($hoje)); $cursor = $cursor->addDay()) {
            if ($bateu($cursor->toDateString())) {
                $atual++;
                $recorde = max($recorde, $atual);
            } else {
                $atual = 0;
            }
        }

        return ['dias' => $dias, 'recorde' => $recorde];
    }

    /**
     * "Bateu a meta" = ficou entre 90% e 110% do valor-alvo (decisão de escopo da Fase 6) —
     * evita punir quem passou 1% e não recompensa quem comeu muito pouco.
     */
    private function dentroDaFaixa(float $consumido, float $alvo): bool
    {
        if ($alvo <= 0 || $consumido <= 0) {
            return false;
        }

        $percentual = $consumido / $alvo;

        return $percentual >= 0.9 && $percentual <= 1.1;
    }

    private function diasComProteinaBatida(Collection $porDia, ?Meta_diaria $meta, string $hoje): int
    {
        if (! $meta || ! $meta->meta_proteinas) {
            return 0;
        }

        $dias = 0;
        for ($i = 0; $i < 7; $i++) {
            $data = CarbonImmutable::parse($hoje)->subDays($i)->toDateString();
            if ($this->dentroDaFaixa($porDia->get($data)['proteina'] ?? 0.0, (float) $meta->meta_proteinas)) {
                $dias++;
            }
        }

        return $dias;
    }

    /**
     * @return array<int, array{data: string, percentual: int, dentro_da_meta: bool}>
     */
    private function semana(Collection $porDia, ?Meta_diaria $meta, string $hoje): array
    {
        $dias = [];
        for ($i = 6; $i >= 0; $i--) {
            $data = CarbonImmutable::parse($hoje)->subDays($i)->toDateString();
            $caloria = $porDia->get($data)['caloria'] ?? 0.0;
            $percentual = $meta && $meta->meta_calorias > 0
                ? (int) round(min($caloria / $meta->meta_calorias, 1.5) * 100)
                : 0;

            $dias[] = [
                'data' => $data,
                'percentual' => $percentual,
                'dentro_da_meta' => $meta ? $this->dentroDaFaixa($caloria, (float) $meta->meta_calorias) : false,
            ];
        }

        return $dias;
    }

    /**
     * Primeira refeição do usuário (ordenada por horário) ainda sem lançamento hoje — mesma
     * heurística de `proximaFaseAberta` (`diary-day.util.ts`), portada pro backend. Quando existe
     * um plano favorito (`$plano`), cruza por proximidade de horário (mesma heurística ±90min de
     * `aderencia()`) pra sugerir o que o plano propõe pra aquela refeição.
     */
    private function proximaRefeicao(User $user, string $hoje, ?MealPlan $plano): ?array
    {
        $refeicoes = Refeicao::query()
            ->where('id_usuario', $user->id)
            ->whereNull('archived_at')
            ->orderBy('horario')
            ->get(['id', 'descricao', 'horario']);

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
            'descricao' => $proxima->descricao,
            'horario' => substr((string) $proxima->horario, 0, 5),
            'sugestao_plano' => $this->sugestaoDoPlano($plano, (string) $proxima->horario),
        ];
    }

    /**
     * Refeição do plano favorito mais próxima do horário informado (±90min, mesma tolerância da
     * aderência) — sem isso o card de missão só dizia "registre X", nunca o que comer.
     */
    private function sugestaoDoPlano(?MealPlan $plano, string $horario): ?string
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

        return $itens->isEmpty() ? null : $itens->implode(', ');
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
     * Avalia o catálogo de badges contra as estatísticas do usuário e persiste qualquer
     * desbloqueio novo (insert idempotente — não duplica se já existir).
     *
     * @param  array{streakDias: int, diasComProteinaBatida: int}  $stats
     * @return array<int, array{codigo: string, icone: string, titulo: string, conquistado: bool, progresso: int, unlocked_at: ?string}>
     */
    private function badges(User $user, array $stats): array
    {
        $avaliacao = BadgeCatalog::avaliar($stats);
        $existentes = UserBadge::query()->where('user_id', $user->id)->get()->keyBy('badge_code');
        $agora = CarbonImmutable::now(self::TIMEZONE);

        foreach ($avaliacao as $codigo => $resultado) {
            if ($resultado['conquistado'] && ! $existentes->has($codigo)) {
                $novo = UserBadge::create(['user_id' => $user->id, 'badge_code' => $codigo, 'unlocked_at' => $agora]);
                $existentes->put($codigo, $novo);
            }
        }

        return collect(BadgeCatalog::definicoes())->map(function (array $definicao) use ($avaliacao, $existentes) {
            $codigo = $definicao['codigo'];
            $unlocked = $existentes->get($codigo);

            return [
                ...$definicao,
                'conquistado' => $unlocked !== null,
                'progresso' => $avaliacao[$codigo]['progresso'] ?? 0,
                'unlocked_at' => $unlocked?->unlocked_at?->toISOString(),
            ];
        })->values()->all();
    }
}
