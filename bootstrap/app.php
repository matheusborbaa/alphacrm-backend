<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\BasicAuthDocs;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )

    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);
        $middleware->append(\App\Http\Middleware\ForceJsonResponse::class);
        $middleware->validateCsrfTokens(except: [
        'api/*',
    ]);

        $middleware->statefulApi();

        $middleware->appendToGroup('api', \App\Http\Middleware\EnsureFreshAuthentication::class);

        $middleware->alias([
        'docs.auth' => BasicAuthDocs::class,
        'role' => \App\Http\Middleware\RoleMiddleware::class,
        'auth' => \Illuminate\Auth\Middleware\Authenticate::class,

        'chat.enabled' => \App\Http\Middleware\EnsureChatEnabled::class,

        'corretor.area.enabled' => \App\Http\Middleware\EnsureCorretorAreaEnabled::class,

        'academy.enabled' => \App\Http\Middleware\EnsureAcademyEnabled::class,

        'fresh-auth' => \App\Http\Middleware\EnsureFreshAuthentication::class,

        'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,

    ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {

    })->create();
