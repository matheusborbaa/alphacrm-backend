<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ForceJsonResponse
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        return $response->header('Content-Type','application/json; charset=UTF-8');
    }
}
