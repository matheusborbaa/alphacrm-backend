<?php

namespace App\Docs\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;

class GroupByAuth extends Strategy
{
    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        $middlewares = $endpointData->route->gatherMiddleware();

        $isAuthenticated = false;
        foreach ($middlewares as $m) {

            if (is_string($m) && (str_starts_with($m, 'auth') || str_contains($m, 'Authenticate'))) {
                $isAuthenticated = true;
                break;
            }
        }

        $uri = $endpointData->route->uri();
        $segments = array_values(array_filter(explode('/', $uri)));
        $subGroup = null;
        if (count($segments) >= 2) {

            $candidate = $segments[1] ?? null;
            if ($candidate && !str_starts_with($candidate, '{')) {
                $subGroup = ucfirst($candidate);
            }
        }

        return [
            'groupName'       => $isAuthenticated
                ? 'Rotas Autenticadas (exigem token)'
                : 'Rotas Publicas (SEM autenticacao)',
            'groupDescription' => $isAuthenticated
                ? 'Endpoints protegidos por auth:sanctum ou equivalente. Precisam de Bearer token valido.'
                : 'Endpoints acessiveis sem token. Revisar se alguma rota nao deveria estar aberta.',
            'subgroup'        => $subGroup,
            'authenticated'   => $isAuthenticated,
        ];
    }
}
