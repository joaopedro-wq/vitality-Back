<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
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

        $request->session()->regenerate();

        return response()->json([
            'status' => true,
            'user' => (new UserResource($request->user()))->resolve(),
            'message' => __('messages.login_success'),
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function refreshSession()
    {
        return response()->noContent();
    }
}
