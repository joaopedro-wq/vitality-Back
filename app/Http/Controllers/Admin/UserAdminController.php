<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MealPlan;
use App\Models\Registro;
use App\Models\User;
use App\Services\UserAvatarService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserAdminController extends Controller
{
    public function index(Request $request)
    {
        [$from, $to, $filters] = $this->filters($request);
        $users = $this->usersQuery($from, $to, $filters)
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->paginate(25)
            ->withQueryString();

        $data = collect($users->items())->map(fn (User $user) => $this->summary($user, $from, $to));
        $all = $this->usersQuery($from, $to, [])->get()->map(fn (User $user) => $this->summary($user, $from, $to));

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'summary' => [
                    'total_users' => $all->count(),
                    'new_users' => $all->where('engagement_status', 'novo')->count(),
                    'engaged_users' => $all->where('engagement_status', 'engajado')->count(),
                    'inactive_users' => $all->where('engagement_status', 'inativo')->count(),
                ],
            ],
        ]);
    }

    public function show(Request $request, User $user)
    {
        [$from, $to] = $this->filters($request);
        $summary = $this->summary($this->usersQuery($from, $to, [])->whereKey($user)->firstOrFail(), $from, $to);

        return response()->json(['data' => $summary + [
            'recent_diary_entries' => Registro::query()->where('id_usuario', $user->id)->latest('created_at')->limit(5)
                ->get(['id', 'consumed_at', 'created_at', 'descricao_refeicao_snapshot']),
            'recent_meal_plans' => MealPlan::query()->where('user_id', $user->id)->latest('updated_at')->limit(5)
                ->get(['id', 'titulo', 'updated_at', 'created_at']),
        ]]);
    }

    public function destroy(Request $request, User $user)
    {
        abort_if($request->user()->id === $user->id, 422, __('messages.admin_cannot_delete_self'));

        DB::transaction(function () use ($user) {
            DB::table('registros')->where('id_usuario', $user->id)->delete();
            DB::table('dietas')->where('id_usuario', $user->id)->delete();
            DB::table('refeicaos')->where('id_usuario', $user->id)->delete();
            DB::table('meta_diarias')->where('id_usuario', $user->id)->delete();
            DB::table('nutricao_recomendadas')->where('id_usuario', $user->id)->delete();

            DB::table('alimentos')->where('id_usuario', $user->id)->update(['id_usuario' => null]);
            $user->tokens()->delete();
            app(UserAvatarService::class)->remove($user);
            $user->delete();
        });

        return response()->json(['success' => true]);
    }

    private function filters(Request $request): array
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'period' => ['nullable', 'in:7,30,90'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'is_admin' => ['nullable', 'boolean'],
            'engagement_status' => ['nullable', 'in:novo,em_ativacao,engajado,inativo'],
            'sort' => ['nullable', 'in:name,created_at'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);
        $to = isset($filters['date_to'])
            ? CarbonImmutable::parse($filters['date_to'])->endOfDay()
            : CarbonImmutable::now()->endOfDay();
        $from = isset($filters['date_from'])
            ? CarbonImmutable::parse($filters['date_from'])->startOfDay()
            : $to->subDays(((int) ($filters['period'] ?? 30)) - 1)->startOfDay();

        return [$from, $to, $filters];
    }

    private function usersQuery(CarbonImmutable $from, CarbonImmutable $to, array $filters)
    {
        $query = User::query()
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($users) => $users
                ->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
            ->when(array_key_exists('is_admin', $filters), fn ($query) => $query->where('is_admin', $filters['is_admin']))
            ->select('users.*')
            ->selectSub(Registro::query()->selectRaw('COUNT(DISTINCT DATE(created_at))')
                ->whereColumn('id_usuario', 'users.id')->whereBetween('created_at', [$from, $to]), 'active_days')
            ->selectSub(Registro::query()->selectRaw('COUNT(*)')
                ->whereColumn('id_usuario', 'users.id')->whereBetween('created_at', [$from, $to]), 'diary_entries_count')
            ->selectSub(Registro::query()->selectRaw('MAX(created_at)')
                ->whereColumn('id_usuario', 'users.id'), 'last_diary_at')
            ->selectSub(MealPlan::query()->selectRaw('COUNT(*)')
                ->whereColumn('user_id', 'users.id')->whereBetween('updated_at', [$from, $to]), 'plans_count')
            ->selectSub(MealPlan::query()->selectRaw('MAX(updated_at)')
                ->whereColumn('user_id', 'users.id'), 'last_plan_at');

        if ($status = $filters['engagement_status'] ?? null) {
            $this->applyEngagementStatus($query, $status, $from, $to);
        }

        return $query;
    }

    private function applyEngagementStatus($query, string $status, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $withinPeriod = fn ($relation) => $relation->whereBetween('created_at', [$from, $to]);
        $planWithinPeriod = fn ($relation) => $relation->whereBetween('updated_at', [$from, $to]);

        if ($status === 'novo') {
            $query->whereBetween('users.created_at', [$from, $to])
                ->whereDoesntHave('registros', $withinPeriod)
                ->whereDoesntHave('mealPlans', $planWithinPeriod);

            return;
        }

        if ($status === 'inativo') {
            $query->whereNotBetween('users.created_at', [$from, $to])
                ->whereDoesntHave('registros', $withinPeriod)
                ->whereDoesntHave('mealPlans', $planWithinPeriod);

            return;
        }

        if ($status === 'em_ativacao') {
            $query->whereDoesntHave('mealPlans', $planWithinPeriod)
                ->whereRaw('(SELECT COUNT(DISTINCT DATE(created_at)) FROM registros WHERE registros.id_usuario = users.id AND registros.created_at BETWEEN ? AND ?) = 1', [$from, $to]);

            return;
        }

        $query->where(function ($users) use ($planWithinPeriod, $from, $to) {
            $users->whereHas('mealPlans', $planWithinPeriod)
                ->orWhereRaw('(SELECT COUNT(DISTINCT DATE(created_at)) FROM registros WHERE registros.id_usuario = users.id AND registros.created_at BETWEEN ? AND ?) >= 2', [$from, $to]);
        });
    }

    private function summary(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $activeDays = (int) $user->active_days;
        $plans = (int) $user->plans_count;
        $status = $activeDays === 0 && $plans === 0
            ? ($user->created_at->betweenIncluded($from, $to) ? 'novo' : 'inativo')
            : ($activeDays >= 2 || $plans > 0 ? 'engajado' : 'em_ativacao');
        $lastAction = collect([$user->last_diary_at, $user->last_plan_at])->filter()->map(fn ($date) => CarbonImmutable::parse($date))->sortDesc()->first();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => (bool) $user->is_admin,
            'created_at' => $user->created_at?->toISOString(),
            'last_action_at' => $lastAction?->toISOString(),
            'active_days' => $activeDays,
            'diary_entries_count' => (int) $user->diary_entries_count,
            'plans_count' => $plans,
            'engagement_status' => $status,
        ];
    }
}
