<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\Setting;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sprint 3.0a — Reauth por senha com preservação de estado.
 *
 * Aplica em rotas sensíveis: se
 *   (now - personal_access_tokens.last_confirmed_password_at)
 *   > password_confirm_idle_minutes,
 * devolve HTTP 423 { reauth_required: true } SEM invalidar o token.
 *
 * O frontend (core/api.js) captura o 423, pede a senha num modal global
 * e refaz a request original — mantendo o formulário / upload / body
 * exatamente como estavam. Isso é diferente de deslogar e redirecionar
 * pro /login, que faria o user perder o que estava digitando.
 *
 * Rotas isentas por natureza:
 *   - /auth/confirm-password (senão deadlock)
 *   - /auth/logout
 *   - /auth/me / /auth/permissions (read-only de identidade)
 *   - /auth/sessions (lista/revoga do próprio user)
 * Essas NÃO recebem o alias 'fresh-auth' nas rotas.
 */
class EnsureFreshAuthentication
{
    /**
     * Métodos HTTP que não disparam reauth (leitura pura).
     */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * Paths (prefixos) isentos mesmo quando são POST/PUT/DELETE —
     * evita deadlock e permite que o user gerencie a própria sessão.
     */
    private const EXEMPT_PREFIXES = [
        'api/auth/confirm-password',
        'api/auth/logout',
        'api/auth/refresh',
        'api/auth/permissions',
        'api/auth/sessions',
        'api/auth/login',          // login é público mesmo
        'api/auth/forgot-password',
        'api/auth/reset-password',
    ];

    /**
     * Paths de leitura que são sensíveis o suficiente pra exigir reauth
     * MESMO sendo GET (revelam PII ou baixam documento). Ex.: /leads/X/reveal
     * devolve telefone em cleartext — se o user largou o CRM aberto, melhor
     * pedir senha de novo antes de mostrar.
     */
    private const SENSITIVE_READ_PATTERNS = [
        '#^api/leads/\d+/reveal$#',
        '#^api/documents/.+/download$#',
        '#^api/lead-documents/\d+/download$#',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user  = $request->user();
        $token = $user?->currentAccessToken();

        // Rota pública ou não autenticada — não é job nosso.
        if (!$user || !$token) {
            return $next($request);
        }

        // Isenções por path: sempre passam (inclui a rota do próprio
        // confirm-password pra evitar deadlock).
        $path = ltrim($request->path(), '/');
        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $next($request);
            }
        }

        // Leitura normal não dispara reauth — exceto nos paths sensíveis
        // listados em SENSITIVE_READ_PATTERNS (PII/downloads).
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            $isSensitive = false;
            foreach (self::SENSITIVE_READ_PATTERNS as $pattern) {
                if (preg_match($pattern, $path)) {
                    $isSensitive = true;
                    break;
                }
            }
            if (!$isSensitive) {
                return $next($request);
            }
        }

        $idleMinutes = (int) Setting::get('password_confirm_idle_minutes', 30);

        // 0 ou negativo = reauth desligado (modo permissivo).
        if ($idleMinutes <= 0) {
            return $next($request);
        }

        $lastConfirmed = $token->last_confirmed_password_at;

        // Token antigo (pré-migration) — trata como "precisa reauth".
        if (!$lastConfirmed) {
            return $this->reauthResponse();
        }

        $expiresAt = Carbon::parse($lastConfirmed)->addMinutes($idleMinutes);

        if (Carbon::now()->greaterThan($expiresAt)) {
            return $this->reauthResponse();
        }

        return $next($request);
    }

    private function reauthResponse(): Response
    {
        return response()->json([
            'message'         => 'Confirme sua senha pra continuar.',
            'reauth_required' => true,
        ], 423);
    }
}
