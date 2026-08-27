<?php

namespace App\Http\Controllers;

use App\Models\MealPlanProfile;
use App\Services\MealPlanFeasibilityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MealPlanProfileController extends Controller
{
    public function show(Request $request)
    {
        $profile = MealPlanProfile::firstOrCreate(['user_id' => $request->user()->id], $this->defaults());

        return response()->json(['data' => $this->serialize($profile), 'success' => true]);
    }

    public function update(Request $request)
    {
        $data = $this->validateProfile($request);
        $profile = MealPlanProfile::updateOrCreate(['user_id' => $request->user()->id], [
            'diet_type' => $data['diet_type'], 'restriction_slugs' => $data['restriction_slugs'],
            'preferences' => collect($data)->only(['meal_count', 'meal_times', 'style', 'excluded_food_ids', 'included_food_ids'])->all(),
        ]);

        return response()->json(['data' => $this->serialize($profile), 'success' => true]);
    }

    public function restrictions(MealPlanFeasibilityService $feasibility)
    {
        $defaults = $this->defaults();
        $preferences = [
            ...$defaults['preferences'],
            'diet_type' => $defaults['diet_type'],
            'restriction_slugs' => $defaults['restriction_slugs'],
        ];

        return response()->json(['data' => $feasibility->describe($preferences)['restrictions'], 'success' => true]);
    }

    public function feasibility(Request $request, MealPlanFeasibilityService $feasibility)
    {
        $data = $request->validate([
            'meal_count' => ['required', 'integer', 'in:3,4,5'],
            'meal_times' => ['required', 'array', 'min:3', 'max:5'],
            'meal_times.*' => ['required', 'date_format:H:i'],
            'style' => ['required', 'in:rapido,caseiro,economico'],
            'diet_type' => ['required', 'in:onivora,vegetariana'],
            'restriction_slugs' => ['present', 'array', 'max:10'],
            'restriction_slugs.*' => ['string', 'exists:food_restrictions,slug'],
            'excluded_food_ids' => ['present', 'array', 'max:30'],
            'excluded_food_ids.*' => ['integer', 'exists:alimentos,id'],
        ]);

        return response()->json(['data' => $feasibility->describe($data), 'success' => true]);
    }

    private function validateProfile(Request $request): array
    {
        return $request->validate([
            'meal_count' => ['required', 'integer', 'in:3,4,5'], 'meal_times' => ['required', 'array', 'min:3', 'max:5'], 'meal_times.*' => ['required', 'date_format:H:i'],
            'style' => ['required', 'in:rapido,caseiro,economico'], 'diet_type' => ['required', 'in:onivora,vegetariana'],
            'restriction_slugs' => ['present', 'array', 'max:10'], 'restriction_slugs.*' => ['string', 'exists:food_restrictions,slug'],
            'excluded_food_ids' => ['present', 'array', 'max:30'], 'excluded_food_ids.*' => ['integer', 'exists:alimentos,id'],
            'included_food_ids' => ['present', 'array', 'max:8'],
            'included_food_ids.*' => ['integer', 'exists:alimentos,id', Rule::notIn($request->input('excluded_food_ids', []))],
        ]);
    }

    private function defaults(): array
    {
        return ['diet_type' => 'onivora', 'restriction_slugs' => [], 'preferences' => ['meal_count' => 4, 'meal_times' => ['08:00', '12:30', '16:30', '20:00'], 'style' => 'rapido', 'excluded_food_ids' => [], 'included_food_ids' => []]];
    }

    private function serialize(MealPlanProfile $profile): array
    {
        $dietType = $profile->diet_type === 'vegana' ? 'vegetariana' : $profile->diet_type;
        $restrictionSlugs = collect($profile->restriction_slugs ?? [])
            ->reject(fn (string $slug) => $slug === 'vegano')
            ->values()
            ->all();

        return ['diet_type' => $dietType, 'restriction_slugs' => $restrictionSlugs, ...($profile->preferences ?? $this->defaults()['preferences'])];
    }
}
