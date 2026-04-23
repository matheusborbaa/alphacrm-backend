<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;

/**
 * Guarda que bloqueia os endpoints do chat quando o admin desliga o módulo
 * em Configurações → Geral (setting `chat_enabled` = false).
 *
 * Por que existe: só esconder o item no sidebar não basta — alguém que já
 * conhece os endpoints pode bater direto via curl/cliente HTTP e usar o chat
 * mesmo com o módulo "desligado". Esse middleware garante que TODO endpoint
 * `/chat/*` retorna 403 enquanto `chat_enabled` estiver false.
 *
 * Default: habilitado (true). Se o setting nunca foi gravado, o chat
 * continua funcionando como antes — zero-config backward compatibility.
 */
class EnsureChatEnabled
{
    public function handle(Request $request, Closure $next)
    {
        // Setting::get cacheia internamente por request, então chamadas
        // repetidas (ex.: quando o frontend pinga vários endpoints de chat
        // em paralelo) não viram N consultas ao banco.
        $enabled = Setting::get('chat_enabled', true);

        // Coerce pro boolean real — Setting pode devolver '0'/'false' como
        // string dependendo de como foi gravado. SettingController.coerce()
        // já normaliza na escrita, mas duplicamos aqui por segurança.
        if ($enabled === false || $enabled === 0 || $enabled === '0' || $enabled === 'false') {
            return response()->json([
                'message' => 'Módulo de chat desativado pelo administrador.',
                'code'    => 'chat_disabled',
            ], 403);
        }

        return $next($request);
    }
}
