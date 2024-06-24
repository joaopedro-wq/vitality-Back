<?php

namespace App\Http\Controllers;

use App\Models\NutricaoRecomendada;
use Illuminate\Http\Request;

class NutricaoRecomendadaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Usuário não encontrado na base de dados',
                'success' => false
            ], 404);
        }

        $recomendacaoNutricional = NutricaoRecomendada::where('id_usuario', $user->id)->get();

        return response()->json([
            'data' => $recomendacaoNutricional,
            'success' => true
        ]);
    }
    /**
     * Store a newly created resource in storage.
     */
    /*  public function store(Request $request)
    {
        $request->validate([
            'get' => 'required|numeric',
            'tmb' => 'required|numeric',
            'caloria' => 'required|numeric',
            'proteina' => 'required|numeric', 
            'carbo' => 'required|numeric',
            'gordura' => 'required|numeric',


        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Usuário não encontrado na base de dados',
                'success' => false
            ], 404);
        }

        $recomendacaoNutricional = NutricaoRecomendada::create([
            'get' => $request->get,
            'tmb' => $request->tmb,
            'caloria' => $request->caloria,
            'proteina' => $request->proteina,
            'carbo' => $request->carbo,
            'gordura' => $request->gordura,


            'id_usuario' => $user->id,
        ]);

        return response()->json([
            'data' => $recomendacaoNutricional,
            'success' => true,
            'message' => 'Meta diária registrada com sucesso'
        ]);
    } */
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'get' => 'required|numeric',
            'tmb' => 'required|numeric',
            'caloria' => 'required|numeric',
            'proteina' => 'required|numeric',
            'carbo' => 'required|numeric',
            'gordura' => 'required|numeric',
        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Usuário não encontrado na base de dados',
                'success' => false
            ], 404);
        }

        $existingRecommendation = NutricaoRecomendada::where('id_usuario', $user->id)->first();

        if ($existingRecommendation) {
            return response()->json([
                'message' => 'Já existe uma recomendação nutricional para este usuário',
                'success' => false
            ], 400);
        }

        $recomendacaoNutricional = NutricaoRecomendada::create([
            'get' => $request->get,
            'tmb' => $request->tmb,
            'caloria' => $request->caloria,
            'proteina' => $request->proteina,
            'carbo' => $request->carbo,
            'gordura' => $request->gordura,
            'id_usuario' => $user->id,
        ]);

        return response()->json([
            'data' => $recomendacaoNutricional,
            'success' => true,
            'message' => 'Meta diária registrada com sucesso'
        ]);
    }


    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $recomendacaoNutricional = NutricaoRecomendada::find($id);

        if (!$recomendacaoNutricional) {
            return response()->json(['message' => 'Meta diária não encontrada', 'success' => false], 404);
        }

        return response()->json(['data' => $recomendacaoNutricional, 'success' => true]);
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $recomendacaoNutricional = NutricaoRecomendada::find($id);

        if (!$recomendacaoNutricional) {
            return response()->json(['message' => 'Meta diária não encontrada', 'success' => false], 404);
        }

        $request->validate([
            'get' => 'required|numeric',
            'tmb' => 'required|numeric',
            'caloria' => 'required|numeric',
            'proteina' => 'required|numeric',
            'carbo' => 'required|numeric',
            'gordura' => 'required|numeric',


        ]);

        $recomendacaoNutricional->update($request->all());

        return response()->json(['data' => $recomendacaoNutricional, 'success' => true, 'message' => 'Meta diária atualizada com sucesso']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $recomendacaoNutricional = NutricaoRecomendada::find($id);

        if (!$recomendacaoNutricional) {
            return response()->json(['message' => 'Meta diária não encontrada', 'success' => false], 404);
        }

        $recomendacaoNutricional->delete();

        return response()->json(['message' => 'Meta diária excluída com sucesso', 'success' => true]);
    }

    
}
