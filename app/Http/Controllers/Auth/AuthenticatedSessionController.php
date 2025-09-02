<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        try{
            $request->authenticate();
            $user = $request->user();
            $token = $user->createToken('auth_token')->plainTextToken;
            return response()->json(data: [
                'token' => $token,
                'user' => $user
            ]);
        }catch (\Illuminate\Validation\ValidationException $e){
            return response()->json(data: [
                'message' => $e->getMessage()
            ], status: 401);
        }
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): JsonResponse
    {
        if ($request->user()){
            $request->user()->currentAccessToken()->delete();
        }

        return response()->json(data: [
            'message' => 'Logged out successfully'
        ], status: 200);
    }
}
