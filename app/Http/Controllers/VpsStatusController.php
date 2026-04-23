<?php

namespace App\Http\Controllers;

use App\Services\HostingerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * VpsStatusController — expõe métricas do VPS da Hostinger pra aba
 * "Sistema" em Configurações. Admin-only (roteamento já cuida disso).
 *
 * O controller é fino — toda a lógica de API Hostinger + cache está
 * no HostingerService. Aqui só recebemos a request, opcionalmente
 * força refresh via ?refresh=1, e devolvemos JSON.
 */
class VpsStatusController extends Controller
{
    public function __construct(private HostingerService $hostinger) {}

    /**
     * GET /vps/status[?refresh=1]
     *
     * Retorna sempre 200 com um payload que contém 'ok' => true|false.
     * Erros (API Hostinger fora, token inválido, não configurado) vêm
     * com ok=false + mensagem, pra que o frontend renderize um estado
     * amigável sem toast de 5xx.
     */
    public function show(Request $request): JsonResponse
    {
        $refresh = $request->boolean('refresh');

        $payload = $refresh
            ? $this->hostinger->refreshStatus()
            : $this->hostinger->getStatus();

        // Mesmo quando ok=false devolvemos 200 — é informacional, não
        // é um erro de autenticação ou de regra de negócio da nossa API.
        return response()->json($payload);
    }
}
