<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Exception;
use Validator;

class AuthController extends Controller
{
    /**
     * Create user
     *
     * @param  [string] name
     * @param  [string] email
     * @param  [string] password
     * @param  [string] password_confirmation
     * @return [string] message
     */
    public function login(Request $request)
{
    // Verifica se o e-mail existe
    $user = User::where('email', $request->email)->first();

    if ($user) {
        // Verifica se a senha está correta
        if (Auth::attempt(['email' => $request->email, 'password' => $request->password])) {
            $token = $user->createToken('api-token')->plainTextToken;

            return response()->json([
                'status' => true,
                'token' => $token,
                'user' => $user,
                'message' => 'Login sucesso.',
            ], 201);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Senha incorreta.',
            ], 401);  // Código HTTP para erro de autenticação
        }
    } else {
        return response()->json([
            'status' => false,
            'message' => 'E-mail não encontrado.',
        ], 404);  // Código HTTP para recurso não encontrado
    }
}


    public function logout(User $user)
    {

        try {
            $user->tokens()->delete();

            return response()->json([
                'status' => true,
                'message' => 'Deslogado com sucesso.',
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Não deslogado.',
            ], 404);
        }
    }
}
