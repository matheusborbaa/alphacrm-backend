<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
  public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = auth('sanctum')->user();

        if (!$user) {
            return response()->json(['message' => 'Não autenticado'], 401);
        }

        if (!in_array($user->role, $roles)) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        return $next($request);
    }

}
