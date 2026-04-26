<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;

class EnsureCorretorAreaEnabled
{
    public function handle(Request $request, Closure $next)
    {
        $enabled = Setting::get('corretor_area_enabled', true);

        if ($enabled === false || $enabled === 0 || $enabled === '0' || $enabled === 'false') {
            return response()->json([
                'message' => 'Módulo Área do Corretor desativado pelo administrador.',
                'code'    => 'corretor_area_disabled',
            ], 403);
        }

        return $next($request);
    }
}
