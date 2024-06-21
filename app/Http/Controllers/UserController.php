<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $users = User::all()->map(function ($users) {

            return $users;
        });

        return response()->json([
            'data' => $users,
            'success' => true
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'email' => 'unique:users|email',
            'password' => 'required|string|confirmed',
            'genero' => 'nullable|char',
            'peso' => 'nullable|numeric',
            'data_nascimento' => 'nullable|date',
            'altura' => 'nullable|numeric',
            'avatar' => 'nullable|string',
            'nivel_atividade' => 'nullable|string',
        ]);

       

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'genero' => $request->genero,
            'peso' => $request->peso,
            'data_nascimento' => $request->data_nascimento,
            'altura' => $request->altura,
            'avatar' => $request->avatar,
            'nivel_atividade' => $request->nivel_atividade,
        ]);

       

        return response()->json([
            'message' => 'Usuário registrado com sucesso',
            'data' => $user,
            'success' => true
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'message' => 'Usuário não encontrado na base de dados',
                'success' => false
            ], 404);
        }

       

        return response()->json([
            'message' => 'Usuário carregado com sucesso',
            'data' => $user,
            'success' => true
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'message' => 'Usuário não encontrado na base de dados',
                'success' => false
            ], 404);
        }

       
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'genero' => 'nullable',
            'peso' => 'nullable|numeric',
            'data_nascimento' => 'nullable|date',
            'altura' => 'nullable|numeric', // Corrigido para numeric em vez de boolean
            'avatar' => 'nullable|string',
            'nivel_atividade' => 'nullable|string',
        ]);

        // Atualiza os dados do usuário, exceto a senha que só será alterada se fornecida
        $user->update([
            'name' => $request->name,
            'email' => $request->email,
            'genero' => $request->genero,
            'peso' => $request->peso,
            'data_nascimento' => $request->data_nascimento,
            'altura' => $request->altura,
            'avatar' => $request->avatar,
            'nivel_atividade' => $request->nivel_atividade,
        ]);

        // Atualiza a senha apenas se ela foi fornecida
        if ($request->filled('password')) {
            $user->update([
                'password' => Hash::make($request->password),
            ]);
        }

        return response()->json([
            'message' => 'Usuário atualizado com sucesso',
            'data' => $user,
            'success' => true
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function getWithToken(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Usuário não encontrado na base de dados',
                'success' => false
            ], 404);
        }

        // Retornando o caminho de acesso da imagem de avatar caso exista
        if ($user->avatar) {
            $user->avatar = Storage::url($user->avatar);
        }

        return response()->json([
            'message' => 'Usuário encontrado na base de dados',
            'data' => $user,
            'success' => true
        ]);
    }

    
    public function updateProfilePic(Request $request, $id)
    {
        // Procurar o usuário pelo ID
        $user = User::find($id);
    
        // Verificar se o usuário existe
        if (!$user) {
            return response()->json([
                'message' => 'Usuário não encontrado na base de dados',
                'success' => false
            ], 404);
        }
    
        // Validar a requisição
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);
    
        // Apagar a foto de perfil anterior se existir
        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }
    
        // Salvar a nova foto de perfil
        $avatarPath = $request->file('avatar')->store('avatars', 'public');
    
        // Atualizar o usuário com o caminho do novo avatar
        $user->update([
            'avatar' => $avatarPath,
        ]);
    
        // Retornar uma resposta de sucesso
        return response()->json([
            'message' => 'Foto de perfil atualizada com sucesso',
            'data' => $user,
            'success' => true
        ]);
    }
    

    public function deleteProfilePic($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'message' => 'Usuário não encontrado na base de dados',
                'success' => false
            ], 404);
        }

        if ($user->avatar) {
            Storage::delete($user->avatar);

            $user->avatar = null;
            $user->save();

            return response()->json([
                'message' => 'Foto de perfil excluída com sucesso',
                'success' => true
            ], 200);
        }

        return response()->json([
            'message' => 'O usuário não possui uma foto de perfil',
            'success' => false
        ], 404);
    }
}
