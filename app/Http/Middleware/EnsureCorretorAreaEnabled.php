<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;

/**
 * Guarda que bloqueia os endpoints da Área do Corretor (biblioteca de mídia)
 * quando o admin desliga o módulo em Configurações → Geral
 * (setting `corretor_area_enabled` = false).
 *
 * Mesma estratégia do EnsureChatEnabled: só esconder o item no sidebar não
 * basta — alguém pode bater direto via curl/cliente HTTP. Esse middleware
 * garante que TODO endpoint `/media/*` retorna 403 quando o módulo tá off.
 *
 * Default: habilitado (true). Se o setting nunca foi gravado, a área do
 * corretor continua funcionando como antes — zero-config backward compat.
 */
class EnsureCorretorAreaEnabled
{
    public function handle(Request $request, Closure $next)
    {
        $enabled = Setting::get('corretor_area_enabled', true);

        // Coerce pra boolean real (Setting pode devolver '0'/'false' string).
        if ($enabled === false || $enabled === 0 || $enabled === '0' || $enabled === 'false') {
            return response()->json([
                'message' => 'Módulo Área do Corretor desativado pelo administrador.',
                'code'    => 'corretor_area_disabled',
            ], 403);
        }

        return $next($request);
    }
}
