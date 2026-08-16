<?php

namespace App\Http\Controllers;

use App\Models\MealPlan;
use App\Models\MealPlanDraft;
use App\Services\GeminiMealPlanService;
use Illuminate\Http\Request;

class MealPlanController extends Controller
{
    public function index(Request $request)
    {
        $plans = MealPlan::query()->where('user_id', $request->user()->id)->whereNull('archived_at')->with('meals.items')->latest()->get()->map(fn (MealPlan $plan) => $this->serialize($plan));

        return response()->json(['data' => $plans, 'success' => true]);
    }

    public function preview(Request $request, GeminiMealPlanService $generator)
    {
        $draft = $generator->preview($request->user(), $this->preferences($request));

        return response()->json(['data' => $this->serializeDraft($draft), 'success' => true]);
    }

    public function regenerateMeal(Request $request, int $position, GeminiMealPlanService $generator)
    {
        $data = $request->validate(['draft_id' => ['required', 'uuid'], 'instruction' => ['nullable', 'string', 'max:350']]);
        $draft = MealPlanDraft::query()->whereKey($data['draft_id'])->where('user_id', $request->user()->id)->firstOrFail();
        if ($draft->expires_at->isPast()) {
            abort(422, 'Esta prévia expirou. Gere um novo plano.');
        }
        $draft = $generator->replaceMeal($request->user(), $draft, $position, $data['instruction'] ?? null);

        return response()->json(['data' => $this->serializeDraft($draft), 'success' => true]);
    }

    public function itemSuggestions(Request $request, int $position, int $foodId, GeminiMealPlanService $generator)
    {
        $data = $request->validate(['draft_id' => ['required', 'uuid']]);
        $draft = MealPlanDraft::query()->whereKey($data['draft_id'])->where('user_id', $request->user()->id)->firstOrFail();

        return response()->json(['data' => $generator->itemSuggestions($request->user(), $draft, $position, $foodId), 'success' => true]);
    }

    public function replaceItem(Request $request, int $position, int $foodId, GeminiMealPlanService $generator)
    {
        $data = $request->validate(['draft_id' => ['required', 'uuid'], 'replacement_food_id' => ['required', 'integer', 'exists:alimentos,id'], 'quantity' => ['required', 'numeric', 'min:1', 'max:800']]);
        $draft = MealPlanDraft::query()->whereKey($data['draft_id'])->where('user_id', $request->user()->id)->firstOrFail();
        $draft = $generator->applyItemReplacement($request->user(), $draft, $position, $foodId, $data['replacement_food_id'], (float) $data['quantity']);

        return response()->json(['data' => $this->serializeDraft($draft), 'success' => true]);
    }

    public function undo(Request $request, GeminiMealPlanService $generator)
    {
        $data = $request->validate(['draft_id' => ['required', 'uuid']]);
        $draft = MealPlanDraft::query()->whereKey($data['draft_id'])->where('user_id', $request->user()->id)->firstOrFail();
        $draft = $generator->undo($request->user(), $draft);

        return response()->json(['data' => $this->serializeDraft($draft), 'success' => true]);
    }

    public function recreate(Request $request, GeminiMealPlanService $generator)
    {
        $data = $request->validate(['draft_id' => ['required', 'uuid']]);
        $draft = MealPlanDraft::query()->whereKey($data['draft_id'])->where('user_id', $request->user()->id)->firstOrFail();
        $newDraft = $generator->preview($request->user(), collect($draft->preferences)->except('objective')->all());

        return response()->json(['data' => $this->serializeDraft($newDraft), 'success' => true]);
    }

    public function editDraft(Request $request, MealPlan $mealPlan, GeminiMealPlanService $generator)
    {
        $draft = $generator->clonePlan($request->user(), $mealPlan);

        return response()->json(['data' => $this->serializeDraft($draft), 'success' => true]);
    }

    public function store(Request $request, GeminiMealPlanService $generator)
    {
        $data = $request->validate(['titulo' => ['required', 'string', 'max:120'], 'draft_id' => ['required', 'uuid']]);
        $draft = MealPlanDraft::query()->whereKey($data['draft_id'])->where('user_id', $request->user()->id)->firstOrFail();
        $plan = $generator->save($request->user(), $draft, $data['titulo']);

        return response()->json(['data' => $this->serialize($plan), 'success' => true], 201);
    }

    public function archive(Request $request, MealPlan $mealPlan)
    {
        abort_unless($mealPlan->user_id === $request->user()->id, 404);
        $mealPlan->update(['archived_at' => now()]);

        return response()->noContent();
    }

    private function preferences(Request $request): array
    {
        return $request->validate([
            'meal_count' => ['required', 'integer', 'in:3,4,5'], 'meal_times' => ['required', 'array', 'min:3', 'max:5'], 'meal_times.*' => ['required', 'date_format:H:i'],
            'style' => ['required', 'in:rapido,caseiro,economico'], 'diet_type' => ['required', 'in:onivora,vegetariana,vegana'],
            'restriction_slugs' => ['present', 'array', 'max:7'], 'restriction_slugs.*' => ['string', 'exists:food_restrictions,slug'],
            'excluded_food_ids' => ['present', 'array', 'max:30'], 'excluded_food_ids.*' => ['integer', 'exists:alimentos,id'],
        ]);
    }

    private function serialize(MealPlan $plan): array
    {
        return ['id' => $plan->id, 'titulo' => $plan->titulo, 'preferences' => $plan->preferences, 'target' => $plan->target, 'totals' => $plan->totals, 'within_target' => ! $plan->warning, 'warning' => $plan->warning, 'archived_at' => $plan->archived_at?->toISOString(), 'created_at' => $plan->created_at?->toISOString(), 'updated_at' => $plan->updated_at?->toISOString(), 'meals' => $plan->meals->map(fn ($meal) => ['id' => $meal->id, 'position' => $meal->position, 'descricao' => $meal->descricao, 'horario' => substr((string) $meal->horario, 0, 5), 'target' => $meal->target, 'totals' => $meal->totals, 'items' => $meal->items->map(fn ($item) => ['id' => $item->id, 'food_id' => $item->food_id, 'descricao' => $item->descricao_snapshot, 'role' => $item->culinary_role, 'quantity' => (float) $item->quantity, 'macros' => $item->macros])->values()])->values()];
    }

    private function serializeDraft(MealPlanDraft $draft): array
    {
        return ['draft_id' => $draft->id, 'can_undo' => $draft->previous_payload !== null, ...$draft->payload];
    }
}
