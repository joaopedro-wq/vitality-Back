<?php

namespace App\Http\Controllers;

use App\Http\Resources\FoodResource;
use App\Models\Alimento;
use App\Models\UserFood;
use Illuminate\Http\Request;

class FoodController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'tab' => ['nullable', 'in:all,favorites'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $user = $request->user();
        $foods = Alimento::query()
            ->where('status', 'ativo')
            ->when($validated['search'] ?? null, fn ($query, $search) => $query->where('nome_normalizado', 'like', '%'.app(\App\Services\FoodCatalogService::class)->normalizeName($search).'%'))
            ->when(($validated['tab'] ?? 'all') === 'favorites', fn ($query) => $query->whereHas('userPreferences', fn ($preference) => $preference->where('user_id', $user->id)->where('is_favorite', true)))
            ->withExists(['userPreferences as is_favorite' => fn ($preference) => $preference->where('user_id', $user->id)->where('is_favorite', true)])
            ->orderBy('descricao')
            ->paginate(20);

        return FoodResource::collection($foods);
    }

    public function show(Request $request, Alimento $food)
    {
        abort_unless($food->status === 'ativo', 404);
        $food->load('nutrientes');
        $food->loadExists(['userPreferences as is_favorite' => fn ($preference) => $preference->where('user_id', $request->user()->id)->where('is_favorite', true)]);
        return new FoodResource($food);
    }

    public function favorite(Request $request, Alimento $food)
    {
        abort_unless($food->status === 'ativo', 404);
        UserFood::updateOrCreate(['user_id' => $request->user()->id, 'food_id' => $food->id], ['is_favorite' => true]);
        $food->setAttribute('is_favorite', true);
        return (new FoodResource($food))->additional(['success' => true]);
    }

    public function unfavorite(Request $request, Alimento $food)
    {
        UserFood::where('user_id', $request->user()->id)->where('food_id', $food->id)->delete();
        return response()->noContent();
    }
}
