<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ForceJsonResponse
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return $response;
        }

        if ($request->is('docs', 'docs/*', 'docs.openapi', 'docs.postman')) {
            return $response;
        }

        $current = $response->headers->get('Content-Type');
        if ($current && !str_starts_with($current, 'text/html')) {
            return $response;
        }

        $response->headers->set('Content-Type', 'application/json; charset=UTF-8');

        return $response;
    }
}
