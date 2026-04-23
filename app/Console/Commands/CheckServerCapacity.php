<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use App\Notifications\ServerCapacityAlertNotification;
use App\Services\HostingerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Vigilância de capacidade do servidor. Roda no scheduler (routes/console.php)
 * e notifica todos os admins quando disco ou RAM cruza o threshold.
 *
 * Executável (manual):
 *   php artisan servidor:check-capacity
 *   php artisan servidor:check-capacity --force  (ignora dedup — útil pra testar)
 *
 * Thresholds (lidos da tabela `settings`, editáveis pela UI):
 *   server_alert_enabled          → liga/desliga tudo (default: true)
 *   server_alert_disk_threshold   → disco em % (default: 75)
 *   server_alert_ram_threshold    → RAM em %   (default: 90)
 *
 * Dedup (pra não spammar o admin a cada hora que o problema persiste):
 *   - Se estava abaixo do threshold e agora passou → notifica imediatamente.
 *   - Se já estava acima e continua acima → notifica de novo só depois de 24h
 *     desde o último alerta daquela métrica. Isso dá 1 lembrete por dia
 *     enquanto o problema não for resolvido.
 *   - Se desceu de volta pra abaixo do threshold → reseta o state, próximo
 *     cruzamento dispara de novo imediatamente.
 *
 * Estado persistido em Setting porque é mais robusto que Cache (sobrevive
 * a redis flush e deploy). As chaves de estado começam com underscore pra
 * diferenciar de settings editáveis pelo admin (`_server_alert_state_*`).
 */
class CheckServerCapacity extends Command
{
    protected $signature = 'servidor:check-capacity {--force : Ignora dedup e força notificação se em crítico}';
    protected $description = 'Verifica uso de disco/RAM do servidor e notifica admins quando ultrapassa o threshold.';

    // Intervalo mínimo entre alertas da MESMA métrica quando em estado crítico
    // sustentado. 24h = 86400s. Em segundos pra usar direto em timestamps.
    private const REMINDER_INTERVAL_SECONDS = 86_400;

    public function handle(HostingerService $hostinger): int
    {
        if (!Setting::get('server_alert_enabled', true)) {
            $this->info('Alertas de capacidade desligados (server_alert_enabled=false). Saindo.');
            return self::SUCCESS;
        }

        if (!$hostinger->isConfigured()) {
            // Sem API key/VPS ID não dá pra checar. Não é erro — é config
            // incompleta. Log pra dev saber, return success pra scheduler
            // não ficar reportando falha todo tick.
            Log::info('CheckServerCapacity: monitoramento não configurado, pulando.');
            $this->warn('Monitoramento do servidor não configurado. Pulando.');
            return self::SUCCESS;
        }

        $status = $hostinger->getStatus();
        if (!($status['ok'] ?? false)) {
            // Não foi possível obter métricas — NÃO notifica (seria falso
            // positivo). Só loga. O cache de status por 60s garante que
            // se a API cair a gente não entope o log.
            Log::warning('CheckServerCapacity: falha ao obter status do servidor', [
                'reason' => $status['reason'] ?? null,
            ]);
            $this->warn('Falha ao obter status do servidor. Nenhum alerta disparado.');
            return self::SUCCESS;
        }

        $diskThreshold = (float) Setting::get('server_alert_disk_threshold', 75);
        $ramThreshold  = (float) Setting::get('server_alert_ram_threshold',  90);

        $force = (bool) $this->option('force');

        $this->evaluateMetric(
            metric:     'disk',
            percent:    (float) ($status['disk_percent'] ?? 0),
            threshold:  $diskThreshold,
            usedBytes:  (int)   ($status['disk_used_bytes']  ?? 0),
            totalBytes: (int)   ($status['disk_total_bytes'] ?? 0),
            force:      $force,
        );

        $this->evaluateMetric(
            metric:     'ram',
            percent:    (float) ($status['ram_percent'] ?? 0),
            threshold:  $ramThreshold,
            usedBytes:  (int)   ($status['ram_used_bytes']  ?? 0),
            totalBytes: (int)   ($status['ram_total_bytes'] ?? 0),
            force:      $force,
        );

        return self::SUCCESS;
    }

