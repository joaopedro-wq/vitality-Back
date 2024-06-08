<?php

namespace App\Http\Controllers;

use App\Models\Dieta;
use Illuminate\Http\Request;

class DietaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $dieta = Dieta::with(['alimentos', 'refeicao'])->get();
        return response()->json([
            'data' => $dieta,
            'success' => true
        ]);
    }


   /*  public function index()
    {
        $dietas = Dieta::with(['alimentos', 'refeicao'])->get()->map(function ($dieta) {
            // Calcular os valores nutricionais totais para cada dieta
            $total_calorias = 0;
            $total_proteina = 0;
            $total_gordura = 0;
            $total_carbo = 0;
    
            foreach ($dieta->alimentos as $alimento) {
                // Calcular os nutrientes com base na quantidade
                $fator = $alimento->pivot->qtd / 100; // Agora estou usando 100 como base para a quantidade
                $total_calorias += $alimento->caloria * $fator;
                $total_proteina += $alimento->proteina * $fator;
                $total_gordura += $alimento->gordura * $fator;
                $total_carbo += $alimento->carbo * $fator;
            }
    
            // Adicionar os valores calculados à resposta JSON
            $dieta->total_calorias = $total_calorias;
            $dieta->total_proteina = $total_proteina;
            $dieta->total_gordura = $total_gordura;
            $dieta->total_carbo = $total_carbo;
    
            return $dieta;
        });
    
        return response()->json([
            'data' => $dietas,
            'success' => true
        ]);
    } */



    /**
     * Store a newly created resource in storage.
     */
     public function store(Request $request)
    {
        $dieta = Dieta::create([
            'descricao' => $request->descricao,
            'id_refeicao' => $request->id_refeicao,
            

        ]);
    
        $dieta->alimentos()->attach($request->alimentos);
    
        $alimentos_descricao = $dieta->alimentos->pluck('descricao')->toArray();
        $refeicao_descricao = $dieta->refeicao->descricao;
    
        return response()->json([
            'message' => 'Refeição registrada com sucesso',
            'data' => $dieta,
            'success' => true,
            'descricao_alimentos' => $alimentos_descricao,
            'descricao_refeicao' => $refeicao_descricao,
        ]);
    } 
/*
    public function store(Request $request)
    {
        $validated = $request->validate([
            'descricao' => 'required|string|max:255',
            'id_refeicao' => 'required|exists:refeicaos,id',
            'alimentos' => 'array|required',
            'alimentos.*.id' => 'required|exists:alimentos,id',
            'alimentos.*.qtd' => 'required|numeric|min:1'
        ]);
    
        $dieta = Dieta::create([
            'descricao' => $validated['descricao'],
            'id_refeicao' => $validated['id_refeicao'],
        ]);
    
        foreach ($validated['alimentos'] as $alimento) {
            // Associar o alimento à dieta e armazenar a quantidade na tabela pivot
            $dieta->alimentos()->attach($alimento['id'], ['qtd' => $alimento['qtd']]);
        }
    
        // Agora que a quantidade está armazenada na tabela pivot, podemos calcular os valores nutricionais da dieta
        $total_calorias = 0;
        $total_proteina = 0;
        $total_gordura = 0;
        $total_carbo = 0;
    
        foreach ($dieta->alimentos as $alimento) {
            // Obter a quantidade de cada alimento associado à dieta
            $qtd_alimento = $alimento->pivot->qtd;
    
            // Calcular os nutrientes com base na quantidade
            $fator = $qtd_alimento / 100; // A base de 100 unidades foi usada na tabela de alimentos
            $total_calorias += $alimento->caloria * $fator;
            $total_proteina += $alimento->proteina * $fator;
            $total_gordura += $alimento->gordura * $fator;
            $total_carbo += $alimento->carbo * $fator;
        }
    
        // Agora temos os valores nutricionais totais da dieta
        // Adicionaremos esses valores à resposta JSON
    
        $alimentos_descricao = $dieta->alimentos->pluck('descricao')->toArray();
        $refeicao_descricao = $dieta->refeicao->descricao;
    
        return response()->json([
            'message' => 'Refeição registrada com sucesso',
            'data' => $dieta,
            'success' => true,
            'descricao_alimentos' => $alimentos_descricao,
            'descricao_refeicao' => $refeicao_descricao,
            'total_calorias' => $total_calorias,
            'total_proteina' => $total_proteina,
            'total_gordura' => $total_gordura,
            'total_carbo' => $total_carbo,
        ]);
    } */
    



    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $dieta = Dieta::with(['alimentos', 'refeicao'])->find($id);

        if (!$dieta) {
            return response()->json([
                'message' => 'dieta não encontrada na base de dados',
                'success' => false
            ], 404);
        }


        return response()->json([
            'message' => 'Resposta da dieta carregada com sucesso',
            'data' => $dieta,
            'success' => true
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $dieta = Dieta::find($id);

        if (!$dieta) {
            return response()->json([
                'message' => 'Dieta não encontrada na base de dados',
                'success' => false
            ], 404);
        }

        // Atualizar os campos da dieta
        $dieta->descricao = $request->descricao;
        $dieta->id_refeicao = $request->id_refeicao;
      



        $dieta->save();

        // Atualizar os alimentos associados
        if ($request->has('alimentos')) {
            $dieta->alimentos()->sync($request->alimentos);
        }

        $alimentos_descricao = $dieta->alimentos->pluck('descricao')->toArray();
        $refeicao_descricao = $dieta->refeicao->descricao;

        return response()->json([
            'message' => 'Dieta atualizada com sucesso',
            'data' => $dieta,
            'success' => true,
            'descricao_alimentos' => $alimentos_descricao,
            'descricao_refeicao' => $refeicao_descricao,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $dieta = Dieta::find($id);

        if (!$dieta) {
            return response()->json([
                'message' => 'dieta não encontrada na base de dados',
                'success' => false
            ], 404);
        }

        $dieta->delete();

        return response()->json([
            'message' => 'dieta excluída com sucesso',
            'success' => true
        ]);
    }
}
