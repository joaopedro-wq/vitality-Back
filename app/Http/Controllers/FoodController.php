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
            'grupo' => ['nullable', 'array'],
            'grupo.*' => ['string', 'max:120'],
            'caloria_min' => ['nullable', 'numeric', 'min:0'],
            'caloria_max' => ['nullable', 'numeric', 'min:0', 'gte:caloria_min'],
            'sort_field' => ['nullable', 'in:descricao,grupo,caloria,proteina,carbo,gordura'],
            'sort_order' => ['nullable', 'in:asc,desc'],
        ]);
        $user = $request->user();
        $foods = Alimento::query()
            ->where('status', 'ativo')
            ->when($validated['search'] ?? null, fn ($query, $search) => $query->where('nome_normalizado', 'like', '%'.app(\App\Services\FoodCatalogService::class)->normalizeName($search).'%'))
            ->when(($validated['tab'] ?? 'all') === 'favorites', fn ($query) => $query->whereHas('userPreferences', fn ($preference) => $preference->where('user_id', $user->id)->where('is_favorite', true)))
            ->when($validated['grupo'] ?? null, fn ($query, $grupos) => $query->whereIn('grupo', $grupos))
            ->when($validated['caloria_min'] ?? null, fn ($query, $min) => $query->where('caloria', '>=', $min))
            ->when($validated['caloria_max'] ?? null, fn ($query, $max) => $query->where('caloria', '<=', $max))
            ->withExists(['userPreferences as is_favorite' => fn ($preference) => $preference->where('user_id', $user->id)->where('is_favorite', true)])
            ->with('publishedImage')
            ->orderBy($validated['sort_field'] ?? 'descricao', $validated['sort_order'] ?? 'asc')
            ->paginate(20);

        return FoodResource::collection($foods);
    }

   
    public function groups()
    {
        $grupos = Alimento::query()
            ->where('status', 'ativo')
            ->whereNotNull('grupo')
            ->where('grupo', '!=', '')
            ->select('grupo')
            ->selectRaw('count(*) as total')
            ->groupBy('grupo')
            ->orderBy('grupo')
            ->get();

        return response()->json([
            'data' => $grupos,
            'success' => true,
        ]);
    }

    public function show(Request $request, Alimento $food)
    {
        abort_unless($food->status === 'ativo', 404);
        $food->load('nutrientes', 'publishedImage');
        $food->loadExists(['userPreferences as is_favorite' => fn ($preference) => $preference->where('user_id', $request->user()->id)->where('is_favorite', true)]);
        return new FoodResource($food);
    }

    public function favorite(Request $request, Alimento $food)
    {
        abort_unless($food->status === 'ativo', 404);
        UserFood::updateOrCreate(['user_id' => $request->user()->id, 'food_id' => $food->id], ['is_favorite' => true]);
        $food->setAttribute('is_favorite', true);
        $food->load('publishedImage');
        return (new FoodResource($food))->additional(['success' => true]);
    }

    public function unfavorite(Request $request, Alimento $food)
    {
        UserFood::where('user_id', $request->user()->id)->where('food_id', $food->id)->delete();
        return response()->noContent();
    }
}
