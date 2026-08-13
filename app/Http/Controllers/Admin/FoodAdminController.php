<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFoodRequest;
use App\Http\Requests\Admin\UpdateFoodRequest;
use App\Http\Resources\FoodResource;
use App\Models\Alimento;
use App\Services\FoodCatalogService;
use Illuminate\Http\Request;

class FoodAdminController extends Controller
{
    public function index(Request $request, FoodCatalogService $catalog)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'fonte' => ['nullable', 'in:taco,manual,legado,usda'],
            'status' => ['nullable', 'in:ativo,pendente,arquivado'],
        ]);
        $foods = Alimento::query()
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where('nome_normalizado', 'like', '%'.$catalog->normalizeName($search).'%'))
            ->when($filters['fonte'] ?? null, fn ($query, $fonte) => $query->where('fonte', $fonte))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('descricao')->paginate(30);
        return FoodResource::collection($foods);
    }

    public function duplicates(Request $request, FoodCatalogService $catalog)
    {
        $data = $request->validate(['descricao' => ['required', 'string', 'max:255']]);
        $foods = Alimento::where('nome_normalizado', 'like', '%'.$catalog->normalizeName($data['descricao']).'%')->limit(8)->get();
        return FoodResource::collection($foods);
    }

    public function store(StoreFoodRequest $request, FoodCatalogService $catalog)
    {
        $food = Alimento::create($request->validated() + [
            'fonte' => 'manual', 'status' => 'ativo', 'nome_normalizado' => $catalog->normalizeName($request->string('descricao')->toString()),
            'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
        ]);
        return (new FoodResource($food))->additional(['success' => true, 'message' => 'Alimento criado com sucesso.']);
    }

    public function update(UpdateFoodRequest $request, Alimento $food, FoodCatalogService $catalog)
    {
        $values = $request->validated();
        $values['nome_normalizado'] = $catalog->normalizeName($values['descricao']);
        $values['updated_by'] = $request->user()->id;
        $food->update($values);
        return (new FoodResource($food))->additional(['success' => true]);
    }

    public function archive(Request $request, Alimento $food)
    {
        $food->update(['status' => 'arquivado', 'updated_by' => $request->user()->id]);
        return (new FoodResource($food))->additional(['success' => true]);
    }

    public function restore(Request $request, Alimento $food)
    {
        $food->update(['status' => 'ativo', 'updated_by' => $request->user()->id]);
        return (new FoodResource($food))->additional(['success' => true]);
    }

    public function importTaco(FoodCatalogService $catalog)
    {
        return response()->json(['data' => $catalog->importTaco(), 'success' => true]);
    }
}
