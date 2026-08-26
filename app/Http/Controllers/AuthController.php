<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Services\GroupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return response()->json(['status' => false, 'message' => __('messages.invalid_credentials')], 401);
        }

        $user = $request->user();
        $token = $user->createToken('vitality-web')->plainTextToken;

        GroupService::ensureGlobalMembership($user);

        return response()->json([
            'status' => true,
            'user' => (new UserResource($user))->resolve(),
            'token' => $token,
            'message' => __('messages.login_success'),
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        // Token-authenticated API callers do not have a session store. Stateful
        // Sanctum requests do, so invalidate it when present without turning a
        // valid logout into a 500 for other authenticated clients.
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $accessToken = $request->user()?->currentAccessToken();
        if ($accessToken && method_exists($accessToken, 'delete')) {
            $accessToken->delete();
        }

        return response()->noContent();
    }

    public function refreshSession()
    {
        return response()->noContent();
    }
}
