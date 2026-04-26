<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;

class EnsureChatEnabled
{
    public function handle(Request $request, Closure $next)
    {

        $enabled = Setting::get('chat_enabled', true);

        if ($enabled === false || $enabled === 0 || $enabled === '0' || $enabled === 'false') {
            return response()->json([
                'message' => 'Módulo de chat desativado pelo administrador.',
                'code'    => 'chat_disabled',
            ], 403);
        }

        return $next($request);
    }
}
