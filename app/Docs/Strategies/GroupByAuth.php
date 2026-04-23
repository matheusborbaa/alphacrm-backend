<?php

namespace App\Docs\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;

/**
 * Estratégia custom de metadata pro Scribe: agrupa endpoints em dois
 * grupos automáticos baseados em se a rota exige autenticação ou não.
 *
 * Motivação: queremos uma visão de "o que está ABERTO" sem precisar
 * anotar @group manualmente em 158 endpoints. A fonte da verdade é
 * o próprio middleware da rota — então reaproveitamos isso.
 *
 * Critério: se a rota tem qualquer middleware que começa com "auth"
 * (cobre 'auth', 'auth:sanctum', 'auth:api', 'auth.basic' etc),
 * consideramos autenticada. Caso contrário, pública.
 *
 * Observação sobre subGroup: usamos subGroup pra preservar a noção de
 * contexto (ex.: "Leads", "Empreendimentos") inferida pelo nome da
 * rota/controller, enquanto o group fica reservado pro eixo auth.
 *
 * Em caso de conflito com @group anotado manualmente em algum
 * controller, esta strategy vence porque é a última registrada na
 * config. Se quisermos preservar @group existente, basta mover esta
 * strategy pra ANTES de GetFromGroupAttribute na config.
 */
class GroupByAuth extends Strategy
{
    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        $middlewares = $endpointData->route->gatherMiddleware();

        $isAuthenticated = false;
        foreach ($middlewares as $m) {
            // `$m` pode ser string ('auth:sanctum') ou FQCN
            // (App\Http\Middleware\Authenticate::class). Cobrimos os dois.
            if (is_string($m) && (str_starts_with($m, 'auth') || str_contains($m, 'Authenticate'))) {
                $isAuthenticated = true;
                break;
            }
        }

        // Deriva um subGroup a partir do primeiro segmento do path
        // (ex.: "api/leads/{lead}" -> "leads"). Capitaliza pra ficar
        // bonito na UI do Scribe.
        $uri = $endpointData->route->uri();
        $segments = array_values(array_filter(explode('/', $uri)));
        $subGroup = null;
        if (count($segments) >= 2) {
            // segmento 0 é "api" — o 1 é o recurso real.
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
