<?php

namespace App\Http\Controllers;

use App\Models\Alimento;
use Illuminate\Http\Request;

class AlimentoController extends Controller
{
   

    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Usuário não encontrado na base de dados',
                'success' => false
            ], 404);
        }

        $alimento = Alimento::where('id_usuario', $user->id)
                            ->orWhereNull('id_usuario')
                            ->get();

        return response()->json([
            'data' => $alimento,
            'success' => true
        ]);
    }


    public function store(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'descricao' => 'required|string',
            'proteina' => 'required|numeric',
            'gordura' => 'required|numeric',
            'carbo' => 'required|numeric',
            'caloria' => 'required|numeric',
            'qtd' => 'required|numeric',


        ]);

        $alimento = Alimento::create([
            'descricao' => $request->descricao,
            'proteina' => $request->proteina,
            'gordura' => $request->gordura,
            'carbo' => $request->carbo,
            'caloria' => $request->caloria,
            'qtd' => $request->qtd,
            'id_usuario' => $user->id,


        ]);

        return response()->json([
            'message' => 'Alimento registrado com sucesso',
            'data' => $alimento,
            'success' => true
        ]);
    }



    public function adicionarAlimentosDoJson($userId)
    {
        $caminhoArquivo = base_path('taco.json');

        if (!file_exists($caminhoArquivo)) {
            return response()->json([
                'message' => 'Arquivo JSON não encontrado',
                'success' => false
            ], 404);
        }

        $json = file_get_contents($caminhoArquivo);
        $dadosJson = json_decode($json, true);

        if ($dadosJson === null) {
            return response()->json([
                'message' => 'Erro ao decodificar o arquivo JSON',
                'success' => false
            ], 400);
        }

        try {
            foreach ($dadosJson as $item) {
                if (!isset($item['Descrição do Alimento'], $item['Proteína(g)'], $item['Lipídeos(g)'], $item['Energia(kcal)'], $item['Carboidrato(g)'])) {
                    return response()->json([
                        'message' => 'Dados incompletos no JSON',
                        'success' => false
                    ], 400);
                }

                Alimento::create([
                    'descricao' => $item['Descrição do Alimento'],
                    'proteina' => (float) $item['Proteína(g)'],
                    'gordura' => (float) $item['Lipídeos(g)'],
                    'caloria' => (float) $item['Energia(kcal)'],
                    'carbo' => (float) $item['Carboidrato(g)'],
                    'qtd' => 100,
                    'id_usuario' => $userId,
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao inserir dados no banco de dados: ' . $e->getMessage(),
                'success' => false
            ], 500);
        }

        return response()->json([
            'message' => 'Dados do JSON adicionados ao banco de dados com sucesso',
            'success' => true
        ]);
    }



    public function show(string $id)
    {
        $alimento = Alimento::find($id);
    
        if (!$alimento) {
            return response()->json([
                'message' => 'Alimento não encontrado na base de dados',
                'success' => false
            ], 404);
        }
    
        return response()->json([
            'message' => 'Alimento carregado com sucesso',
            'data' => $alimento,
            'success' => true
        ]);
    }
    

    public function update(Request $request, $id)
    {
        $alimento = Alimento::find($id);

        if (!$alimento) {
            return response()->json([
                'message' => 'alimento não encontrado na base de dados',
                'success' => false
            ], 404);
        }

        $request->validate([
            'descricao' => 'required|string',
            'proteina' => 'required|numeric',
            'gordura' => 'required|numeric',
            'carbo' => 'required|numeric',
            'caloria' => 'required|numeric',
            'qtd' => 'required|numeric',
        ]);

        $alimento->update([
            'descricao' => $request->descricao,
            'proteina' => $request->proteina,
            'gordura' => $request->gordura,
            'carbo' => $request->carbo,
            'caloria' => $request->caloria,
            'qtd' => $request->qtd,
        ]);

        return response()->json([
            'message' => 'alimento atualizado com sucesso',
            'data' => $alimento,
            'success' => true
        ]);
    }

    public function destroy(string $id)
    {
        $alimento = Alimento::find($id);

        if (!$alimento) {
            return response()->json([
                'message' => 'alimento não encontrado na base de dados',
                'success' => false
            ], 404);
        }


        $alimento->delete();

        return response()->json([
            'message' => 'alimento excluído com sucesso',
            'success' => true
        ]);
    }
}
