<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
                    'user' => (new UserResource($user))->resolve(),
                    'message' => __('messages.login_success'),
                ], 201);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => __('messages.invalid_password'),
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
                'message' => __('messages.logout_success'),
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Não deslogado.',
            ], 404);
        }
    }
}
