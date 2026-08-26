<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\UserMissionCompletion;
use App\Services\LevelCatalog;
use App\Services\MissionCatalog;
use App\Services\UserAvatarService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class GroupController extends Controller
{
    public function index(Request $request)
    {
        $groups = $request->user()->groups()->withCount('members')->get();

        $groups->each->makeHidden('pivot');

        $xpTotal = (int) UserMissionCompletion::where('user_id', $request->user()->id)->sum('xp');
        $nivelInfo = LevelCatalog::resolver($xpTotal);

        $groups->each(function (Group $group) use ($request, $xpTotal, $nivelInfo) {
            [$inicio, $fim] = $this->janelaPeriodo($group);

            $xpPeriodo = (int) UserMissionCompletion::where('user_id', $request->user()->id)
                ->when($inicio, fn ($query) => $query->where('completed_at', '>=', $inicio))
                ->when($fim, fn ($query) => $query->where('completed_at', '<=', $fim))
                ->sum('xp');

            // `LevelCatalog::resolver()` devolve `xp` (o mesmo total passado pra ele) — não
            // repete aqui como `xp_total`; só os três campos de nível seguem daquele array.
            $group->voce = [
                'nivel' => $nivelInfo['nivel'],
                'xp_proximo_nivel' => $nivelInfo['xp_proximo_nivel'],
                'progresso_percent' => $nivelInfo['progresso_percent'],
                'xp_total' => $xpTotal,
                'xp_periodo' => $xpPeriodo,
            ];
            $group->members_preview = $group->members()
                ->take(4)
                ->get(['users.id', 'users.name', 'users.avatar'])
                ->map(fn ($membro) => ['id' => $membro->id, 'name' => $membro->name, 'avatar' => UserAvatarService::url($membro->avatar)]);
        });

        return response()->json(['data' => $groups, 'success' => true]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'challenge_type' => ['required', 'string', Rule::in(Group::TIPOS_DESAFIO)],
            'challenge_starts_at' => ['required_if:challenge_type,custom', 'nullable', 'date'],
            'challenge_ends_at' => [
                'required_if:challenge_type,custom',
                'nullable',
                'date',
                'after:challenge_starts_at',
            ],
        ]);

        $group = Group::create([
            'name' => $data['name'],
            'owner_id' => $request->user()->id,
            'challenge_type' => $data['challenge_type'],
            'challenge_starts_at' => $data['challenge_starts_at'] ?? null,
            'challenge_ends_at' => $data['challenge_ends_at'] ?? null,
        ]);
        $group->members()->attach($request->user()->id, ['joined_at' => now()]);

        return response()->json(['data' => $group, 'success' => true], 201);
    }

    public function show(Request $request, Group $group)
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $group), 404);

        // Colunas restritas de propósito: membros do grupo veem nome/avatar uns dos outros,
        // nunca e-mail/peso/altura/data de nascimento/rotina — o resto do `User` não é assunto
        // de grupo. Mesma restrição que `ranking()` já aplica.
        $group->load(['owner:id,name,avatar', 'members:id,name,avatar']);
        $group->members->each->makeHidden('pivot');
        if ($group->owner) {
            $group->owner->avatar = UserAvatarService::url($group->owner->avatar);
        }
        $group->members->each(fn ($membro) => $membro->avatar = UserAvatarService::url($membro->avatar));

        return response()->json(['data' => $group, 'success' => true]);
    }

    public function destroy(Request $request, Group $group)
    {
        abort_unless(Gate::forUser($request->user())->allows('delete', $group), 404);

        $group->delete();

        return response()->json(['message' => 'Grupo removido', 'success' => true]);
    }

    public function join(Request $request)
    {
        $data = $request->validate(['invite_code' => ['required', 'string']]);

        $group = Group::where('invite_code', strtoupper($data['invite_code']))->first();
        if (! $group) {
            return response()->json(['message' => 'Código de convite inválido', 'success' => false], 404);
        }

        $group->members()->syncWithoutDetaching([$request->user()->id => ['joined_at' => now()]]);

        $group->load('members:id,name,avatar');
        $group->members->each->makeHidden('pivot');
        $group->members->each(fn ($membro) => $membro->avatar = UserAvatarService::url($membro->avatar));

        return response()->json(['data' => $group, 'success' => true]);
    }

    public function leave(Request $request, Group $group)
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $group), 404);

        if ($group->is_global) {
            return response()->json([
                'message' => 'Não é possível sair do grupo Vitality',
                'success' => false,
            ], 422);
        }

        if ($group->owner_id === $request->user()->id) {
            return response()->json([
                'message' => 'O dono não pode sair do grupo, apenas excluí-lo',
                'success' => false,
            ], 422);
        }

        $group->members()->detach($request->user()->id);

        return response()->json(['message' => 'Você saiu do grupo', 'success' => true]);
    }

    /**
     * Ranking de XP dos membros do grupo. Não existe tabela de progresso própria: o XP já é
     * calculado organicamente pelo sistema de missões (`UserMissionCompletion`), o mesmo dado
     * que alimenta `DashboardService::progressao()` — aqui só agregamos por membro do grupo.
     *
     * A janela do "XP do período" (`xp_periodo`) depende de `challenge_type`, escolhido na
     * criação do grupo — filtra sempre por `completed_at`, nunca por `period_key`: `period_key`
     * tem formato diferente por escopo de missão (data para `diaria`, segunda-feira para
     * `semanal`, `'once'` para `marco`), então filtrar por ele daria janela errada.
     */
    public function ranking(Request $request, Group $group)
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $group), 404);

        [$inicio, $fim] = $this->janelaPeriodo($group);

        $membros = $group->members()->get(['users.id', 'users.name', 'users.avatar']);
        $ids = $membros->pluck('id');

        $xpTotalPorUsuario = UserMissionCompletion::query()
            ->whereIn('user_id', $ids)
            ->selectRaw('user_id, SUM(xp) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $xpPeriodoPorUsuario = UserMissionCompletion::query()
            ->whereIn('user_id', $ids)
            ->when($inicio, fn ($query) => $query->where('completed_at', '>=', $inicio))
            ->when($fim, fn ($query) => $query->where('completed_at', '<=', $fim))
            ->selectRaw('user_id, SUM(xp) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $ranking = $membros
            ->map(function ($membro) use ($xpTotalPorUsuario, $xpPeriodoPorUsuario) {
                $xpTotal = (int) ($xpTotalPorUsuario[$membro->id] ?? 0);
                $nivelInfo = LevelCatalog::resolver($xpTotal);

                return [
                    'user' => ['id' => $membro->id, 'name' => $membro->name, 'avatar' => UserAvatarService::url($membro->avatar)],
                    'nivel' => $nivelInfo['nivel'],
                    'xp_proximo_nivel' => $nivelInfo['xp_proximo_nivel'],
                    'progresso_percent' => $nivelInfo['progresso_percent'],
                    'xp_periodo' => (int) ($xpPeriodoPorUsuario[$membro->id] ?? 0),
                    'xp_total' => $xpTotal,
                ];
            })
            ->sortByDesc('xp_periodo')
            ->values();

        return response()->json(['data' => $ranking, 'success' => true]);
    }

    /**
     * Feed de atividade do grupo: "como cada um está indo", sem inventar nada — são as mesmas
     * missões cumpridas que já viram XP no ranking, só apresentadas como eventos em vez de soma.
     * `MissionCatalog::definicoes()` já devolve `titulo`/`icone` traduzidos (via `__()`), então o
     * feed sai no idioma certo pelo mesmo `Accept-Language` que o resto da API já respeita.
     */
    public function activity(Request $request, Group $group)
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $group), 404);

        $ids = $group->members()->pluck('users.id');
        $catalogoPorCodigo = collect(MissionCatalog::definicoes())->keyBy('codigo');

        $itens = UserMissionCompletion::with('user:id,name,avatar')
            ->whereIn('user_id', $ids)
            ->orderByDesc('completed_at')
            ->limit(30)
            ->get()
            ->map(function (UserMissionCompletion $conclusao) use ($catalogoPorCodigo) {
                $missao = $catalogoPorCodigo->get($conclusao->mission_code);

                return [
                    'user' => [
                        'id' => $conclusao->user->id,
                        'name' => $conclusao->user->name,
                        'avatar' => UserAvatarService::url($conclusao->user->avatar),
                    ],
                    'titulo' => $missao['titulo'] ?? $conclusao->mission_code,
                    'marco' => ($missao['escopo'] ?? null) === MissionCatalog::ESCOPO_MARCO,
                    'xp' => $conclusao->xp,
                    'completed_at' => $conclusao->completed_at,
                ];
            });

        return response()->json(['data' => $itens, 'success' => true]);
    }

    /**
     * Janela de XP do grupo conforme `challenge_type` — compartilhada por `index()` e
     * `ranking()`. `null`/`null` (caso `all_time`) significa "sem filtro de data".
     *
     * @return array{0: \Carbon\CarbonInterface|null, 1: \Carbon\CarbonInterface|null}
     */
    private function janelaPeriodo(Group $group): array
    {
        $agora = CarbonImmutable::now('America/Sao_Paulo');

        return match ($group->challenge_type) {
            'weekly' => [$agora->startOfWeek(CarbonImmutable::MONDAY), null],
            'monthly' => [$agora->startOfMonth(), null],
            'custom' => [$group->challenge_starts_at, $group->challenge_ends_at],
            default => [null, null], // all_time
        };
    }
}
