<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HostingerService — wrapper fino da API pública da Hostinger
 * (https://developers.hostinger.com).
 *
 * Usado pela aba "Sistema" em Configurações pra exibir health do VPS
 * onde o CRM está hospedado. Todas as respostas são cacheadas por 60s
 * pra não estourar rate limit da Hostinger (a API tem limites por
 * token) e porque métricas mudam devagar — polling agressivo é
 * desperdício.
 *
 * Endpoints da Hostinger usados:
 *   - GET /api/vps/v1/virtual-machines/{id}          → info geral (status, plan, uptime, OS)
 *   - GET /api/vps/v1/virtual-machines/{id}/metrics  → séries de CPU/RAM/disco/net
 *
 * Se a API key ou VPS_ID não estão configurados, ou a Hostinger está
 * fora do ar, retornamos `['ok' => false, 'error' => '...']` pra que
 * o frontend mostre um estado de fallback em vez de um 500.
 */
class HostingerService
{
    private const CACHE_PREFIX = 'hostinger:vps:';
    private const CACHE_TTL    = 60;       // segundos
    private const HTTP_TIMEOUT = 8;        // segundos — a API pode ser lenta
    private const METRICS_WINDOW_MIN = 10; // olhar só os últimos 10min pras médias

    private string $apiBase;
    private ?string $apiKey;
    private ?string $vpsId;

    public function __construct()
    {
        $this->apiBase = rtrim((string) config('services.hostinger.api_base', 'https://developers.hostinger.com'), '/');
        $this->apiKey  = config('services.hostinger.api_key');
        $this->vpsId   = config('services.hostinger.vps_id');
    }

    /**
     * Compila o payload de status consolidado pro frontend. Faz 1-2 chamadas
     * HTTP (info da VM + métricas) e devolve dados prontos pra render —
     * nada de deixar o frontend fazer matemática de bytes/segundos.
     *
     * Retorno em caso de sucesso:
     *   [
     *     'ok' => true,
     *     'fetched_at' => ISO-8601,
     *     'status' => 'running'|'stopped'|...,
     *     'uptime_seconds' => int,
     *     'uptime_human' => 'Xd Yh Zm',
     *     'plan' => 'KVM 4' (string, opcional),
     *     'os' => 'Ubuntu 22.04' (string, opcional),
     *     'cpu_percent' => float,            // média da janela
     *     'ram_total_bytes' => int,
     *     'ram_used_bytes' => int,
     *     'ram_percent' => float,
     *     'disk_total_bytes' => int,
     *     'disk_used_bytes' => int,
     *     'disk_percent' => float,
     *     'net_in_bytes_per_sec' => float,   // média da janela
     *     'net_out_bytes_per_sec' => float,  // média da janela
     *   ]
     *
     * Retorno em caso de erro:
     *   ['ok' => false, 'error' => 'mensagem humana', 'reason' => 'code']
     */
    public function getStatus(): array
    {
        if (!$this->isConfigured()) {
            return [
                'ok'     => false,
                'reason' => 'not_configured',
                'error'  => 'Integração com a Hostinger não está configurada. Defina HOSTINGER_API_KEY e HOSTINGER_VPS_ID no .env.',
            ];
        }

        return Cache::remember(
            self::CACHE_PREFIX . 'status:' . $this->vpsId,
            self::CACHE_TTL,
            fn() => $this->fetchStatusNow()
        );
    }

    /** Força um refresh ignorando cache. Usado quando o admin clica "Atualizar". */
    public function refreshStatus(): array
    {
        Cache::forget(self::CACHE_PREFIX . 'status:' . $this->vpsId);
        return $this->getStatus();
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->vpsId);
    }

    /* ==================================================================
     * Internals
     * ================================================================== */

    private function fetchStatusNow(): array
    {
        $vmPath      = "/api/vps/v1/virtual-machines/{$this->vpsId}";
        $metricsPath = "/api/vps/v1/virtual-machines/{$this->vpsId}/metrics";

        try {
            $vm = $this->request('GET', $vmPath);
            if (!$vm) {
                return $this->errorResponse('upstream_error', 'Não foi possível obter informações do VPS na Hostinger.');
            }

            // Métricas da última janela (10 min) — pegamos média do CPU,
            // último ponto de RAM/disk, e soma/delta pro tráfego de rede.
            $metrics = $this->request('GET', $metricsPath, [
                'date_from' => now()->subMinutes(self::METRICS_WINDOW_MIN)->toIso8601String(),
                'date_to'   => now()->toIso8601String(),
            ]) ?? [];

            return $this->buildPayload($vm, $metrics);

        } catch (\Throwable $e) {
            Log::warning('HostingerService.fetchStatusNow falhou', [
                'error' => $e->getMessage(),
                'vps_id' => $this->vpsId,
            ]);
            return $this->errorResponse('exception', 'Falha ao contatar a Hostinger: ' . $e->getMessage());
        }
    }

    /**
     * Wrapper HTTP com Bearer + timeout. Retorna array decodificado em
     * sucesso (2xx), null em qualquer falha — callers checam e respondem
     * com payload de erro.
     */
    private function request(string $method, string $path, array $query = []): ?array
    {
        $url = $this->apiBase . $path;

        $res = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout(self::HTTP_TIMEOUT)
            ->retry(1, 300)   // 1 retry rápido em erros transientes
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

    /**
     * Consolida os dois responses em um payload achatado. O formato exato
     * da API da Hostinger pode evoluir — defendemos cada extração com
     * ?? 0 / ?? null pra não quebrar a aba Sistema se algum campo sumir.
     */
    private function buildPayload(array $vm, array $metrics): array
    {
        // "data" wrapper é o padrão Hostinger pra respostas de entidade única.
        $vmData = $vm['data'] ?? $vm;

        $status = (string) ($vmData['status'] ?? $vmData['state'] ?? 'unknown');
        $uptime = (int) ($vmData['uptime'] ?? $vmData['uptime_seconds'] ?? 0);

        // RAM/disk totais vêm na info da VM (em MB na maioria das APIs
        // Hostinger — normalizamos pra bytes). Se aparecer no formato
        // novo (bytes) também funciona porque detectamos o tamanho.
        $ramTotal  = $this->extractBytes($vmData, ['memory_bytes', 'memory', 'ram_bytes', 'ram'], 'MB');
        $diskTotal = $this->extractBytes($vmData, ['disk_bytes', 'disk_space', 'disk'], 'GB');

        // Séries temporais: formato esperado {metric: [[ts, value], ...]}
        $series = $metrics['data'] ?? $metrics;

        $cpuAvg  = $this->avgSeries($series['cpu_usage'] ?? $series['cpu'] ?? []);
        $ramUsed = $this->lastBytes($series['ram_usage'] ?? $series['memory_usage'] ?? [], 'MB');
        $diskUsed = $this->lastBytes($series['disk_usage'] ?? [], 'GB');

        $netIn  = $this->avgSeries($series['network_in']  ?? $series['net_in']  ?? []);
        $netOut = $this->avgSeries($series['network_out'] ?? $series['net_out'] ?? []);

        return [
            'ok'                    => true,
            'fetched_at'            => now()->toIso8601String(),
            'status'                => $status,
            'uptime_seconds'        => $uptime,
            'uptime_human'          => $this->humanUptime($uptime),
            'plan'                  => (string) ($vmData['plan_name'] ?? $vmData['plan'] ?? ''),
            'os'                    => (string) ($vmData['os_name']   ?? $vmData['os']   ?? ''),
            'hostname'              => (string) ($vmData['hostname']  ?? ''),
            'ipv4'                  => (string) ($vmData['ipv4']      ?? $vmData['ip'] ?? ''),

            'cpu_percent'           => round($cpuAvg, 1),

            'ram_total_bytes'       => $ramTotal,
            'ram_used_bytes'        => $ramUsed,
            'ram_percent'           => $ramTotal > 0 ? round($ramUsed / $ramTotal * 100, 1) : 0.0,

            'disk_total_bytes'      => $diskTotal,
            'disk_used_bytes'       => $diskUsed,
            'disk_percent'          => $diskTotal > 0 ? round($diskUsed / $diskTotal * 100, 1) : 0.0,

            'net_in_bytes_per_sec'  => round($netIn,  0),
            'net_out_bytes_per_sec' => round($netOut, 0),
        ];
    }

    /**
     * Extrai um campo numérico do response da VM e converte pra bytes. A
     * Hostinger às vezes devolve em MB ou GB sem sufixo — $unit é um
     * palpite informado. Se o valor já estiver muito grande (provável
     * que seja bytes), respeitamos.
     */
    private function extractBytes(array $data, array $keys, string $unit): int
    {
        foreach ($keys as $k) {
            if (!isset($data[$k])) continue;
            $v = (float) $data[$k];
            if ($v <= 0) return 0;
            // Heurística: se já parece ser bytes (> 10M) devolve direto.
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

    /** Média numérica de uma série [[ts, value], ...] — 0 se vazia. */
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

    /** Último valor da série convertido pra bytes (mesma heurística de extractBytes). */
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

    /** "5d 3h 12m" — formato curto pro card de status. */
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
}
