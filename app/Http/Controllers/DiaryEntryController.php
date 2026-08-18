<?php

namespace App\Http\Controllers;

use App\Http\Requests\Diary\StoreDiaryEntryRequest;
use App\Http\Requests\Diary\UpdateDiaryEntryRequest;
use App\Http\Resources\DiaryEntryResource;
use App\Http\Resources\FoodResource;
use App\Models\Alimento;
use App\Models\Refeicao;
use App\Models\Registro;
use App\Services\DiaryEntryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DiaryEntryController extends Controller
{
    public function day(Request $request, DiaryEntryService $diary)
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $data['date'] ?? now('America/Sao_Paulo')->toDateString();
        $entries = $diary->forDay($request->user(), $date);
        $resources = $entries->map(fn (Registro $entry) => (new DiaryEntryResource($entry))->resolve($request));
        $totals = ['proteina' => 0.0, 'gordura' => 0.0, 'carbo' => 0.0, 'caloria' => 0.0, 'quantidade' => 0.0];

        foreach ($resources as $entry) {
            foreach ($totals as $key => $value) {
                $totals[$key] += $entry['totals'][$key];
            }
        }

        return response()->json([
            'data' => ['date' => $date, 'entries' => $resources->values(), 'totals' => collect($totals)->map(fn ($value) => round($value, 3))],
            'success' => true,
        ]);
    }

    public function recentFoods(Request $request)
    {
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:8']]);
        $limit = $data['limit'] ?? 8;
        $foodIds = DB::table('registro_alimentos')
            ->join('registros', 'registros.id', '=', 'registro_alimentos.registro_id')
            ->where('registros.id_usuario', $request->user()->id)
            ->select('registro_alimentos.alimento_id')
            ->selectRaw('max(registros.consumed_at) as last_consumed_at')
            ->groupBy('registro_alimentos.alimento_id')
            ->orderByDesc('last_consumed_at')
            ->limit($limit)
            ->pluck('alimento_id');

        $foods = Alimento::query()
            ->whereIn('id', $foodIds)
            ->where('status', 'ativo')
            ->withExists(['userPreferences as is_favorite' => fn ($preference) => $preference->where('user_id', $request->user()->id)->where('is_favorite', true)])
            ->with('publishedImage')
            ->get()
            ->sortBy(fn (Alimento $food) => $foodIds->search($food->id))
            ->values();

        return FoodResource::collection($foods)->additional(['success' => true]);
    }

    public function store(StoreDiaryEntryRequest $request, DiaryEntryService $diary)
    {
        $entry = $diary->create($request->user(), $request->validated());

        return (new DiaryEntryResource($entry))->additional([
            'success' => true,
            'message' => __('messages.entry_created'),
        ])->response()->setStatusCode(201);
    }

    public function show(Request $request, Registro $entry, DiaryEntryService $diary)
    {
        $this->ensureVisible($request, $entry);

        return new DiaryEntryResource($diary->load($entry));
    }

    public function update(UpdateDiaryEntryRequest $request, Registro $entry, DiaryEntryService $diary)
    {
        $this->ensureVisible($request, $entry);
        $entry = $diary->replace($entry, $request->user(), $request->validated());

        return (new DiaryEntryResource($entry))->additional([
            'success' => true,
            'message' => __('messages.entry_updated'),
        ]);
    }

    public function destroy(Request $request, Registro $entry)
    {
        $this->ensureVisible($request, $entry);
        $entry->delete();

        return response()->noContent();
    }

    public function legacyIndex(Request $request, DiaryEntryService $diary)
    {
        $entries = Registro::query()
            ->where('id_usuario', $request->user()->id)
            ->with(['alimentos', 'refeicao'])
            ->orderByDesc('consumed_at')
            ->get();

        return DiaryEntryResource::collection($entries)->additional(['success' => true]);
    }

    public function legacyStore(Request $request, DiaryEntryService $diary)
    {
        $data = $request->validate([
            'alimentos' => ['required', 'array', 'min:1'],
            'alimentos.*.id' => ['required', 'integer'],
            'alimentos.*.qtd' => ['required', 'numeric', 'gt:0'],
            'data' => ['required', 'date_format:Y-m-d'],
            'id_refeicao' => ['required', 'integer'],
        ]);

        return $this->legacyCreateResponse($request, $diary, $data);
    }

    public function legacyUpdate(Request $request, Registro $entry, DiaryEntryService $diary)
    {
        $this->ensureVisible($request, $entry);
        $data = $request->validate([
            'alimentos' => ['required', 'array', 'min:1'],
            'alimentos.*.id' => ['required', 'integer'],
            'alimentos.*.qtd' => ['required', 'numeric', 'gt:0'],
            'data' => ['required', 'date_format:Y-m-d'],
            'id_refeicao' => ['required', 'integer'],
        ]);
        $payload = $this->legacyPayload($request, $data);
        $updated = $diary->replace($entry, $request->user(), $payload);

        return (new DiaryEntryResource($updated))->additional(['success' => true]);
    }

    private function legacyCreateResponse(Request $request, DiaryEntryService $diary, array $data)
    {
        $entry = $diary->create($request->user(), $this->legacyPayload($request, $data));

        return (new DiaryEntryResource($entry))->additional(['success' => true])->response()->setStatusCode(201);
    }

    private function legacyPayload(Request $request, array $data): array
    {
        $meal = Refeicao::whereKey($data['id_refeicao'])->where('id_usuario', $request->user()->id)->first();
        $time = $meal?->horario ?? '12:00:00';

        return [
            'meal_id' => $data['id_refeicao'],
            'consumed_at' => CarbonImmutable::createFromFormat('Y-m-d H:i:s', $data['data'].' '.$time, 'America/Sao_Paulo')->toIso8601String(),
            'items' => collect($data['alimentos'])->map(fn (array $item) => ['food_id' => $item['id'], 'quantity' => $item['qtd']])->all(),
        ];
    }

    private function ensureVisible(Request $request, Registro $entry): void
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $entry), 404);
    }
}
