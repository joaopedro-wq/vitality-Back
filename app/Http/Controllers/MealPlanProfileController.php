<?php

namespace App\Http\Controllers;

use App\Models\FoodRestriction;
use App\Models\MealPlanProfile;
use Illuminate\Http\Request;

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
            'preferences' => collect($data)->only(['meal_count', 'meal_times', 'style', 'excluded_food_ids'])->all(),
        ]);
        return response()->json(['data' => $this->serialize($profile), 'success' => true]);
    }

    public function restrictions()
    {
        return response()->json(['data' => FoodRestriction::query()->orderBy('type')->orderBy('label')->get(['slug', 'label', 'type']), 'success' => true]);
    }

    private function validateProfile(Request $request): array
    {
        return $request->validate([
            'meal_count' => ['required', 'integer', 'in:3,4,5'], 'meal_times' => ['required', 'array', 'min:3', 'max:5'], 'meal_times.*' => ['required', 'date_format:H:i'],
            'style' => ['required', 'in:rapido,caseiro,economico'], 'diet_type' => ['required', 'in:onivora,vegetariana,vegana'],
            'restriction_slugs' => ['present', 'array', 'max:7'], 'restriction_slugs.*' => ['string', 'exists:food_restrictions,slug'],
            'excluded_food_ids' => ['present', 'array', 'max:30'], 'excluded_food_ids.*' => ['integer', 'exists:alimentos,id'],
        ]);
    }

    private function defaults(): array
    {
        return ['diet_type' => 'onivora', 'restriction_slugs' => [], 'preferences' => ['meal_count' => 4, 'meal_times' => ['08:00', '12:30', '16:30', '20:00'], 'style' => 'rapido', 'excluded_food_ids' => []]];
    }

    private function serialize(MealPlanProfile $profile): array
    {
        return ['diet_type' => $profile->diet_type, 'restriction_slugs' => $profile->restriction_slugs ?? [], ...($profile->preferences ?? $this->defaults()['preferences'])];
    }
}
