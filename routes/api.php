<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AlimentoController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RefeicaoController;
use App\Http\Controllers\RegistroController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\DietaController;
use App\Http\Controllers\MetaDiariaController;
use App\Http\Controllers\NutricaoRecomendadaController;

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
    
    
    
    Route::get('/dieta/{id}', [DietaController::class, 'show']);
    Route::delete('/dieta/{id}', [DietaController::class, 'destroy']);
    Route::put('/dieta/{id}', [DietaController::class, 'update']);
    Route::post('/dieta', [DietaController::class, 'store']);
    Route::get('/dieta', [DietaController::class, 'index']);
    
    
    Route::get('/food', [AlimentoController::class, 'index']);
    Route::post('/food', [AlimentoController::class, 'store']);
    Route::get('/food/{id}', [AlimentoController::class, 'show']);
    Route::put('/food/{id}', [AlimentoController::class, 'update']);
    Route::delete('/food/{id}', [AlimentoController::class, 'destroy']);
    
    
    Route::get('/refeicao', [RefeicaoController::class, 'index']);
    Route::post('/refeicao', [RefeicaoController::class, 'store']);
    Route::get('/refeicao/{id}', [RefeicaoController::class, 'show']);
    Route::put('/refeicao/{id}', [RefeicaoController::class, 'update']);
    Route::delete('/refeicao/{id}', [RefeicaoController::class, 'destroy']);
});


Route::get('/adicionar-refeicao', [RefeicaoController::class, 'adicionarRefeicaoDoJson']);
Route::get('/adicionar-alimentos', [AlimentoController::class, 'adicionarAlimentosDoJson']);

Route::get('/users', [UserController::class, 'index']);
Route::post('/user', [UserController::class, 'store']);

Route::delete('/registro/{id}', [RegistroController::class, 'destroy']);
Route::get('/user/get-with-token', [UserController::class, 'getWithToken']);
Route::post('/user/update-profile-pic/{id}', [UserController::class, 'updateProfilePic']);
Route::delete('/user/delete-profile-pic/{id}', [UserController::class, 'deleteProfilePic']);
Route::put('/user/{id}', [UserController::class, 'update']);