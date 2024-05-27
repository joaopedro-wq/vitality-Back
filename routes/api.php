<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AlimentoController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\RefeicaoController;
use App\Http\Controllers\RegistroController;
use App\Http\Controllers\UserController;

 
Route::post('/login', [AuthController::class,'login']);
Route::post('/cadastro', [UserController::class, 'store']);

Route::group(['middleware' => ['auth:sanctum']], function(){

    Route::post('/logout/{user}', [AuthController::class,'logout']);

    
    Route::get('/users', [UserController::class, 'index']);

    Route::get('/food', [AlimentoController::class, 'index']);
    Route::post('/food', [AlimentoController::class, 'store']);
    Route::get('/food/{id}', [AlimentoController::class, 'show']);
    Route::put('/food/{id}', [AlimentoController::class, 'update']);
    Route::delete('/food/{id}', [AlimentoController::class, 'destroy']);
    
    Route::get('/registro', [RegistroController::class, 'index']);
    Route::post('/registro', [RegistroController::class, 'store']);
    Route::get('/registro/{id}', [RegistroController::class, 'show']);
    Route::put('/registro/{id}', [RegistroController::class, 'update']);
    Route::delete('/registro/{id}', [RegistroController::class, 'destroy']);
    Route::get('/adicionar-alimentos', [AlimentoController::class, 'adicionarAlimentosDoJson']);
    
    Route::get('/refeicao', [RefeicaoController::class, 'index']);
    Route::post('/refeicao', [RefeicaoController::class, 'store']);
    Route::get('/refeicao/{id}', [RefeicaoController::class, 'show']);
    Route::put('/refeicao/{id}', [RefeicaoController::class, 'update']);
    Route::delete('/refeicao/{id}', [RefeicaoController::class, 'destroy']);
    Route::get('/adicionar-refeicao', [RefeicaoController::class, 'adicionarRefeicaoDoJson']);
});

