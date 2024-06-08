<?php

namespace App\Http\Controllers;

use App\Models\Refeicao;
use Illuminate\Http\Request;

class RefeicaoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $refeicao = Refeicao::all()->map(function ($refeicao) {

            return $refeicao;
        });

        return response()->json([
            'data' => $refeicao,
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
            'horario' => 'required|date_format:H:i',
            
        ]);

        $refeicao = Refeicao::create([
            'descricao' => $request->descricao,
            'horario' => $request->horario,

        ]);

        return response()->json([
            'message' => 'Refeição registrada com sucesso',
            'data' => $refeicao,
            'success' => true
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $refeicao = Refeicao::find($id);
    
        if (!$refeicao) {
            return response()->json([
                'message' => 'Refeição não encontrado na base de dados',
                'success' => false
            ], 404);
        }
    
        return response()->json([
            'message' => 'Refeição carregado com sucesso',
            'data' => $refeicao,
            'success' => true
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $refeicao = Refeicao::find($id);

        if (!$refeicao) {
            return response()->json([
                'message' => 'Refeição não encontrado na base de dados',
                'success' => false
            ], 404);
        }

        $request->validate([
            'descricao' => 'required|string',
            'horario' => 'required|date_format:H:i',
            
        ]);

        $refeicao->update([
            'descricao' => $request->descricao,
            'horario' => $request->horario,

            
        ]);

        return response()->json([
            'message' => 'Refeição atualizado com sucesso',
            'data' => $refeicao,
            'success' => true
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        
        $refeicao = Refeicao::find($id);

        if (!$refeicao) {
            return response()->json([
                'message' => 'Refeição não encontrado na base de dados',
                'success' => false
            ], 404);
        }


        $refeicao->delete();

        return response()->json([
            'message' => 'Refeição excluído com sucesso',
            'success' => true
        ]);
    }

    public function adicionarRefeicaoDoJson()
    {
        $caminhoArquivo = base_path('refeicao.json');

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
                if (!isset($item['descricao']) || !isset($item['horario'])) {
                    return response()->json([
                        'message' => 'Dados incompletos no JSON',
                        'success' => false
                    ], 400);
                }

                Refeicao::create([
                    'descricao' => $item['descricao'],
                    'horario' => $item['horario'],
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
}
