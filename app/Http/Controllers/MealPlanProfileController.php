<?php

namespace App\Http\Controllers;

use App\Models\FoodRestriction;
use App\Models\MealPlanProfile;
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

    public function restrictions()
    {
        $restrictions = FoodRestriction::query()
            ->leftJoin('alimento_food_restriction as pivot', 'pivot.food_restriction_id', '=', 'food_restrictions.id')
            ->selectRaw('food_restrictions.slug, food_restrictions.label, food_restrictions.type, count(pivot.alimento_id) as food_count')
            ->groupBy('food_restrictions.id', 'food_restrictions.slug', 'food_restrictions.label', 'food_restrictions.type')
            ->orderBy('food_restrictions.type')->orderBy('food_restrictions.label')->get()
            ->map(fn ($restriction) => ['slug' => $restriction->slug, 'label' => $restriction->label, 'type' => $restriction->type, 'food_count' => (int) $restriction->food_count, 'available' => (int) $restriction->food_count >= 8]);

        return response()->json(['data' => $restrictions, 'success' => true]);
    }

    private function validateProfile(Request $request): array
    {
        return $request->validate([
            'meal_count' => ['required', 'integer', 'in:3,4,5'], 'meal_times' => ['required', 'array', 'min:3', 'max:5'], 'meal_times.*' => ['required', 'date_format:H:i'],
            'style' => ['required', 'in:rapido,caseiro,economico'], 'diet_type' => ['required', 'in:onivora,vegetariana,vegana'],
            'restriction_slugs' => ['present', 'array', 'max:7'], 'restriction_slugs.*' => ['string', 'exists:food_restrictions,slug'],
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
        return ['diet_type' => $profile->diet_type, 'restriction_slugs' => $profile->restriction_slugs ?? [], ...($profile->preferences ?? $this->defaults()['preferences'])];
    }
}
