<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use App\Notifications\ServerCapacityAlertNotification;
use App\Services\HostingerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckServerCapacity extends Command
{
    protected $signature = 'servidor:check-capacity {--force : Ignora dedup e força notificação se em crítico}';
    protected $description = 'Verifica uso de disco/RAM do servidor e notifica admins quando ultrapassa o threshold.';

    private const REMINDER_INTERVAL_SECONDS = 86_400;

    private const DISK_THRESHOLD_PERCENT = 75.0;
    private const RAM_THRESHOLD_PERCENT  = 90.0;

    public function handle(HostingerService $hostinger): int
    {
        if (!$hostinger->isConfigured()) {

            Log::info('CheckServerCapacity: monitoramento não configurado, pulando.');
            $this->warn('Monitoramento do servidor não configurado. Pulando.');
            return self::SUCCESS;
        }

        $status = $hostinger->getStatus();
        if (!($status['ok'] ?? false)) {

            Log::warning('CheckServerCapacity: falha ao obter status do servidor', [
                'reason' => $status['reason'] ?? null,
            ]);
            $this->warn('Falha ao obter status do servidor. Nenhum alerta disparado.');
            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');

        $this->evaluateMetric(
            metric:     'disk',
            percent:    (float) ($status['disk_percent'] ?? 0),
            threshold:  self::DISK_THRESHOLD_PERCENT,
            usedBytes:  (int)   ($status['disk_used_bytes']  ?? 0),
            totalBytes: (int)   ($status['disk_total_bytes'] ?? 0),
            force:      $force,
        );

        $this->evaluateMetric(
            metric:     'ram',
            percent:    (float) ($status['ram_percent'] ?? 0),
            threshold:  self::RAM_THRESHOLD_PERCENT,
            usedBytes:  (int)   ($status['ram_used_bytes']  ?? 0),
            totalBytes: (int)   ($status['ram_total_bytes'] ?? 0),
            force:      $force,
        );

        return self::SUCCESS;
    }

    private function evaluateMetric(
        string $metric,
        float  $percent,
        float  $threshold,
        int    $usedBytes,
        int    $totalBytes,
        bool   $force,
    ): void {
        $stateKey      = "_server_alert_state_{$metric}";
        $lastNotifyKey = "_server_alert_last_notify_{$metric}";

        $prevState  = Setting::get($stateKey, 'ok');
        $lastNotify = (int) Setting::get($lastNotifyKey, 0);

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

        $now = time();
        $shouldNotify = $force
            || $prevState === 'ok'
            || ($now - $lastNotify) >= self::REMINDER_INTERVAL_SECONDS;

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

            $admin->notify($notification);
        }

        Setting::set($stateKey, 'critical');
        Setting::set($lastNotifyKey, $now);

        $this->warn(sprintf(
            '[%s] %.1f%% — ALERTA DISPARADO pra %d admin(s). Threshold: %.0f%%.',
            $metric, $percent, $admins->count(), $threshold
        ));
    }

    private function humanAgo(int $seconds): string
    {
        if ($seconds < 60)   return $seconds . 's';
        if ($seconds < 3600) return intdiv($seconds, 60) . 'm';
        return intdiv($seconds, 3600) . 'h';
    }
}
