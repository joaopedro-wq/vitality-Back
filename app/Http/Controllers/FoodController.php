<?php

namespace App\Http\Controllers;

use App\Http\Resources\FoodResource;
use App\Models\Alimento;
use App\Models\UserFood;
use App\Services\FoodCatalogService;
use Illuminate\Http\Request;

class FoodController extends Controller
{
    public function index(Request $request, FoodCatalogService $catalog)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'tab' => ['nullable', 'in:all,favorites'],
            'page' => ['nullable', 'integer', 'min:1'],
            'grupo' => ['nullable', 'array'],
            'grupo.*' => ['string', 'max:120'],
            'grupo_normalizado' => ['nullable', 'array'],
            'grupo_normalizado.*' => ['string', 'max:40'],
            'grupo_exibicao' => ['nullable', 'array'],
            'grupo_exibicao.*' => ['string', 'max:60'],

            'categoria' => ['nullable', 'array'],
            'categoria.*' => ['string', 'max:60'],
            'caloria_min' => ['nullable', 'numeric', 'min:0'],
            'caloria_max' => ['nullable', 'numeric', 'min:0', 'gte:caloria_min'],
            'sort_field' => ['nullable', 'in:descricao,grupo,caloria,proteina,carbo,gordura'],
            'sort_order' => ['nullable', 'in:asc,desc'],
        ]);
        $user = $request->user();
        $sortField = $validated['sort_field'] ?? 'descricao';
        $sortOrder = ($validated['sort_order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $foods = Alimento::query()
            ->where('status', 'ativo')
            ->when($validated['search'] ?? null, function ($query, $search) use ($catalog) {
                $normalized = $catalog->normalizeName($search);

                // Cruza técnico + amigável pt-BR + amigável en-US sempre, independente
                // do locale ativo — um alimento buscável em qualquer um dos três nomes,
                // não só no idioma da tela no momento.
                return $query->where(fn ($sub) => $sub
                    ->where('nome_normalizado', 'like', '%'.$normalized.'%')
                    ->orWhere('nome_exibicao_normalizado', 'like', '%'.$normalized.'%')
                    ->orWhere('nome_exibicao_en_normalizado', 'like', '%'.$normalized.'%')
                    ->orWhereHas('aliases', fn ($aliases) => $aliases->where('normalized', 'like', '%'.$normalized.'%')));
            })
            ->when(($validated['tab'] ?? 'all') === 'favorites', fn ($query) => $query->whereHas('userPreferences', fn ($preference) => $preference->where('user_id', $user->id)->where('is_favorite', true)))
            ->when($validated['grupo'] ?? null, fn ($query, $grupos) => $query->whereIn('grupo', $grupos))
            ->when($validated['grupo_normalizado'] ?? null, fn ($query, $grupos) => $query->whereIn('grupo_normalizado', $grupos))
            ->when($validated['grupo_exibicao'] ?? null, fn ($query, $grupos) => $query->whereIn('grupo_exibicao', $grupos))
            ->when($validated['categoria'] ?? null, function ($query, $slugs) use ($catalog) {
                $labels = collect($slugs)->map(fn ($slug) => $catalog->displayGroupLabelForSlug($slug) ?? $slug)->unique()->values()->all();

                return $query->whereIn('grupo_exibicao', $labels);
            })
            ->when($validated['caloria_min'] ?? null, fn ($query, $min) => $query->where('caloria', '>=', $min))
            ->when($validated['caloria_max'] ?? null, fn ($query, $max) => $query->where('caloria', '<=', $max))
            ->withExists(['userPreferences as is_favorite' => fn ($preference) => $preference->where('user_id', $user->id)->where('is_favorite', true)])
            ->with('publishedImage')
            // `descricao` na API é o nome amigável (nome_exibicao/nome_exibicao_en
            // conforme o locale ativo); ordenar por ele — não pelo texto técnico
            // original — é o que o usuário espera ao ordenar "por nome", no
            // idioma que está vendo na tela.
            ->when($sortField === 'descricao',
                fn ($query) => $query->orderByRaw((app()->getLocale() === 'en-US'
                    ? 'COALESCE(nome_exibicao_en, nome_exibicao, descricao) '
                    : 'COALESCE(nome_exibicao, descricao) ').$sortOrder),
                fn ($query) => $query->orderBy($sortField, $sortOrder))
            ->paginate(20);

        return FoodResource::collection($foods);
    }

    /**
     * `/foods/groups`, `/foods/groups-normalized` e `/foods/groups-display`
     * retornam exatamente a mesma coisa — uma única fonte de verdade para
     * categoria (`FoodCatalogService::displayGroups()`), com `id` (slug
     * estável, usado no filtro `categoria[]`) e `label` (texto amigável para
     * exibição). As três rotas continuam existindo por compatibilidade com
     * quem já as chama; nenhuma mantém uma lista de labels própria.
     */
    public function groups(FoodCatalogService $catalog)
    {
        return response()->json(['data' => $catalog->displayGroups(), 'success' => true]);
    }

    public function gruposNormalizados(FoodCatalogService $catalog)
    {
        return response()->json(['data' => $catalog->displayGroups(), 'success' => true]);
    }

    public function gruposExibicao(FoodCatalogService $catalog)
    {
        return response()->json(['data' => $catalog->displayGroups(), 'success' => true]);
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
