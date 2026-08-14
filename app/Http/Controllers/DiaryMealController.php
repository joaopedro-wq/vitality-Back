<?php

namespace App\Http\Controllers;

use App\Http\Requests\Diary\StoreMealRequest;
use App\Http\Requests\Diary\UpdateMealRequest;
use App\Http\Resources\MealResource;
use App\Models\Refeicao;
use App\Services\MealPresetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DiaryMealController extends Controller
{
    public function index(Request $request, MealPresetService $presets)
    {
        $presets->ensureFor($request->user());
        $includeArchived = $request->boolean('include_archived');
        $meals = Refeicao::query()
            ->where('id_usuario', $request->user()->id)
            ->when(! $includeArchived, fn ($query) => $query->whereNull('archived_at'))
            ->orderBy('ordem')
            ->orderBy('horario')
            ->get();

        return MealResource::collection($meals)->additional(['success' => true]);
    }

    public function store(StoreMealRequest $request)
    {
        $meal = Refeicao::create($request->validated() + [
            'id_usuario' => $request->user()->id,
            'horario' => substr($request->validated('horario'), 0, 5).':00',
            'ordem' => $request->validated('ordem', 999),
        ]);

        return (new MealResource($meal))->additional(['success' => true])->response()->setStatusCode(201);
    }

    public function show(Request $request, Refeicao $meal)
    {
        $this->ensureVisible($request, $meal);

        return new MealResource($meal);
    }

    public function update(UpdateMealRequest $request, Refeicao $meal)
    {
        $this->ensureVisible($request, $meal);
        $values = $request->validated();
        if (isset($values['horario'])) {
            $values['horario'] = substr($values['horario'], 0, 5).':00';
        }
        $meal->update($values);

        return (new MealResource($meal->fresh()))->additional(['success' => true]);
    }

    public function destroy(Request $request, Refeicao $meal)
    {
        $this->ensureVisible($request, $meal);
        $meal->update(['archived_at' => now()]);

        return response()->noContent();
    }

    private function ensureVisible(Request $request, Refeicao $meal): void
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $meal), 404);
    }
}
