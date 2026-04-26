<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ServerCapacityAlertNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $metric,
        public float  $percent,
        public float  $threshold,
        public int    $usedBytes,
        public int    $totalBytes,
    ) {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

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
