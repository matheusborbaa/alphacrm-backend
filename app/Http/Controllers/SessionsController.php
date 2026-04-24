<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Sprint 3.0a — Tela "Meus dispositivos".
 *
 * O user lista os próprios tokens Sanctum e pode revogar qualquer um
 * (ex.: esqueci logado no PC da imobiliária, encerrar de casa).
 * Nunca expõe tokens de outros users.
 */
class SessionsController extends Controller
{
    /** GET /auth/sessions — lista sessões ativas do user autenticado. */
    public function index(Request $request)
    {
        $user    = $request->user();
        $current = $user->currentAccessToken();
        $currentId = $current?->id;

        $sessions = $user->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn($t) => [
                'id'                          => $t->id,
                'device_label'                => $t->device_label ?: 'Dispositivo desconhecido',
                'ip_address'                  => $t->ip_address,
                'user_agent'                  => $t->user_agent,
                'last_used_at'                => optional($t->last_used_at)->toIso8601String(),
                'created_at'                  => optional($t->created_at)->toIso8601String(),
                'last_confirmed_password_at'  => optional($t->last_confirmed_password_at)->toIso8601String(),
                'is_current'                  => $t->id === $currentId,
            ]);

        return response()->json($sessions);
    }

    /**
     * DELETE /auth/sessions/{token} — revoga um token do próprio user.
     * Devolve 403 se tentar apagar token de outro user. Se o user quiser
     * encerrar a sessão atual, usa /auth/logout — aqui o caso principal
     * é revogar uma sessão remota.
     */
    public function destroy(Request $request, int $token)
    {
        $user = $request->user();

        $t = $user->tokens()->where('id', $token)->first();

        if (!$t) {
            return response()->json(['message' => 'Sessão não encontrada.'], 404);
        }

        $t->delete();

        return response()->json(['ok' => true]);
    }
}
