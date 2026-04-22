<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Força Content-Type: application/json nas respostas da API — útil porque
 * o handler de erros do Laravel cospe HTML por padrão em alguns casos, e
 * no front a gente sempre faz res.json().
 *
 * ATENÇÃO: precisa pular respostas que NÃO são JSON:
 *   - StreamedResponse  -> download via response()->streamDownload(...)
 *   - BinaryFileResponse-> Storage::download() / response()->file(...)
 *   - Qualquer resposta que já setou Content-Type manualmente (ex.: text/csv,
 *     application/pdf, image/png). Se sobrescrever pra JSON, o browser vai
 *     salvar o arquivo corrompido / renderizar errado.
 *
 * Também usa `$response->headers->set(...)` em vez de `$response->header(...)`
 * porque o último só existe em Illuminate\Http\Response — StreamedResponse
 * é da Symfony e não tem esse helper.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Downloads / streams nunca são JSON.
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return $response;
        }

        // Se a rota já definiu um Content-Type explícito diferente de HTML
        // (default quando nada foi setado), respeitamos. O "text/html" sem
        // configuração é tipicamente erro renderizado pelo framework — esse
        // a gente converte pra JSON pra manter a API consistente.
        $current = $response->headers->get('Content-Type');
        if ($current && !str_starts_with($current, 'text/html')) {
            return $response;
        }

        $response->headers->set('Content-Type', 'application/json; charset=UTF-8');

        return $response;
    }
}
