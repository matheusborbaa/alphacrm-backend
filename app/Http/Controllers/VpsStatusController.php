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
    // Thresholds fixos — mesmos valores de CheckServerCapacity. A UI
    // não expõe edição por decisão de produto (operação previsível).
    private const DISK_THRESHOLD_PERCENT = 75.0;
    private const RAM_THRESHOLD_PERCENT  = 90.0;

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

    /**
     * GET /server/capacity-alerts
     *
     * Retorna SOMENTE as métricas atualmente em estado crítico (percent
     * >= threshold) pra o bloco de alertas do dashboard do admin. Isso
     * é diferente do /vps/status — aquele retorna tudo (números brutos),
     * esse aqui é pro banner e só lista o que precisa de ação.
     *
     * Thresholds são fixos (75% disco, 90% RAM) pra manter a operação
     * previsível — sem UI de configuração. Os mesmos valores estão em
     * CheckServerCapacity (job agendado que dispara notificações).
     *
     * Resposta:
     *   {
     *     "ok": true,
     *     "alerts": [
     *       {
     *         "metric": "disk"|"ram",
     *         "percent": 78.3,
     *         "threshold": 75,
     *         "used_bytes": ...,
     *         "total_bytes": ...
     *       },
     *       ...
     *     ]
     *   }
     *
     * Quando a integração não está configurada ou a API de métricas falha,
     * devolvemos {ok:true, alerts:[]} — o dashboard simplesmente não
     * mostra o banner (em vez de mostrar erro pro usuário). O monitoramento
     * real acontece no job agendado `servidor:check-capacity`, que já tem
     * tratamento próprio de falhas e dedup.
     */
    public function capacityAlerts(): JsonResponse
    {
        if (!$this->hostinger->isConfigured()) {
            return response()->json(['ok' => true, 'alerts' => []]);
        }

        $status = $this->hostinger->getStatus();
        if (!($status['ok'] ?? false)) {
            // Falha transiente de API — não bloqueia o dashboard. O job
            // `servidor:check-capacity` (scheduler) é o ponto autoritativo;
            // se estiver mesmo em estado crítico, o admin recebe notificação
            // direto na UI (sino + banner), independente desse endpoint.
            return response()->json(['ok' => true, 'alerts' => []]);
        }

        $alerts = [];

        $diskPct = (float) ($status['disk_percent'] ?? 0);
        if ($diskPct >= self::DISK_THRESHOLD_PERCENT) {
            $alerts[] = [
                'metric'      => 'disk',
                'percent'     => round($diskPct, 1),
                'threshold'   => (int) self::DISK_THRESHOLD_PERCENT,
                'used_bytes'  => (int) ($status['disk_used_bytes']  ?? 0),
                'total_bytes' => (int) ($status['disk_total_bytes'] ?? 0),
            ];
        }

        $ramPct = (float) ($status['ram_percent'] ?? 0);
        if ($ramPct >= self::RAM_THRESHOLD_PERCENT) {
            $alerts[] = [
                'metric'      => 'ram',
                'percent'     => round($ramPct, 1),
                'threshold'   => (int) self::RAM_THRESHOLD_PERCENT,
                'used_bytes'  => (int) ($status['ram_used_bytes']  ?? 0),
                'total_bytes' => (int) ($status['ram_total_bytes'] ?? 0),
            ];
        }

        return response()->json([
            'ok'     => true,
            'alerts' => $alerts,
        ]);
    }
}
