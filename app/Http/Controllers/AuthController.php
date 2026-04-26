<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Validation\Rules\Password as PasswordRule;
use App\Models\User;
use App\Models\Setting;
use App\Models\RefreshToken;
use App\Mail\ResetPasswordMail;
use App\Support\DeviceLabel;

class AuthController extends Controller
{

    public function login(Request $request)
    {
        $request->validate([
            'email'              => 'required|email',
            'password'           => 'required',
            'revoke_session_id'  => 'nullable|integer',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password) || !$user->active) {
            return response()->json([
                'message' => 'Credenciais inválidas'
            ], 401);
        }

        if ($request->filled('revoke_session_id')) {
            $user->tokens()->where('id', (int) $request->revoke_session_id)->delete();
        }

        $maxSessions = max(1, (int) Setting::get('max_concurrent_sessions', 2));
        $activeCount = $user->tokens()->count();

        if ($activeCount >= $maxSessions) {

            $sessions = $user->tokens()
                ->orderByDesc('last_used_at')
                ->get()
                ->map(fn($t) => [
                    'id'            => $t->id,
                    'device_label'  => $t->device_label ?: 'Dispositivo desconhecido',
                    'ip_address'    => $t->ip_address,
                    'last_used_at'  => optional($t->last_used_at)->toIso8601String(),
                    'created_at'    => optional($t->created_at)->toIso8601String(),
                ]);

            return response()->json([
                'message'              => 'Limite de sessões simultâneas atingido.',
                'max_sessions'         => $maxSessions,
                'active_sessions'      => $sessions,
                'session_limit_reached' => true,
            ], 409);
        }

        RefreshToken::where('user_id', $user->id)->delete();

        $accessToken  = $user->createToken('crm-token');
        $plainToken   = $accessToken->plainTextToken;
        $tokenModel   = $accessToken->accessToken;

        $tokenModel->forceFill([
            'ip_address'                 => $request->ip(),
            'user_agent'                 => substr((string) $request->userAgent(), 0, 500),
            'device_label'               => DeviceLabel::fromUserAgent($request->userAgent()),
            'last_confirmed_password_at' => Carbon::now(),
        ])->save();

        $refreshToken = Str::random(64);

        RefreshToken::create([
            'user_id'    => $user->id,
            'token'      => hash('sha256', $refreshToken),
            'expires_at' => now()->addDays(7),
        ]);

        return response()->json([
            'access_token'  => $plainToken,
            'refresh_token' => $refreshToken,
            'expires_in'    => 900,

            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,

                'role'  => method_exists($user, 'effectiveRole')
                    ? ($user->effectiveRole() ?: null)
                    : ($user->role ?? null),
            ],
        ]);
    }

    public function confirmPassword(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user  = $request->user();
        $token = $user?->currentAccessToken();

        if (!$user || !$token) {
            return response()->json(['message' => 'Não autenticado'], 401);
        }

        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Senha incorreta.'], 401);
        }

        $token->forceFill(['last_confirmed_password_at' => Carbon::now()])->save();

        return response()->json(['ok' => true]);
    }

    public function permissions(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Não autenticado'], 401);
        }

        $role = $user->getRoleNames()->first() ?? $user->role;

        $permissions = $user->getAllPermissions()->pluck('name')->values();

        return response()->json([
            'role'        => $role,
            'permissions' => $permissions,
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

        if (!$user || !$user->active) {
            if ($user) {
                $user->tokens()->delete();
                RefreshToken::where('user_id', $user->id)->delete();
            }
            return response()->json(['message' => 'Sessão inválida'], 401);
        }

        $accessToken = $user->createToken('crm-token')->plainTextToken;

        return response()->json([
            'access_token' => $accessToken,
            'expires_in' => 900
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if ($user && $user->active) {

            $token = Password::broker()->createToken($user);

            \App\Services\EmailLoggerService::send(
                to: $user->email,
                mailable: new ResetPasswordMail($user, $token),
                type: \App\Models\EmailLog::TYPE_RESET,
                relatedUserId: $user->id,
                toName: $user->name,
                triggeredBy: null
            );
        }

        return response()->json([
            'message' => 'Se o email estiver cadastrado, você receberá um link de recuperação em instantes.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email'                 => 'required|email',
            'token'                 => 'required|string',
            'password'              => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->password = Hash::make($password);
                $user->save();

                $user->tokens()->delete();
                RefreshToken::where('user_id', $user->id)->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Senha alterada com sucesso. Faça login com a nova senha.',
            ]);
        }

        $message = match ($status) {
            Password::INVALID_USER  => 'Não encontramos uma conta com esse email.',
            Password::INVALID_TOKEN => 'Link inválido ou expirado. Solicite um novo.',
            default                 => 'Não foi possível redefinir a senha. Tente novamente.',
        };

        return response()->json(['message' => $message], 422);
    }

}
