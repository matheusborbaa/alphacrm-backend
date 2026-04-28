<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;

class EnsureAcademyEnabled
{
    public function handle(Request $request, Closure $next)
    {
        $enabled = Setting::get('academy_enabled', true);

        if ($enabled === false || $enabled === 0 || $enabled === '0' || $enabled === 'false') {
            return response()->json([
                'message' => 'Módulo Academy desativado pelo administrador.',
                'code'    => 'academy_disabled',
            ], 403);
        }

        return $next($request);
    }
}
