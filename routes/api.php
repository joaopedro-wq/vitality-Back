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


Route::middleware('guest')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/criar-usuario', [UserController::class, 'storeUser']);
});

Route::middleware('auth:sanctum')->group(function () {
    
    
    Route::get('/registro', [RegistroController::class, 'index']);
    Route::post('/registro', [RegistroController::class, 'store']);
    Route::get('/registro/{id}', [RegistroController::class, 'show']);
    Route::put('/registro/{id}', [RegistroController::class, 'update']);
    
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
    
    Route::delete('/registro/{id}', [RegistroController::class, 'destroy']);
    
    
    Route::get('/dieta/{id}', [DietaController::class, 'show']);
    Route::delete('/dieta/{id}', [DietaController::class, 'destroy']);
    Route::put('/dieta/{id}', [DietaController::class, 'update']);
    Route::post('/dieta', [DietaController::class, 'store']);
    Route::get('/dieta', [DietaController::class, 'index']);
    
    
    Route::get('/foods', [FoodController::class, 'index']);
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
    });
    
    
    Route::get('/refeicao', [RefeicaoController::class, 'index']);
    Route::post('/refeicao', [RefeicaoController::class, 'store']);
    Route::get('/refeicao/{id}', [RefeicaoController::class, 'show']);
    Route::put('/refeicao/{id}', [RefeicaoController::class, 'update']);
    Route::delete('/refeicao/{id}', [RefeicaoController::class, 'destroy']);

});


Route::get('/adicionar-refeicao', [RefeicaoController::class, 'adicionarRefeicaoDoJson']);
