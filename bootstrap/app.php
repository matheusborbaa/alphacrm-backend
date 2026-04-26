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
    // Sprint 4.5 — registra a rota /broadcasting/auth com middleware
    // auth:sanctum (Bearer token). Sem isso a rota não existe e o Echo
    // não consegue subscribe em canais privados.
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

        // Sprint 3.0a — reauth por senha em rotas sensíveis. Roda em todo
        // request da API; o middleware em si decide se aplica (pula GET
        // normais, pula /auth/*, pula se user não autenticado etc.).
        $middleware->appendToGroup('api', \App\Http\Middleware\EnsureFreshAuthentication::class);

        $middleware->alias([
        'docs.auth' => BasicAuthDocs::class,
        'role' => \App\Http\Middleware\RoleMiddleware::class,
        'auth' => \Illuminate\Auth\Middleware\Authenticate::class,
        // Sprint 4.x — 403 nos endpoints de /chat quando o setting
        // `chat_enabled` estiver false. Colado no grupo prefix('chat').
        'chat.enabled' => \App\Http\Middleware\EnsureChatEnabled::class,

        // Sprint 3.0a — aplicar em rotas que escrevem dados ou revelam PII.
        // Devolve 423 se o token passou do password_confirm_idle_minutes.
        'fresh-auth' => \App\Http\Middleware\EnsureFreshAuthentication::class,

        // Sprint Cargos — middleware do Spatie pra checagem de permission
        // com suporte a `|` como OR. O `can:` nativo do Laravel NÃO trata
        // `|` como OR (lê tudo como nome único), causando 403 silencioso.
        // Uso: `permission:perm1|perm2` (passa se tem qualquer uma).
        'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,

    ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
