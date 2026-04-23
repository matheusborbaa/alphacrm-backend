<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Alerta de capacidade do servidor do CRM.
 *
 * Disparado pelo comando agendado `servidor:check-capacity` quando disco
 * ou RAM ultrapassa o threshold configurado (defaults: 75% disco, 90% RAM).
 * Alvo: todos os usuários com role 'admin' — só eles conseguem acionar
 * upgrade do servidor.
 *
 * Canais:
 *   - database: popup + badge na UI (mesmo fluxo das outras notificações).
 *
 * Por escolha do produto, esse alerta NÃO vai por e-mail — só dentro do
 * próprio CRM (sino + banner no dashboard). O admin logado é o único
 * destinatário que interessa e o sino já toca som via `sound=alert`.
 *
 * NÃO implementa ShouldQueue (mesma razão do LeadAssignedNotification —
 * o projeto não roda queue worker permanente).
 *
 * Mensagem é parametrizada pra reutilizar o mesmo payload pros dois
 * tipos de alerta (disco e memória) — a diferença é só o texto.
 */
class ServerCapacityAlertNotification extends Notification
{
    use Queueable;

    /**
     * @param string $metric   'disk' ou 'ram' — define copy e chave do payload
     * @param float  $percent  Percentual atual (ex: 78.3)
     * @param float  $threshold Limite que foi cruzado (ex: 75.0)
     * @param int    $usedBytes  Absoluto usado (pra contexto no payload)
     * @param int    $totalBytes Absoluto total
     */
    public function __construct(
        public string $metric,
        public float  $percent,
        public float  $threshold,
        public int    $usedBytes,
        public int    $totalBytes,
    ) {
    }

    /**
     * Canais: só database. Nada de e-mail — alerta de capacidade fica
     * contido no próprio CRM conforme decisão do produto.
     */
    public function via($notifiable): array
    {
        return ['database'];
    }

    /**
     * Payload pra tabela `notifications`. O frontend lê via /notifications,
     * incrementa o badge e toca som. `type` = 'server_capacity' serve pra
     * UI eventualmente renderizar diferente (ícone de alerta, cor, etc).
     */
    public function toDatabase($notifiable): array
    {
        return [
            'type'       => 'server_capacity',
            'metric'     => $this->metric,
            'title'      => $this->titleFor($this->metric),
            'message'    => $this->messageFor($this->metric, $this->percent, $this->threshold),
            'percent'    => round($this->percent, 1),
            'threshold'  => round($this->threshold, 1),
            'used_bytes' => $this->usedBytes,
            'total_bytes'=> $this->totalBytes,
            'sound'      => 'alert',
        ];
    }

    /* ---------------------------------------------------------------- */

    private function titleFor(string $metric): string
    {
        return $metric === 'disk'
            ? 'Espaço em disco do servidor crítico'
            : 'Memória do servidor crítica';
    }

    private function messageFor(string $metric, float $percent, float $threshold): string
    {
        $pct = number_format($percent, 1, ',', '.');
        $thr = number_format($threshold, 0, ',', '.');
        $label = $metric === 'disk' ? 'disco' : 'memória (RAM)';

        return "Uso de {$label} em {$pct}% (limite de alerta: {$thr}%). " .
               "Entre em contato com o suporte para avaliar o upgrade do servidor.";
    }
}
