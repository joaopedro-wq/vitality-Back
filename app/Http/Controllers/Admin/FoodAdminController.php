<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFoodRequest;
use App\Http\Requests\Admin\UpdateFoodRequest;
use App\Http\Resources\FoodResource;
use App\Http\Resources\FoodImageResource;
use App\Models\Alimento;
use App\Models\FoodImage;
use App\Models\FoodPlanTag;
use App\Models\FoodRestriction;
use App\Services\FoodCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            ->with(['publishedImage', 'planTags', 'restrictions'])
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
        $validated = $request->validated();
        $food = Alimento::create($validated + [
            'fonte' => 'manual', 'status' => 'ativo', 'nome_normalizado' => $catalog->normalizeName($request->string('descricao')->toString()),
            'grupo_normalizado' => $catalog->normalizeGroup($validated['grupo'] ?? null),
            'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
        ]);
        return (new FoodResource($food))->additional(['success' => true, 'message' => 'Alimento criado com sucesso.']);
    }

    public function update(UpdateFoodRequest $request, Alimento $food, FoodCatalogService $catalog)
    {
        $values = $request->validated();
        $values['nome_normalizado'] = $catalog->normalizeName($values['descricao']);
        // `grupo_normalizado` é derivado do `grupo` recebido — se este PUT não
        // mexer em `grupo`, mantém o que já está gravado no alimento.
        $values['grupo_normalizado'] = $catalog->normalizeGroup($values['grupo'] ?? $food->grupo);
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

    public function planTags()
    {
        return response()->json(['data' => FoodPlanTag::query()->orderBy('label')->get(['id', 'slug', 'label']), 'success' => true]);
    }

    public function updatePlanTags(Request $request, Alimento $food)
    {
        $data = $request->validate(['slugs' => ['present', 'array'], 'slugs.*' => ['string', 'exists:food_plan_tags,slug']]);
        $ids = FoodPlanTag::query()->whereIn('slug', $data['slugs'])->pluck('id');
        $food->planTags()->sync($ids);
        return (new FoodResource($food->fresh('planTags')))->additional(['success' => true]);
    }

    public function restrictions()
    {
        return response()->json(['data' => FoodRestriction::query()->orderBy('type')->orderBy('label')->get(['id', 'slug', 'label', 'type']), 'success' => true]);
    }

    public function updateRestrictions(Request $request, Alimento $food)
    {
        $data = $request->validate(['slugs' => ['present', 'array'], 'slugs.*' => ['string', 'exists:food_restrictions,slug']]);
        $food->restrictions()->sync(FoodRestriction::query()->whereIn('slug', $data['slugs'])->pluck('id'));
        return (new FoodResource($food->fresh('restrictions')))->additional(['success' => true]);
    }

    public function images(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', 'in:pending,published,rejected,failed,superseded']]);
        $images = FoodImage::query()->with(['alimento.publishedImage'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('updated_at')->paginate(30);
        return FoodImageResource::collection($images);
    }

    public function approveImage(Request $request, FoodImage $image)
    {
        abort_unless($image->path, 422, 'A imagem ainda não está disponível no storage.');
        DB::transaction(function () use ($request, $image): void {
            FoodImage::where('alimento_id', $image->alimento_id)->where('status', 'published')
                ->where('id', '!=', $image->id)->update(['status' => 'superseded', 'rejection_reason' => 'Substituída por aprovação administrativa.']);
            $image->update(['status' => 'published', 'rejection_reason' => null, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        });
        return (new FoodImageResource($image->fresh('alimento.publishedImage')))->additional(['success' => true]);
    }

    public function rejectImage(Request $request, FoodImage $image)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $image->update(['status' => 'rejected', 'rejection_reason' => $data['reason'] ?? 'Recusada por administrador.', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        return (new FoodImageResource($image))->additional(['success' => true]);
    }

    public function removeImage(Request $request, Alimento $food)
    {
        $food->publishedImage?->update(['status' => 'superseded', 'rejection_reason' => 'Removida por administrador.', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        return response()->noContent();
    }
}
