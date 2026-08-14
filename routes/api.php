<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RefeicaoController;
use App\Http\Controllers\RegistroController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\DietaController;
use App\Http\Controllers\MetaDiariaController;
use App\Http\Controllers\NutricaoRecomendadaController;
use App\Http\Controllers\FoodController;
use App\Http\Controllers\Admin\FoodAdminController;
use App\Http\Controllers\DiaryEntryController;
use App\Http\Controllers\DiaryMealController;


Route::middleware('guest')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/criar-usuario', [UserController::class, 'storeUser']);
});

Route::middleware('auth:sanctum')->group(function () {
    
    
    
    Route::get('/diary/day', [DiaryEntryController::class, 'day']);
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
    
    
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/user', [UserController::class, 'store']);
    // Rotas estáticas ANTES das paramétricas para evitar conflito de match
    Route::get('/user/get-with-token', [UserController::class, 'getWithToken']);
    Route::post('/user/avatar', [UserController::class, 'updateAvatar']);
    Route::delete('/user/avatar', [UserController::class, 'destroyAvatar']);
    Route::get('/user/{id}', [UserController::class, 'show']);
    Route::put('/atualizar-user/{id}', [UserController::class, 'update']);
    Route::put('/user/{id}', [UserController::class, 'update']);
    
    Route::delete('/registro/{entry}', [DiaryEntryController::class, 'destroy']);
    
    
    Route::get('/dieta/{id}', [DietaController::class, 'show']);
    Route::delete('/dieta/{id}', [DietaController::class, 'destroy']);
    Route::put('/dieta/{id}', [DietaController::class, 'update']);
    Route::post('/dieta', [DietaController::class, 'store']);
    Route::get('/dieta', [DietaController::class, 'index']);
    
    
    Route::get('/foods', [FoodController::class, 'index']);
    Route::get('/foods/groups', [FoodController::class, 'groups']);
    Route::get('/foods/{food}', [FoodController::class, 'show']);
    Route::post('/foods/{food}/favorite', [FoodController::class, 'favorite']);
    Route::delete('/foods/{food}/favorite', [FoodController::class, 'unfavorite']);

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/foods', [FoodAdminController::class, 'index']);
        Route::get('/foods/duplicates', [FoodAdminController::class, 'duplicates']);
        Route::post('/foods', [FoodAdminController::class, 'store']);
        Route::put('/foods/{food}', [FoodAdminController::class, 'update']);
        Route::post('/foods/{food}/archive', [FoodAdminController::class, 'archive']);
        Route::post('/foods/{food}/restore', [FoodAdminController::class, 'restore']);
        Route::post('/foods/import-taco', [FoodAdminController::class, 'importTaco']);
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

});
