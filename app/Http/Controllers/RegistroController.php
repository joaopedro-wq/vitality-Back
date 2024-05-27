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
        $registros = Registro::with(['alimento', 'refeicao'])->get()->map(function ($registro) {
            $registro->descricao_alimento = $registro->alimento->descricao;
            $registro->descricao_refeicao = $registro->refeicao->descricao;

            return $registro;
        });

        
        $caloriaTotal = $registros->reduce(function ($carry, $registro) {
            return $carry + ($registro->alimento->caloria * ($registro->qtd / $registro->alimento->qtd));
        }, 0);

        return response()->json([
            'data' => $registros,
            'caloria_total' => $caloriaTotal, 
            'success' => true
        ]);
    }


    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {




        $request->validate([
            'qtd' => 'required|array',
            'qtd.*' => 'required|numeric',
            'data' => 'required|date',
            'id_alimento' => 'required|array',
            'id_alimento.*' => 'required|integer',
            'id_refeicao' => 'required|integer',
        ]);
    


        
        if (count($request->qtd) != count($request->id_alimento)) {
            return response()->json([
                'message' => 'Os arrays de qtd e id_alimento devem ter o mesmo tamanho',
                'success' => false
            ], 400);
        }
    
        $registros = collect();
    
        foreach ($request->qtd as $index => $qtd) {
            $registro = Registro::create([
                'qtd' => $qtd,
                'data' => $request->data,
                'id_alimento' => $request->id_alimento[$index],
                'id_refeicao' => $request->id_refeicao,
            ]);
    
            $registros->push($registro);
        }
    
        $response = $registros->map(function ($registro) {
            return [
                'id' => $registro->id,
                'data' => $registro->data,
                'qtd' => $registro->qtd,
                'id_alimento' => $registro->id_alimento,
                'id_refeicao' => $registro->id_refeicao,
                'descricao_alimento' => $registro->alimento->descricao,
                'descricao_refeicao' => $registro->refeicao->descricao,
                'alimento' => $registro->alimento,
                'refeicao' => $registro->refeicao,
            ];
        });
    
        return response()->json([
            'message' => 'Registros registrados com sucesso',
            'data' => $response,
            'success' => true
        ]);
    }
    
    public function getRegistro($id)
{
    $registro = Registro::with(['alimento', 'refeicao'])
                    ->where('id', $id)
                    ->first();

    return response()->json([
        'message' => 'Registro encontrado com sucesso',
        'data' => $registro,
        'success' => true
    ]);
}

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $registro = Registro::with(['alimento'])->find($id);

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
            'qtd' => 'required|numeric',
            'data' => 'required|date',
            'id_alimento' => 'required|integer',
            'id_refeicao' => 'required|integer',

        ]);

        $registro->update([
            'qtd' => $request->qtd,
            'data' => $request->data,
            'id_alimento' => $request->id_alimento,
            'id_refeicao' => $request->id_refeicao,

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
