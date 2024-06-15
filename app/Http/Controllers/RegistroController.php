<?php

namespace App\Http\Controllers;

use App\Models\Registro;
use Illuminate\Http\Request;

class RegistroController extends Controller
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
        
        $registros = Registro::with(['alimentos', 'refeicao'])
            ->where('id_usuario', $user->id)
            ->orWhereNull('id_usuario')
            ->get()
            ->map(function ($registro) {
                $alimentos_detalhes = [];
                $nutrientes_totais = [
                    'proteina' => 0,
                    'gordura' => 0,
                    'caloria' => 0,
                    'carbo' => 0,
                    'qtd' => 0,
                ];
    
                foreach ($registro->alimentos as $alimento) {
                    $fator = $alimento->pivot->qtd / $alimento->qtd;
    
                    $alimentos_detalhes[] = [
                        'descricao' => $alimento->descricao,
                        'qtd' => round($alimento->pivot->qtd, 3),
                        'proteina' => round($alimento->proteina * $fator, 3),
                        'gordura' => round($alimento->gordura * $fator, 3),
                        'caloria' => round($alimento->caloria * $fator, 3),
                        'carbo' => round($alimento->carbo * $fator, 3),
                        'alimento' => $alimento, 
                    ];
    
                    $nutrientes_totais['proteina'] += $alimento->proteina * $fator;
                    $nutrientes_totais['gordura'] += $alimento->gordura * $fator;
                    $nutrientes_totais['caloria'] += $alimento->caloria * $fator;
                    $nutrientes_totais['carbo'] += $alimento->carbo * $fator;
                    $nutrientes_totais['qtd'] += $alimento->pivot->qtd;
                }
    
                foreach ($nutrientes_totais as $key => $value) {
                    $nutrientes_totais[$key] = round($value, 3);
                }
    
                return [
                    'id' => $registro->id,
                    'data' => $registro->data,
                    'descricao_refeicao' => $registro->refeicao->descricao,
                    'alimentos' => $alimentos_detalhes,
                    'nutrientes_totais' => $nutrientes_totais,
                ];
            });
    
        return response()->json([
            'data' => $registros,
            'success' => true
        ]);
    }
    





    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'alimentos' => 'required|array',
            'alimentos.*.id' => 'required|integer',
            'alimentos.*.qtd' => 'required|numeric',
            'data' => 'required|date',
            'id_refeicao' => 'required|integer',
        ]);

        $registro = Registro::create([
            'data' => $request->data,
            'id_refeicao' => $request->id_refeicao,
            'id_usuario' => $user->id,

        ]);

        foreach ($request->alimentos as $alimento) {
            $registro->alimentos()->attach($alimento['id'], ['qtd' => $alimento['qtd']]);
        }

        $registro->load('alimentos', 'refeicao');

        $alimentos_descricao = $registro->alimentos->pluck('descricao')->toArray();
        $refeicao_descricao = $registro->refeicao->descricao;

        return response()->json([
            'message' => 'Refeição registrada com sucesso',
            'data' => $registro,
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
        $registro = Registro::with(['alimentos', 'refeicao'])->find($id);
        /*  $registro = Registro::with(['alimento'])->find($id);
 */
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
                'message' => 'Registro não encontrado na base de dados',
                'success' => false
            ], 404);
        }

        $request->validate([
            'alimentos' => 'required|array',
            'alimentos.*.id' => 'required|integer',
            'alimentos.*.qtd' => 'required|numeric',
            'data' => 'required|date',
            'id_refeicao' => 'required|integer',
        ]);

        // Atualizar o registro com novos dados
        $registro->update([
            'data' => $request->data,
            'id_refeicao' => $request->id_refeicao,
        ]);

        // Remover os alimentos existentes associados ao registro
        $registro->alimentos()->detach();

        // Adicionar os novos alimentos ao registro
        foreach ($request->alimentos as $alimento) {
            $registro->alimentos()->attach($alimento['id'], ['qtd' => $alimento['qtd']]);
        }

        // Carregar os relacionamentos novamente
        $registro->load('alimentos', 'refeicao');

        return response()->json([
            'message' => 'Registro atualizado com sucesso',
            'data' => [
                'id' => $registro->id,
                'data' => $registro->data,
                'descricao_refeicao' => $registro->refeicao->descricao,
                'alimentos' => $registro->alimentos,
            ],
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
