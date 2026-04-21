<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BasicAuthDocs
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle($request, Closure $next)
    {
        $user = env('DOCS_USER');
        $pass = env('DOCS_PASS');

        if (
            $request->getUser() !== $user ||
            $request->getPassword() !== $pass
        ) {
            return response('Unauthorized', 401, [
                'WWW-Authenticate' => 'Basic',
            ]);
        }

        return $next($request);
    }
}
