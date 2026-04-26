<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\Setting;
use Symfony\Component\HttpFoundation\Response;

class EnsureFreshAuthentication
{

    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private const EXEMPT_PREFIXES = [
        'api/auth/confirm-password',
        'api/auth/logout',
        'api/auth/refresh',
        'api/auth/permissions',
        'api/auth/sessions',
        'api/auth/login',
        'api/auth/forgot-password',
        'api/auth/reset-password',
    ];

    private const SENSITIVE_READ_PATTERNS = [
        '#^api/leads/\d+/reveal$#',
        '#^api/documents/.+/download$#',
        '#^api/lead-documents/\d+/download$#',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user  = $request->user();
        $token = $user?->currentAccessToken();

        if (!$user || !$token) {
            return $next($request);
        }

        $path = ltrim($request->path(), '/');
        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $next($request);
            }
        }

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

        if ($idleMinutes <= 0) {
            return $next($request);
        }

        $lastConfirmed = $token->last_confirmed_password_at;

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
