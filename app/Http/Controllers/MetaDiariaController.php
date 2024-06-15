<?php

namespace App\Http\Controllers;

use App\Models\Meta_diaria;
use Illuminate\Http\Request;

class MetaDiariaController extends Controller
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

        $metas = Meta_diaria::where('id_usuario', $user->id)->get();

        return response()->json([
            'data' => $metas,
            'success' => true
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'meta_calorias' => 'required|numeric',
            'meta_proteinas' => 'required|numeric',
            'meta_carboidratos' => 'required|numeric',
            'meta_gorduras' => 'required|numeric',
        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Usuário não encontrado na base de dados',
                'success' => false
            ], 404);
        }

        $meta = Meta_diaria::create([
            'meta_calorias' => $request->meta_calorias,
            'meta_proteinas' => $request->meta_proteinas,
            'meta_carboidratos' => $request->meta_carboidratos,
            'meta_gorduras' => $request->meta_gorduras,
            'id_usuario' => $user->id,
        ]);

        return response()->json([
            'data' => $meta,
            'success' => true,
            'message' => 'Meta diária registrada com sucesso'
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $meta = Meta_diaria::find($id);

        if (!$meta) {
            return response()->json(['message' => 'Meta diária não encontrada', 'success' => false], 404);
        }

        return response()->json(['data' => $meta, 'success' => true]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $meta = Meta_diaria::find($id);

        if (!$meta) {
            return response()->json(['message' => 'Meta diária não encontrada', 'success' => false], 404);
        }

        $request->validate([
            'meta_calorias' => 'required|numeric',
            'meta_proteinas' => 'required|numeric',
            'meta_carboidratos' => 'required|numeric',
            'meta_gorduras' => 'required|numeric',
        ]);

        $meta->update($request->all());

        return response()->json(['data' => $meta, 'success' => true, 'message' => 'Meta diária atualizada com sucesso']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $meta = Meta_diaria::find($id);

        if (!$meta) {
            return response()->json(['message' => 'Meta diária não encontrada', 'success' => false], 404);
        }

        $meta->delete();

        return response()->json(['message' => 'Meta diária excluída com sucesso', 'success' => true]);
    }
}
