<?php

namespace App\Http\Controllers;

use App\Models\Registro;
use Illuminate\Http\Request;

class RegistroController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $registro = Registro::with(['alimento'])->get()->map(function ($registro) {
           
            return $registro;
        });

        return response()->json([
            'data' => $registro,
            'success' => true
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'descricao' => 'required|string',
            'qtd' => 'required|numeric',
            'data' => 'required|date',
            'id_alimento' => 'required|integer',
        ]);

        $registro = Registro::create([
            'descricao' => $request->descricao,
            'qtd' => $request->qtd,
            'data' => $request->data,
            'id_alimento' => $request->id_alimento,
        ]);

        return response()->json([
            'message' => 'registro  registrada com sucesso',
            'data' => $registro,
            'success' => true
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $registro = Registro::with([ 'alimento'])->find($id);

        if (!$registro) {
            return response()->json([
                'message' => 'registro não encontrada na base de dados',
                'success' => false
            ], 404);
        }

    
        return response()->json([
            'message' => 'Resposta da Meta carregada com sucesso',
            'data' => $registro,
            'success' => true
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $registro = Registro::find($id);

        if (!$registro) {
            return response()->json([
                'message' => 'registro não encontrada na base de dados',
                'success' => false
            ], 404);
        }

        $request->validate([
            'descricao' => 'required|string',
            'qtd' => 'required|numeric',
            'data' => 'required|date',
            'id_alimento' => 'required|integer',
        ]);

        $registro->update([
            'descricao' => $request->descricao,
            'qtd' => $request->qtd,
            'data' => $request->data,
            'id_alimento' => $request->id_alimento,
        ]);

        return response()->json([
            'message' => 'registro atualizada com sucesso',
            'data' => $registro,
            'success' => true
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $registro = Registro::find($id);

        if (!$registro) {
            return response()->json([
                'message' => 'registro não encontrada na base de dados',
                'success' => false
            ], 404);
        }

        $registro->delete();

        return response()->json([
            'message' => 'registroexcluída com sucesso',
            'success' => true
        ]);
    } 
}