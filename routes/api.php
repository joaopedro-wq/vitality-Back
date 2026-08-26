<?php

use App\Http\Controllers\Admin\FoodAdminController;
use App\Http\Controllers\Admin\UserAdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiaryEntryController;
use App\Http\Controllers\DiaryMealController;
use App\Http\Controllers\DietaController;
use App\Http\Controllers\FoodController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\MealPlanController;
use App\Http\Controllers\MealPlanProfileController;
use App\Http\Controllers\MetaDiariaController;
use App\Http\Controllers\NutricaoRecomendadaController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:api-login');
    Route::post('/criar-usuario', [UserController::class, 'storeUser'])->middleware('throttle:api-register');
});

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

    Route::get('/diary/day', [DiaryEntryController::class, 'day']);
    Route::get('/diary/recent-foods', [DiaryEntryController::class, 'recentFoods']);
    Route::post('/diary/entries', [DiaryEntryController::class, 'store']);
    Route::get('/diary/entries/{entry}', [DiaryEntryController::class, 'show']);
    Route::patch('/diary/entries/{entry}', [DiaryEntryController::class, 'update']);
    Route::delete('/diary/entries/{entry}', [DiaryEntryController::class, 'destroy']);
    Route::get('/diary/meals', [DiaryMealController::class, 'index']);
    Route::post('/diary/meals', [DiaryMealController::class, 'store']);
    Route::get('/diary/meals/{meal}', [DiaryMealController::class, 'show']);
    Route::patch('/diary/meals/{meal}', [DiaryMealController::class, 'update']);
    Route::delete('/diary/meals/{meal}', [DiaryMealController::class, 'destroy']);

    // Compatibilidade temporária para consumidores do contrato antigo.
    Route::get('/registro', [DiaryEntryController::class, 'legacyIndex']);
    Route::post('/registro', [DiaryEntryController::class, 'legacyStore']);
    Route::get('/registro/{entry}', [DiaryEntryController::class, 'show']);
    Route::put('/registro/{entry}', [DiaryEntryController::class, 'legacyUpdate']);

    Route::get('/meta', [MetaDiariaController::class, 'index']);
    Route::post('/meta', [MetaDiariaController::class, 'store']);
    Route::get('/meta/{id}', [MetaDiariaController::class, 'show']);
    Route::put('/meta/{id}', [MetaDiariaController::class, 'update']);
    Route::delete('/meta/{id}', [MetaDiariaController::class, 'destroy']);

    Route::get('/recomendacao', [NutricaoRecomendadaController::class, 'index']);
    Route::post('/recomendacao', [NutricaoRecomendadaController::class, 'store']);
    Route::get('/recomendacao/{id}', [NutricaoRecomendadaController::class, 'show']);
    Route::put('/recomendacao/{id}', [NutricaoRecomendadaController::class, 'update']);
    Route::delete('/recomendacao/{id}', [NutricaoRecomendadaController::class, 'destroy']);

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/session/refresh', [AuthController::class, 'refreshSession']);
    // Rotas estáticas ANTES das paramétricas para evitar conflito de match
    Route::get('/user', [UserController::class, 'getWithToken']);
    Route::put('/user', [UserController::class, 'update']);
    Route::put('/user/onboarding', [UserController::class, 'updateOnboarding']);
    Route::post('/user/avatar', [UserController::class, 'updateAvatar'])->middleware('throttle:avatar-upload');
    Route::delete('/user/avatar', [UserController::class, 'destroyAvatar']);

    Route::delete('/registro/{entry}', [DiaryEntryController::class, 'destroy']);

    Route::get('/dieta/{id}', [DietaController::class, 'show']);
    Route::delete('/dieta/{id}', [DietaController::class, 'destroy']);
    Route::put('/dieta/{id}', [DietaController::class, 'update']);
    Route::post('/dieta', [DietaController::class, 'store']);
    Route::get('/dieta', [DietaController::class, 'index']);

    Route::get('/meal-plans', [MealPlanController::class, 'index']);
    Route::get('/meal-plan-profile', [MealPlanProfileController::class, 'show']);
    Route::put('/meal-plan-profile', [MealPlanProfileController::class, 'update']);
    Route::get('/meal-plan-restrictions', [MealPlanProfileController::class, 'restrictions']);
    Route::post('/meal-plan-feasibility', [MealPlanProfileController::class, 'feasibility']);
    Route::post('/meal-plans/preview', [MealPlanController::class, 'preview'])->middleware('throttle:ai-plan');
    Route::post('/meal-plans/preview/recreate', [MealPlanController::class, 'recreate'])->middleware('throttle:ai-plan');
    Route::post('/meal-plans/preview/undo', [MealPlanController::class, 'undo'])->middleware('throttle:ai-plan');
    Route::post('/meal-plans/preview/refresh-locale', [MealPlanController::class, 'refreshLocale'])->middleware('throttle:ai-plan');
    Route::post('/meal-plans/preview/meal/{position}', [MealPlanController::class, 'regenerateMeal'])->middleware('throttle:ai-plan');
    Route::post('/meal-plans/preview/meal/{position}/item/{foodId}/suggestions', [MealPlanController::class, 'itemSuggestions'])->middleware('throttle:ai-plan');
    Route::post('/meal-plans/preview/meal/{position}/item/{foodId}/replace', [MealPlanController::class, 'replaceItem'])->middleware('throttle:ai-plan');
    Route::post('/meal-plans/manual/preview', [MealPlanController::class, 'manualPreview']);
    Route::put('/meal-plans/manual/preview/meal/{position}', [MealPlanController::class, 'manualUpdateMeal']);
    Route::post('/meal-plans/manual/preview/meal', [MealPlanController::class, 'manualAddMeal']);
    Route::delete('/meal-plans/manual/preview/meal/{position}', [MealPlanController::class, 'manualRemoveMeal']);
    Route::post('/meal-plans/{mealPlan}/edit-draft', [MealPlanController::class, 'editDraft']);
    Route::post('/meal-plans', [MealPlanController::class, 'store']);
    Route::put('/meal-plans/{mealPlan}', [MealPlanController::class, 'update']);
    Route::post('/meal-plans/{mealPlan}/archive', [MealPlanController::class, 'archive']);
    Route::post('/meal-plans/{mealPlan}/favorite', [MealPlanController::class, 'favorite']);
    Route::delete('/meal-plans/{mealPlan}/favorite', [MealPlanController::class, 'unfavorite']);
    Route::delete('/meal-plans/{mealPlan}', [MealPlanController::class, 'destroy']);

    Route::get('/foods', [FoodController::class, 'index']);
    Route::get('/foods/groups', [FoodController::class, 'groups']);
    Route::get('/foods/groups-normalized', [FoodController::class, 'gruposNormalizados']);
    Route::get('/foods/groups-display', [FoodController::class, 'gruposExibicao']);
    Route::get('/foods/{food}', [FoodController::class, 'show']);
    Route::post('/foods/{food}/favorite', [FoodController::class, 'favorite']);
    Route::delete('/foods/{food}/favorite', [FoodController::class, 'unfavorite']);

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/users', [UserAdminController::class, 'index']);
        Route::get('/users/{user}', [UserAdminController::class, 'show']);
        Route::get('/foods', [FoodAdminController::class, 'index']);
        Route::get('/foods/duplicates', [FoodAdminController::class, 'duplicates']);
        Route::post('/foods', [FoodAdminController::class, 'store']);
        Route::put('/foods/{food}', [FoodAdminController::class, 'update']);
        Route::post('/foods/{food}/archive', [FoodAdminController::class, 'archive']);
        Route::post('/foods/{food}/restore', [FoodAdminController::class, 'restore']);
        Route::post('/foods/import-taco', [FoodAdminController::class, 'importTaco']);
        Route::post('/foods/import-taco-spreadsheet', [FoodAdminController::class, 'importTacoSpreadsheet']);
        Route::get('/food-plan-tags', [FoodAdminController::class, 'planTags']);
        Route::put('/foods/{food}/plan-tags', [FoodAdminController::class, 'updatePlanTags']);
        Route::get('/food-restrictions', [FoodAdminController::class, 'restrictions']);
        Route::put('/foods/{food}/restrictions', [FoodAdminController::class, 'updateRestrictions']);
        Route::get('/food-images', [FoodAdminController::class, 'images']);
        Route::post('/food-images/{image}/approve', [FoodAdminController::class, 'approveImage']);
        Route::post('/food-images/{image}/reject', [FoodAdminController::class, 'rejectImage']);
        Route::delete('/foods/{food}/image', [FoodAdminController::class, 'removeImage']);
    });

    Route::get('/refeicao', [DiaryMealController::class, 'index']);
    Route::post('/refeicao', [DiaryMealController::class, 'store']);
    Route::get('/refeicao/{meal}', [DiaryMealController::class, 'show']);
    Route::put('/refeicao/{meal}', [DiaryMealController::class, 'update']);
    Route::delete('/refeicao/{meal}', [DiaryMealController::class, 'destroy']);

    Route::post('/groups/join', [GroupController::class, 'join']);
    Route::get('/groups', [GroupController::class, 'index']);
    Route::post('/groups', [GroupController::class, 'store']);
    Route::get('/groups/{group}', [GroupController::class, 'show']);
    Route::delete('/groups/{group}', [GroupController::class, 'destroy']);
    Route::post('/groups/{group}/leave', [GroupController::class, 'leave']);
    Route::get('/groups/{group}/ranking', [GroupController::class, 'ranking']);
    Route::get('/groups/{group}/activity', [GroupController::class, 'activity']);

});
