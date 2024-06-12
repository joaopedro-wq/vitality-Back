<?php

namespace App\Http\Controllers;

use App\Models\Meta_diaria;
use Illuminate\Http\Request;

class MetaDiariaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $metas = Meta_diaria::all();
        return response()->json(['data' => $metas, 'success' => true]);
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

        $meta = Meta_diaria::create($request->all());

        return response()->json(['data' => $meta, 'success' => true, 'message' => 'Meta diária registrada com sucesso']);
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
