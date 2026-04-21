<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\RefreshToken;

class AuthController extends Controller
{

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Credenciais inválidas'
            ], 401);
        }

        // Remove tokens antigos
        $user->tokens()->delete();
        RefreshToken::where('user_id', $user->id)->delete();

        // Access Token (15 minutos conceitual)
        $accessToken = $user->createToken('crm-token')->plainTextToken;
        $id = $user->id;
        $name = $user->name;
        $email = $user->email;
        $role = $user->role;
        // Refresh Token (7 dias)
        $refreshToken = Str::random(64);

        RefreshToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', $refreshToken),
            'expires_at' => now()->addDays(7)
        ]);

        return response()->json([
    'access_token' => $accessToken,
    'refresh_token' => $refreshToken,
    'expires_in' => 900,
    'teste' => 'Matheus2',

    'user' => [
        'id' => $id,
        'name' => $name,
        'email' => $email,
        'role' => $role ?? null
        ]
    ]);
    }


    public function refresh(Request $request)
    {
        $request->validate([
            'refresh_token' => 'required'
        ]);

        $hashed = hash('sha256', $request->refresh_token);

        $refresh = RefreshToken::where('token', $hashed)
            ->where('expires_at', '>', now())
            ->first();

        if (!$refresh) {
            return response()->json([
                'message' => 'Refresh inválido'
            ], 401);
        }

        $user = $refresh->user;

        // gerar novo access
        $accessToken = $user->createToken('crm-token')->plainTextToken;

        return response()->json([
            'access_token' => $accessToken,
            'expires_in' => 900
        ]);
    }

}