    /**
     * Avalia uma métrica (disk/ram) e dispara notificação se necessário.
     *
     * Fluxo decisório:
     *   1. percent < threshold → limpa estado crítico (se havia) e retorna.
     *   2. percent ≥ threshold + estado anterior era OK → notifica AGORA.
     *   3. percent ≥ threshold + já em crítico há < 24h → não faz nada (dedup).
     *   4. percent ≥ threshold + já em crítico há ≥ 24h → re-notifica (lembrete).
     *   5. --force ignora (2-4) e sempre notifica se está em crítico.
     */
    private function evaluateMetric(
        string $metric,
        float  $percent,
        float  $threshold,
        int    $usedBytes,
        int    $totalBytes,
        bool   $force,
    ): void {
        $stateKey      = "_server_alert_state_{$metric}";       // 'ok' | 'critical'
        $lastNotifyKey = "_server_alert_last_notify_{$metric}"; // unix timestamp

        $prevState  = Setting::get($stateKey, 'ok');
        $lastNotify = (int) Setting::get($lastNotifyKey, 0);

        // Caso 1: abaixo do threshold → tudo OK, reseta se antes estava crítico.
        if ($percent < $threshold) {
            if ($prevState !== 'ok') {
                Setting::set($stateKey, 'ok');
                $this->info(sprintf(
                    '[%s] %.1f%% — voltou ao normal (threshold %.0f%%). Estado resetado.',
                    $metric, $percent, $threshold
                ));
            } else {
                $this->line(sprintf(
                    '[%s] %.1f%% — OK (threshold %.0f%%).',
                    $metric, $percent, $threshold
                ));
            }
            return;
        }

        // Daqui pra baixo: percent >= threshold (estado crítico).
        $now = time();
        $shouldNotify = $force
            || $prevState === 'ok'                                     // transição OK → crítico
            || ($now - $lastNotify) >= self::REMINDER_INTERVAL_SECONDS; // lembrete 24h

        if (!$shouldNotify) {
            $this->warn(sprintf(
                '[%s] %.1f%% — crítico, mas último alerta foi há %s. Dedup ativo.',
                $metric, $percent, $this->humanAgo($now - $lastNotify)
            ));
            return;
        }

        $admins = User::where('role', 'admin')->where('active', true)->get();
        if ($admins->isEmpty()) {
            Log::warning('CheckServerCapacity: nenhum admin ativo pra notificar', [
                'metric'  => $metric,
                'percent' => $percent,
            ]);
            return;
        }

        $notification = new ServerCapacityAlertNotification(
            metric:     $metric,
            percent:    $percent,
            threshold:  $threshold,
            usedBytes:  $usedBytes,
            totalBytes: $totalBytes,
        );

        foreach ($admins as $admin) {
            // notify() é síncrono — o insert em `notifications` acontece
            // aqui mesmo, garantido pro polling do frontend. Canal único
            // (database) por decisão do produto: alerta de capacidade
            // vive só dentro do CRM (sino + banner na home do admin),
            // sem envio de e-mail.
            $admin->notify($notification);
        }

        Setting::set($stateKey, 'critical');
        Setting::set($lastNotifyKey, $now);

        $this->warn(sprintf(
            '[%s] %.1f%% — ALERTA DISPARADO pra %d admin(s). Threshold: %.0f%%.',
            $metric, $percent, $admins->count(), $threshold
        ));
    }

    /** "2h atrás", "35m atrás" — só pro output do console, não é crítico. */
    private function humanAgo(int $seconds): string
    {
        if ($seconds < 60)   return $seconds . 's';
        if ($seconds < 3600) return intdiv($seconds, 60) . 'm';
        return intdiv($seconds, 3600) . 'h';
    }
}
