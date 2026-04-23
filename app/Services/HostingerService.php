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
                // User-facing: genérico. Os nomes de env vars vivem só
                // no .env.example e no comando artisan — quem vê esse
                // texto é o admin do CRM, não o dev de infra.
                'error'  => 'Monitoramento do servidor ainda não foi configurado.',
            ];
        }

        $key = self::CACHE_PREFIX . 'status:' . $this->vpsId;

        // Cache manual (em vez de Cache::remember) pra NÃO cachear
        // respostas de erro — senão um erro transiente fica preso 60s e
        // a UI mostra o erro mesmo depois de o problema ser resolvido.
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

    /**
     * Lista todas as VMs do dono da API key — usado apenas pro comando
     * artisan `hostinger:list-vps` na hora de descobrir qual é o VPS_ID
     * certo pra colocar no .env. Não usa cache (roda uma vez e morre).
     *
     * Retorna:
     *   [
     *     'ok' => true,
     *     'vms' => [
     *       ['id' => 17923, 'hostname' => '...', 'plan' => '...',
     *        'state' => '...', 'ipv4' => '...'],
     *       ...
     *     ]
     *   ]
     * Ou em erro:
     *   ['ok' => false, 'reason' => ..., 'error' => ...]
     *
     * Só precisa da API key — o VPS_ID pode estar vazio.
     */
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

            // A resposta pode vir direto como array OU envelopada em {data: [...]}
            // dependendo da versão da API. Aceitamos os dois.
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
                    'memory'   => is_scalar($vm['memory'] ?? null) ? $vm['memory'] : null,  // em MB (geralmente)
                    'disk'     => is_scalar($vm['disk']   ?? null) ? $vm['disk']   : null,  // em MB (geralmente)
                ];
            }

            return ['ok' => true, 'vms' => $normalized, 'raw' => $vms];

        } catch (\Throwable $e) {
            Log::warning('HostingerService.listVirtualMachines falhou', [
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('exception', 'Falha ao contatar o servidor: ' . $e->getMessage());
        }
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
                return $this->errorResponse('upstream_error', 'Não foi possível obter informações do servidor.');
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
            return $this->errorResponse('exception', 'Falha ao contatar o servidor: ' . $e->getMessage());
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

        // Usa scalarFrom() pra proteger contra campos que o provedor
        // eventualmente devolve como objeto aninhado (vide bug
        // "Array to string conversion" que pegava status/plan).
        $status = $this->scalarFrom($vmData['status'] ?? $vmData['state'] ?? 'unknown') ?: 'unknown';
        $uptime = (int) (is_numeric($vmData['uptime'] ?? null) ? $vmData['uptime']
                      : (is_numeric($vmData['uptime_seconds'] ?? null) ? $vmData['uptime_seconds'] : 0));

        // RAM/disk totais vêm na info da VM (em MB na maioria das APIs
        // Hostinger — normalizamos pra bytes). Se aparecer no formato
        // novo (bytes) também funciona porque detectamos o tamanho.
        // Obs.: API às vezes devolve como objeto em vez de escalar —
        // extractBytes já tem guarda contra is_numeric().
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
            // Todos esses campos passaram pra scalarFrom()/extractIpv4()
            // porque a Hostinger devolve plan/os/image frequentemente como
            // {name: "...", id: N} em vez de string simples, e ipv4 pode
            // vir como array de objetos. Sem o helper, batia em
            // "Array to string conversion".
            'plan'                  => $this->scalarFrom($vmData['plan_name'] ?? $vmData['plan'] ?? null, ['name', 'title', 'label', 'slug']),
            'os'                    => $this->scalarFrom($vmData['os_name']   ?? $vmData['os']   ?? $vmData['template'] ?? null, ['name', 'title', 'label', 'slug']),
            'hostname'              => $this->scalarFrom($vmData['hostname']  ?? null),
            'ipv4'                  => $this->extractIpv4($vmData),

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
            $raw = $data[$k];
            // Se vier como objeto aninhado ({value: X, unit: "MB"} ou similar)
            // tentamos pegar a subchave numérica mais comum.
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

    /**
     * Converte qualquer valor em string de forma segura. A Hostinger
     * mistura string plain com objeto aninhado dependendo do campo
     * (ex.: `plan` às vezes vem "KVM 4", outras vezes {name: "KVM 4",
     * id: 123}), o que quebrava nosso cast `(string) $v` com o erro
     * "Array to string conversion" em PHP 8+.
     *
     * - Se for escalar, devolve cast direto.
     * - Se for array, tenta subchaves comuns (name, label, value, id)
     *   na ordem — essas são as convenções mais frequentes em APIs REST.
     * - Caso contrário (null ou array sem subchave scalar), devolve ''.
     */
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

    /**
     * Extrai um IPv4 da resposta da VM. A Hostinger pode devolver:
     *   - `ipv4` como string "198.51.100.10"
     *   - `ip` string
     *   - `ipv4` como array de objetos [{address: "..."}, ...]
     *   - `addresses` / `ips` como array
     *
     * Cobrimos os formatos conhecidos e caímos em string vazia se nada
     * casar (assim a tabela imprime "—" em vez de estourar).
     */
    private function extractIpv4(array $vm): string
    {
        foreach (['ipv4', 'ip', 'primary_ipv4', 'ip_address'] as $k) {
            if (!isset($vm[$k])) continue;
            $v = $vm[$k];
            if (is_string($v) && $v !== '') return $v;
            if (is_array($v)) {
                // Pode ser lista de IPs ou lista de {address/ip/ipv4}
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
        // Hostinger às vezes usa chaves plurais
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
