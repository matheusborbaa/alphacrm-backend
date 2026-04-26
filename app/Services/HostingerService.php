<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HostingerService
{
    private const CACHE_PREFIX = 'hostinger:vps:';
    private const CACHE_TTL    = 60;

    private const HTTP_TIMEOUT = 15;

    private const METRICS_WINDOW_CASCADE = [60, 360, 1440];

    private string $apiBase;
    private ?string $apiKey;
    private ?string $vpsId;

    private int $appDiskQuotaBytes;

    public function __construct()
    {
        $this->apiBase = rtrim((string) config('services.hostinger.api_base', 'https://developers.hostinger.com'), '/');
        $this->apiKey  = config('services.hostinger.api_key');
        $this->vpsId   = config('services.hostinger.vps_id');

        $quotaGb = max(1, (int) config('services.alphacrm_disk.quota_gb', 30));
        $this->appDiskQuotaBytes = $quotaGb * 1024 * 1024 * 1024;
    }

    public function getStatus(): array
    {
        if (!$this->isConfigured()) {
            return [
                'ok'     => false,
                'reason' => 'not_configured',

                'error'  => 'Monitoramento do servidor ainda não foi configurado.',
            ];
        }

        $key = self::CACHE_PREFIX . 'status:' . $this->vpsId;

        $cached = Cache::get($key);
        if (is_array($cached) && ($cached['ok'] ?? false) === true) {
            return $cached;
        }

        $fresh = $this->fetchStatusNow();
        if (($fresh['ok'] ?? false) === true) {
            Cache::put($key, $fresh, self::CACHE_TTL);
        }
        return $fresh;
    }

    public function refreshStatus(): array
    {
        Cache::forget(self::CACHE_PREFIX . 'status:' . $this->vpsId);
        Cache::forget(self::CACHE_PREFIX . 'latest_backup:' . $this->vpsId);
        return $this->getStatus();
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->vpsId);
    }

    public function listVirtualMachines(): array
    {
        if (empty($this->apiKey)) {
            return $this->errorResponse('not_configured', 'HOSTINGER_API_KEY não está definida no .env.');
        }

        try {
            $res = $this->request('GET', '/api/vps/v1/virtual-machines');
            if (!is_array($res)) {
                return $this->errorResponse('upstream_error', 'O provedor não respondeu com uma lista de servidores.');
            }

            $vms = $res['data'] ?? $res;
            if (!is_array($vms)) {
                return $this->errorResponse('upstream_shape', 'Formato inesperado da resposta do provedor.');
            }

            $normalized = [];
            foreach ($vms as $vm) {
                if (!is_array($vm)) continue;
                $normalized[] = [
                    'id'       => $vm['id'] ?? null,
                    'hostname' => $this->scalarFrom($vm['hostname'] ?? null),
                    'plan'     => $this->scalarFrom($vm['plan']  ?? $vm['plan_name'] ?? null, ['name', 'title', 'label', 'slug']),
                    'state'    => $this->scalarFrom($vm['state'] ?? $vm['status']    ?? null),
                    'ipv4'     => $this->extractIpv4($vm),
                    'cpus'     => is_scalar($vm['cpus']   ?? null) ? $vm['cpus']   : null,
                    'memory'   => is_scalar($vm['memory'] ?? null) ? $vm['memory'] : null,
                    'disk'     => is_scalar($vm['disk']   ?? null) ? $vm['disk']   : null,
                ];
            }

            return ['ok' => true, 'vms' => $normalized, 'raw' => $vms];

        } catch (\Throwable $e) {
            Log::warning('HostingerService.listVirtualMachines falhou', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('exception', $this->humanizeException($e));
        }
    }

    private function fetchStatusNow(): array
    {
        $vmPath      = "/api/vps/v1/virtual-machines/{$this->vpsId}";
        $metricsPath = "/api/vps/v1/virtual-machines/{$this->vpsId}/metrics";

        try {
            $vm = $this->request('GET', $vmPath);
            if (!$vm) {
                return $this->errorResponse('upstream_error', 'Não foi possível obter o status do servidor no momento.');
            }

            $metrics = [];
            foreach (self::METRICS_WINDOW_CASCADE as $minutes) {
                $resp = $this->request('GET', $metricsPath, [
                    'date_from' => now()->subMinutes($minutes)->toIso8601String(),
                    'date_to'   => now()->toIso8601String(),
                ]) ?? [];

                if ($this->metricsHaveUsefulData($resp)) {
                    $metrics = $resp;
                    break;
                }

                $metrics = $resp ?: $metrics;
            }

            return $this->buildPayload($vm, $metrics);

        } catch (\Throwable $e) {

            Log::warning('HostingerService.fetchStatusNow falhou', [
                'error' => $e->getMessage(),
                'vps_id' => $this->vpsId,
            ]);
            return $this->errorResponse('exception', $this->humanizeException($e));
        }
    }

    private function metricsHaveUsefulData($resp): bool
    {
        if (!is_array($resp) || empty($resp)) return false;
        $series = $resp['data'] ?? $resp;
        foreach (['cpu_usage', 'ram_usage', 'disk_space'] as $key) {
            $block = $series[$key] ?? null;
            if (!is_array($block)) continue;
            $usage = $block['usage'] ?? $block;
            if (is_array($usage) && count($usage) > 0) {

                foreach ($usage as $v) {
                    if (is_numeric($v)) return true;
                }
            }
        }
        return false;
    }

    private function request(string $method, string $path, array $query = []): ?array
    {
        $url = $this->apiBase . $path;

        $res = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout(self::HTTP_TIMEOUT)
            ->retry(1, 300)
            ->{strtolower($method)}($url, $query);

        if (!$res->successful()) {
            Log::info('HostingerService.request não-2xx', [
                'method' => $method,
                'path'   => $path,
                'status' => $res->status(),
                'body'   => substr((string) $res->body(), 0, 500),
            ]);
            return null;
        }

        $json = $res->json();
        return is_array($json) ? $json : null;
    }

    private function buildPayload(array $vm, array $metrics): array
    {

        $vmData = $vm['data'] ?? $vm;

        $status = $this->scalarFrom($vmData['status'] ?? $vmData['state'] ?? 'unknown') ?: 'unknown';
        $uptime = (int) (is_numeric($vmData['uptime'] ?? null) ? $vmData['uptime']
                      : (is_numeric($vmData['uptime_seconds'] ?? null) ? $vmData['uptime_seconds'] : 0));

        if ($uptime <= 0) {
            $seriesTmp = $metrics['data'] ?? $metrics;
            $uptimeBlock = $seriesTmp['uptime'] ?? null;
            if (is_array($uptimeBlock)) {
                $n = $this->normalizeMetric($uptimeBlock);
                if (!empty($n['points'])) {
                    $lastVal = (float) end($n['points']);
                    $unit = strtolower($n['unit'] ?: 'milliseconds');

                    $uptime = match ($unit) {
                        'milliseconds', 'ms' => (int) round($lastVal / 1000),
                        'microseconds', 'us' => (int) round($lastVal / 1_000_000),
                        'minutes', 'm'       => (int) round($lastVal * 60),
                        'hours', 'h'         => (int) round($lastVal * 3600),
                        default              => (int) $lastVal,
                    };
                }
            }
        }

        $ramTotal  = $this->extractBytes($vmData, ['memory_bytes', 'memory', 'ram_bytes', 'ram'], 'MB');
        $diskTotal = $this->extractBytes($vmData, ['disk_bytes', 'disk_space', 'disk'], 'MB');

        $series = $metrics['data'] ?? $metrics;

        $cpuAvg = $this->avgFromMetric($series['cpu_usage'] ?? []);

        if ($cpuAvg <= 0) {
            foreach (['cpu_usage', 'cpu_percent', 'cpu_load'] as $k) {
                if (isset($vmData[$k]) && is_numeric($vmData[$k])) {
                    $cpuAvg = (float) $vmData[$k];
                    break;
                }
            }
        }

        $ramUsed = $this->lastBytesFromMetric($series['ram_usage'] ?? []);
        if ($ramUsed <= 0) {

            $ramUsed = $this->extractBytes(
                $vmData,
                ['ram_used_bytes', 'ram_used', 'memory_used_bytes', 'memory_used', 'used_memory'],
                'MB'
            );
        }

        $ramPercentRaw = null;
        foreach (['ram_percent', 'memory_percent', 'ram_usage_percent', 'memory_usage_percent'] as $k) {
            if (isset($vmData[$k]) && is_numeric($vmData[$k])) {
                $ramPercentRaw = (float) $vmData[$k];
                break;
            }
        }
        if ($ramUsed <= 0 && $ramTotal > 0 && $ramPercentRaw !== null) {
            $ramUsed = (int) round($ramTotal * ($ramPercentRaw / 100));
        }

        $diskUsed = $this->lastBytesFromMetric(
            $series['disk_space'] ?? $series['disk_usage'] ?? $series['disk_used'] ?? $series['disk'] ?? []
        );
        if ($diskUsed <= 0) {

            $diskUsed = $this->extractBytes(
                $vmData,
                ['disk_used_bytes', 'disk_used', 'used_disk', 'disk_usage'],
                'MB'
            );
        }

        $diskPercentRaw = null;
        foreach (['disk_percent', 'disk_usage_percent', 'disk_used_percent'] as $k) {
            if (isset($vmData[$k]) && is_numeric($vmData[$k])) {
                $diskPercentRaw = (float) $vmData[$k];
                break;
            }
        }
        if ($diskUsed <= 0 && $diskTotal > 0 && $diskPercentRaw !== null) {
            $diskUsed = (int) round($diskTotal * ($diskPercentRaw / 100));
        }

        $netIn  = $this->avgFromMetric($series['incoming_traffic'] ?? $series['network_in']  ?? $series['net_in']  ?? []);
        $netOut = $this->avgFromMetric($series['outgoing_traffic'] ?? $series['network_out'] ?? $series['net_out'] ?? []);

        $appDiskTotal = $this->appDiskQuotaBytes;

        $appDiskPct   = $appDiskTotal > 0
            ? round(min(100.0, $diskUsed / $appDiskTotal * 100), 1)
            : 0.0;

        $latestBackupResp = $this->getLatestBackup();
        $latestBackup = ($latestBackupResp['ok'] ?? false) === true
            ? ($latestBackupResp['backup'] ?? null)
            : null;

        return [
            'ok'                    => true,
            'fetched_at'            => now()->toIso8601String(),
            'status'                => $status,
            'uptime_seconds'        => $uptime,
            'uptime_human'          => $this->humanUptime($uptime),

            'plan'                  => $this->scalarFrom($vmData['plan_name'] ?? $vmData['plan'] ?? null, ['name', 'title', 'label', 'slug']),
            'os'                    => $this->scalarFrom($vmData['os_name']   ?? $vmData['os']   ?? $vmData['template'] ?? null, ['name', 'title', 'label', 'slug']),
            'hostname'              => $this->scalarFrom($vmData['hostname']  ?? null),
            'ipv4'                  => $this->extractIpv4($vmData),

            'cpu_percent'           => round($cpuAvg, 1),

            'ram_total_bytes'       => $ramTotal,
            'ram_used_bytes'        => $ramUsed,

            'ram_percent'           => $ramPercentRaw !== null
                ? round($ramPercentRaw, 1)
                : ($ramTotal > 0 ? round($ramUsed / $ramTotal * 100, 1) : 0.0),

            'disk_total_bytes'      => $appDiskTotal,
            'disk_used_bytes'       => $diskUsed,
            'disk_percent'          => $appDiskPct,

            'net_in_bytes_per_sec'  => round($netIn,  0),
            'net_out_bytes_per_sec' => round($netOut, 0),

            'latest_backup'         => $latestBackup,
        ];
    }

    public function getLatestBackup(): array
    {
        if (!$this->isConfigured()) {
            return $this->errorResponse('not_configured', 'Monitoramento do servidor ainda não foi configurado.');
        }

        $cacheKey = self::CACHE_PREFIX . 'latest_backup:' . $this->vpsId;

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && ($cached['ok'] ?? false) === true) {
            return $cached;
        }

        try {

            $resp = $this->request('GET', "/api/vps/v1/virtual-machines/{$this->vpsId}/backups", [
                'page' => 1,
            ]);

            if (!is_array($resp)) {
                return $this->errorResponse('upstream_error', 'Não foi possível obter o histórico de backups.');
            }

            $list = $resp['data'] ?? $resp;
            if (!is_array($list) || empty($list)) {

                $result = ['ok' => true, 'backup' => null];
                Cache::put($cacheKey, $result, 900);
                return $result;
            }

            $first = is_array($list[0] ?? null) ? $list[0] : [];
            $backup = [
                'id'                   => isset($first['id']) && is_numeric($first['id']) ? (int) $first['id'] : null,
                'created_at'           => $this->scalarFrom($first['created_at'] ?? null),
                'size_bytes'           => isset($first['size']) && is_numeric($first['size']) ? (int) $first['size'] : 0,
                'location'             => $this->scalarFrom($first['location'] ?? null),
                'restore_time_seconds' => isset($first['restore_time']) && is_numeric($first['restore_time'])
                    ? (int) $first['restore_time']
                    : 0,
            ];

            $result = ['ok' => true, 'backup' => $backup];
            Cache::put($cacheKey, $result, 900);
            return $result;

        } catch (\Throwable $e) {
            Log::warning('HostingerService.getLatestBackup falhou', [
                'error'  => $e->getMessage(),
                'vps_id' => $this->vpsId,
            ]);
            return $this->errorResponse('exception', $this->humanizeException($e));
        }
    }

    public function refreshLatestBackup(): array
    {
        Cache::forget(self::CACHE_PREFIX . 'latest_backup:' . $this->vpsId);
        return $this->getLatestBackup();
    }

    private function extractBytes(array $data, array $keys, string $unit): int
    {
        foreach ($keys as $k) {
            if (!isset($data[$k])) continue;
            $raw = $data[$k];

            if (is_array($raw)) {
                foreach (['value', 'bytes', 'size', 'total', 'used'] as $sub) {
                    if (isset($raw[$sub]) && is_numeric($raw[$sub])) {
                        $raw = $raw[$sub];
                        break;
                    }
                }
            }
            if (!is_numeric($raw)) continue;
            $v = (float) $raw;
            if ($v <= 0) return 0;

            if ($v > 10_000_000) return (int) $v;
            return match (strtoupper($unit)) {
                'GB' => (int) ($v * 1024 * 1024 * 1024),
                'MB' => (int) ($v * 1024 * 1024),
                'KB' => (int) ($v * 1024),
                default => (int) $v,
            };
        }
        return 0;
    }

    private function normalizeMetric($block): array
    {
        if (!is_array($block) || empty($block)) {
            return ['unit' => '', 'points' => []];
        }

        if (isset($block['usage']) && is_array($block['usage'])) {
            $unit = is_string($block['unit'] ?? null) ? (string) $block['unit'] : '';

            $usage = $block['usage'];
            ksort($usage, SORT_NUMERIC);
            $points = [];
            foreach ($usage as $v) {
                if (is_numeric($v)) $points[] = (float) $v;
            }
            return ['unit' => $unit, 'points' => $points];
        }

        $points = [];
        foreach ($block as $pt) {
            $v = is_array($pt) ? ($pt[1] ?? $pt['value'] ?? null) : $pt;
            if (is_numeric($v)) $points[] = (float) $v;
        }
        return ['unit' => '', 'points' => $points];
    }

    private function avgFromMetric($block): float
    {
        $n = $this->normalizeMetric($block);
        if (empty($n['points'])) return 0.0;
        return array_sum($n['points']) / count($n['points']);
    }

    private function lastBytesFromMetric($block, string $defaultUnit = 'bytes'): int
    {
        $n = $this->normalizeMetric($block);
        if (empty($n['points'])) return 0;
        $v = (float) end($n['points']);
        if ($v <= 0) return 0;
        $unit = strtolower($n['unit'] ?: $defaultUnit);
        return match ($unit) {
            'bytes', 'b'             => (int) $v,
            'kb', 'kib', 'kilobytes' => (int) ($v * 1024),
            'mb', 'mib', 'megabytes' => (int) ($v * 1024 * 1024),
            'gb', 'gib', 'gigabytes' => (int) ($v * 1024 * 1024 * 1024),

            default                  => $v > 10_000_000 ? (int) $v : (int) ($v * 1024 * 1024),
        };
    }

    private function avgSeries(array $series): float
    {
        if (empty($series)) return 0.0;
        $sum = 0.0; $n = 0;
        foreach ($series as $pt) {
            $v = is_array($pt) ? ($pt[1] ?? $pt['value'] ?? null) : $pt;
            if (is_numeric($v)) { $sum += (float) $v; $n++; }
        }
        return $n > 0 ? $sum / $n : 0.0;
    }

    private function lastBytes(array $series, string $unit): int
    {
        if (empty($series)) return 0;
        $last = end($series);
        $v = is_array($last) ? ($last[1] ?? $last['value'] ?? 0) : $last;
        $v = (float) $v;
        if ($v <= 0) return 0;
        if ($v > 10_000_000) return (int) $v;
        return match (strtoupper($unit)) {
            'GB' => (int) ($v * 1024 * 1024 * 1024),
            'MB' => (int) ($v * 1024 * 1024),
            'KB' => (int) ($v * 1024),
            default => (int) $v,
        };
    }

    private function humanUptime(int $seconds): string
    {
        if ($seconds <= 0) return '—';
        $d = intdiv($seconds, 86400); $seconds %= 86400;
        $h = intdiv($seconds, 3600);  $seconds %= 3600;
        $m = intdiv($seconds, 60);

        if ($d > 0) return "{$d}d {$h}h";
        if ($h > 0) return "{$h}h {$m}m";
        return "{$m}m";
    }

    private function errorResponse(string $reason, string $msg): array
    {
        return ['ok' => false, 'reason' => $reason, 'error' => $msg];
    }

    private function humanizeException(\Throwable $e): string
    {
        $msg = strtolower($e->getMessage());

        if (str_contains($msg, 'timed out') || str_contains($msg, 'timeout')) {
            return 'O servidor demorou demais pra responder. Tente novamente em alguns instantes.';
        }
        if (str_contains($msg, 'could not resolve host') || str_contains($msg, 'name or service not known')) {
            return 'Não foi possível resolver o endereço do servidor de monitoramento.';
        }
        if (str_contains($msg, 'connection refused') || str_contains($msg, 'failed to connect')) {
            return 'Não foi possível estabelecer conexão com o servidor de monitoramento.';
        }
        if (str_contains($msg, 'ssl') || str_contains($msg, 'certificate')) {
            return 'Problema de certificado na conexão com o servidor de monitoramento.';
        }

        return 'Não foi possível obter o status do servidor no momento.';
    }

    private function scalarFrom($value, array $subKeys = ['name', 'title', 'label', 'value']): string
    {
        if (is_scalar($value)) return (string) $value;
        if (is_array($value)) {
            foreach ($subKeys as $k) {
                if (isset($value[$k]) && is_scalar($value[$k])) {
                    return (string) $value[$k];
                }
            }
        }
        return '';
    }

    private function extractIpv4(array $vm): string
    {
        foreach (['ipv4', 'ip', 'primary_ipv4', 'ip_address'] as $k) {
            if (!isset($vm[$k])) continue;
            $v = $vm[$k];
            if (is_string($v) && $v !== '') return $v;
            if (is_array($v)) {

                foreach ($v as $entry) {
                    if (is_string($entry) && $entry !== '') return $entry;
                    if (is_array($entry)) {
                        foreach (['address', 'ipv4', 'ip', 'value'] as $sub) {
                            if (!empty($entry[$sub]) && is_string($entry[$sub])) {
                                return $entry[$sub];
                            }
                        }
                    }
                }
            }
        }

        foreach (['ipv4_addresses', 'addresses', 'ips'] as $k) {
            if (!isset($vm[$k]) || !is_array($vm[$k])) continue;
            foreach ($vm[$k] as $entry) {
                if (is_string($entry) && $entry !== '') return $entry;
                if (is_array($entry)) {
                    foreach (['address', 'ipv4', 'ip', 'value'] as $sub) {
                        if (!empty($entry[$sub]) && is_string($entry[$sub])) {
                            return $entry[$sub];
                        }
                    }
                }
            }
        }
        return '';
    }
}
