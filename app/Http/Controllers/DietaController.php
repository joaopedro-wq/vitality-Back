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
        $dietas = Dieta::with(['alimentos', 'refeicao'])->get();
    
        $dietas->transform(function ($dieta) {
            $totalCarbo = 0;
            $totalGordura = 0;
            $totalProteina = 0;
            $totalCaloria = 0;
            $totalQtd = 0;
            foreach ($dieta->alimentos as $alimento) {
                $qtd = $alimento->pivot->qtd;
                $totalCarbo += ($alimento->carbo * $qtd) / 100;
                $totalGordura += ($alimento->gordura * $qtd) / 100;
                $totalProteina += ($alimento->proteina * $qtd) / 100;
                $totalCaloria += ($alimento->caloria * $qtd) / 100;
                $totalQtd += $qtd;
            }
    
            $dieta->total_carbo = round($totalCarbo, 2);
            $dieta->total_gordura = round($totalGordura, 2);
            $dieta->total_proteina = round($totalProteina, 2);
            $dieta->total_caloria = round($totalCaloria, 2);
            $dieta->totalQtd = round($totalQtd, 2);
    
            return $dieta;
        });
    
        return response()->json([
            'data' => $dietas,
            'success' => true
        ]);
    }
    
    

    /**
     * Store a newly created resource in storage.
     */
   /*   public function store(Request $request)
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
 */
    
 public function store(Request $request)
 {
     $dieta = Dieta::create([
         'descricao' => $request->descricao,
         'id_refeicao' => $request->id_refeicao,
     ]);
 
     $alimentos = $request->alimentos;
     foreach ($alimentos as $alimento) {
         $dieta->alimentos()->attach($alimento['id'], ['qtd' => $alimento['qtd']]);
     }
 
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
    
        // Atualizar os alimentos associados com quantidade
        if ($request->has('alimentos')) {
            $alimentos = [];
            foreach ($request->alimentos as $alimento) {
                $alimentos[$alimento['id']] = ['qtd' => $alimento['qtd']];
            }
            $dieta->alimentos()->sync($alimentos);
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
