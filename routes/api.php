<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AlimentoController;
use App\Http\Controllers\RegistroController;


Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


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