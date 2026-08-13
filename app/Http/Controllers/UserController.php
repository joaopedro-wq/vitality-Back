<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateUserAvatarRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserAvatarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        return UserResource::collection(User::all())->additional([
            'success' => true,
        ]);
    }

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
            'objetivo' => 'nullable|string',
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
            'objetivo' => $request->objetivo,
        ]);

        (new RefeicaoController())->adicionarRefeicaoDoJson($user->id);

        return UserResource::make($user)->additional([
            'message' => 'Usuário registrado com sucesso',
            'success' => true,
        ]);
    }

    public function show($id)
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json([
                'message' => 'Usuário não encontrado na base de dados',
                'success' => false,
            ], 404);
        }

        return UserResource::make($user)->additional([
            'message' => 'Usuário carregado com sucesso',
            'success' => true,
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json([
                'message' => 'Usuário não encontrado na base de dados',
                'success' => false,
            ], 404);
        }

        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'genero' => 'nullable',
            'peso' => 'nullable|numeric',
            'data_nascimento' => 'nullable|date',
            'altura' => 'nullable|numeric',
            'nivel_atividade' => 'nullable|string',
            'objetivo' => 'nullable|string',
        ]);

        $user->update($request->only([
            'name',
            'email',
            'genero',
            'peso',
            'data_nascimento',
            'altura',
            'nivel_atividade',
            'objetivo',
        ]));

        if ($request->filled('password')) {
            $user->update(['password' => Hash::make($request->password)]);
        }

        return UserResource::make($user->fresh())->additional([
            'message' => 'Usuário atualizado com sucesso',
            'success' => true,
        ]);
    }

    public function getWithToken(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Usuário não encontrado na base de dados',
                'success' => false,
            ], 404);
        }

        return UserResource::make($user)->additional([
            'message' => 'Usuário encontrado com sucesso',
            'success' => true,
        ]);
    }

    public function updateAvatar(UpdateUserAvatarRequest $request, UserAvatarService $avatarService)
    {
        $user = $avatarService->replace($request->user(), $request->file('avatar'));

        return UserResource::make($user)->additional([
            'message' => 'Foto de perfil atualizada com sucesso',
            'success' => true,
        ]);
    }

    public function destroyAvatar(Request $request, UserAvatarService $avatarService)
    {
        $avatarService->remove($request->user());

        return response()->noContent();
    }

    public function storeUser(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'email' => 'unique:users|email|lowercase',
            'password' => 'required|string|confirmed',
        ], [
            'name.required' => 'O campo nome é obrigatório.',
            'email.required' => 'O campo email é obrigatório.',
            'email.unique' => 'O email já está sendo utilizado por outro usuário.',
            'email.lowercase' => 'O email deve estar em minúsculas.',
            'email.email' => 'O email informado é inválido.',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        (new RefeicaoController())->adicionarRefeicaoDoJson($user->id);

        return UserResource::make($user)->additional([
            'message' => 'Usuário criado com sucesso',
            'success' => true,
        ]);
    }
}
