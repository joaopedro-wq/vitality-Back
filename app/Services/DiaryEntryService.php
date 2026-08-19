<?php

namespace App\Services;

use App\Models\Alimento;
use App\Models\Registro;
use App\Models\Refeicao;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DiaryEntryService
{
    private const TIMEZONE = 'America/Sao_Paulo';

    public function create(User $user, array $data): Registro
    {
        return DB::transaction(function () use ($user, $data) {
            $meal = $this->activeMealFor($user, (int) $data['meal_id']);
            $time = $this->consumedAt($data['consumed_at']);

            $entry = Registro::create([
                'id_usuario' => $user->id,
                'id_refeicao' => $meal->id,
                'data' => $time->setTimezone(self::TIMEZONE)->toDateString(),
                'consumed_at' => $time,
                'descricao_refeicao_snapshot' => $meal->descricao,
                'horario_refeicao_snapshot' => $meal->horario,
            ]);

            $this->syncItems($entry, $data['items']);

            return $this->load($entry);
        });
    }

    public function replace(Registro $entry, User $user, array $data): Registro
    {
        return DB::transaction(function () use ($entry, $user, $data) {
            $meal = $this->activeMealFor($user, (int) $data['meal_id']);
            $time = $this->consumedAt($data['consumed_at']);

            $entry->update([
                'id_refeicao' => $meal->id,
                'data' => $time->setTimezone(self::TIMEZONE)->toDateString(),
                'consumed_at' => $time,
                'descricao_refeicao_snapshot' => $meal->descricao,
                'horario_refeicao_snapshot' => $meal->horario,
            ]);

            $this->syncItems($entry, $data['items']);

            return $this->load($entry);
        });
    }

    public function forDay(User $user, string $date): Collection
    {
        return Registro::query()
            ->where('id_usuario', $user->id)
            ->whereDate('data', $date)
            ->with(['alimentos.publishedImage', 'refeicao'])
            ->orderBy('consumed_at')
            ->get();
    }

    /**
     * Totais de macro por dia, agregados no banco, para um intervalo de datas (inclusive).
     * Usado pelo Painel (streak, trilha semanal, "mais consumidos") — não recarrega o dia
     * inteiro com relações como `forDay`, só a soma que interessa.
     *
     * Reaproveita o mesmo fator qtd/qtd_base_snapshot que `DiaryEntryResource` aplica por item
     * (o snapshot é por porção-base, não por grama absoluta).
     *
     * @return Collection<string, array{caloria: float, proteina: float, carbo: float, gordura: float}>
     */
    public function forDateRange(User $user, string $from, string $to): Collection
    {
        return DB::table('registros')
            ->join('registro_alimentos', 'registro_alimentos.registro_id', '=', 'registros.id')
            ->where('registros.id_usuario', $user->id)
            ->whereBetween('registros.data', [$from, $to])
            ->groupBy('registros.data')
            ->orderBy('registros.data')
            ->selectRaw('registros.data as data')
            ->selectRaw('SUM(registro_alimentos.caloria_snapshot * registro_alimentos.qtd / NULLIF(registro_alimentos.qtd_base_snapshot, 0)) as caloria')
            ->selectRaw('SUM(registro_alimentos.proteina_snapshot * registro_alimentos.qtd / NULLIF(registro_alimentos.qtd_base_snapshot, 0)) as proteina')
            ->selectRaw('SUM(registro_alimentos.carbo_snapshot * registro_alimentos.qtd / NULLIF(registro_alimentos.qtd_base_snapshot, 0)) as carbo')
            ->selectRaw('SUM(registro_alimentos.gordura_snapshot * registro_alimentos.qtd / NULLIF(registro_alimentos.qtd_base_snapshot, 0)) as gordura')
            ->get()
            ->keyBy(fn (object $row) => (string) $row->data)
            ->map(fn (object $row) => [
                'caloria' => round((float) $row->caloria, 3),
                'proteina' => round((float) $row->proteina, 3),
                'carbo' => round((float) $row->carbo, 3),
                'gordura' => round((float) $row->gordura, 3),
            ]);
    }

    /**
     * Alimentos mais consumidos (por contagem de lançamentos), não por recência —
     * complementa `recentFoods` do controller, que ordena por último consumo.
     *
     * @return Collection<int, array{food_id: int, vezes: int}>
     */
    public function mostConsumedFoods(User $user, string $since, int $limit = 5): Collection
    {
        return DB::table('registros')
            ->join('registro_alimentos', 'registro_alimentos.registro_id', '=', 'registros.id')
            ->where('registros.id_usuario', $user->id)
            ->where('registros.data', '>=', $since)
            ->groupBy('registro_alimentos.alimento_id')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit($limit)
            ->selectRaw('registro_alimentos.alimento_id as food_id, COUNT(*) as vezes')
            ->get()
            ->map(fn (object $row) => ['food_id' => (int) $row->food_id, 'vezes' => (int) $row->vezes]);
    }

    public function load(Registro $entry): Registro
    {
        return $entry->fresh(['alimentos.publishedImage', 'refeicao']);
    }

    private function activeMealFor(User $user, int $mealId): Refeicao
    {
        $meal = Refeicao::query()
            ->whereKey($mealId)
            ->where('id_usuario', $user->id)
            ->whereNull('archived_at')
            ->first();

        if (! $meal) {
            throw ValidationException::withMessages(['meal_id' => 'A refeição selecionada não está disponível.']);
        }

        return $meal;
    }

    private function consumedAt(string $value): CarbonImmutable
    {
        $time = CarbonImmutable::parse($value);
        if ($time->isFuture()) {
            throw ValidationException::withMessages(['consumed_at' => 'Não é possível registrar um consumo futuro.']);
        }

        return $time;
    }

    private function syncItems(Registro $entry, array $items): void
    {
        $quantities = collect($items)
            ->groupBy(fn (array $item) => (int) $item['food_id'])
            ->map(fn (Collection $group) => round($group->sum(fn (array $item) => (float) $item['quantity']), 3));

        $foods = Alimento::query()
            ->whereIn('id', $quantities->keys())
            ->where('status', 'ativo')
            ->with('nutrientes')
            ->get()
            ->keyBy('id');

        if ($foods->count() !== $quantities->count()) {
            throw ValidationException::withMessages(['items' => 'Um ou mais alimentos não estão disponíveis.']);
        }

        $pivot = [];
        foreach ($quantities as $foodId => $quantity) {
            $food = $foods[(int) $foodId];
            $pivot[(int) $foodId] = [
                'qtd' => $quantity,
                'descricao_snapshot' => $food->descricao,
                'nome_exibicao_snapshot' => $food->nome_exibicao,
                'detalhe_exibicao_snapshot' => $food->detalhe_exibicao,
                'qtd_base_snapshot' => $food->qtd,
                'proteina_snapshot' => $food->proteina,
                'gordura_snapshot' => $food->gordura,
                'carbo_snapshot' => $food->carbo,
                'caloria_snapshot' => $food->caloria,
                'nutrientes_snapshot' => $food->nutrientes->map(fn ($nutrient) => [
                    'codigo' => $nutrient->codigo,
                    'nome' => $nutrient->nome,
                    'unidade' => $nutrient->unidade,
                    'valor' => (float) $nutrient->pivot->valor,
                ])->values()->all(),
            ];
        }

        $entry->alimentos()->sync($pivot);
    }
}
