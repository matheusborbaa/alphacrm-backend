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
    // 15s: a API do provedor tem latência alta em horário de pico e a gente
    // já estava batendo em timeout com 8s. Como cacheamos 60s, o custo extra
    // só afeta o primeiro request da janela — não vira problema pra UX.
    private const HTTP_TIMEOUT = 15;
    // Janelas de consulta das métricas, em minutos. A Hostinger amostra
    // a cada ~5min, então 10min é curto demais — tinha alinhamento ruim
    // que fazia a série voltar vazia ("depois de limpar cache dá 0%"). A
    // gente tenta da mais curta pra mais longa; assim que achar pontos,
    // usa. Pior caso (VPS recém-criada) olha 24h pra não deixar a aba
    // Sistema mostrando 0.0% indefinidamente.
    private const METRICS_WINDOW_CASCADE = [60, 360, 1440]; // 1h → 6h → 24h

    private string $apiBase;
    private ?string $apiKey;
    private ?string $vpsId;

    // --- Quota fictícia de disco reservada pro CRM ---
    // O servidor físico é compartilhado com outros sistemas. A aba Sistema
    // e os alertas usam essa quota como denominador ("5,6 GB de 30 GB") em
    // vez do disco total do VPS. O numerador continua sendo o uso real que
    // a Hostinger devolve — mudar só o total dá referência mais honesta
    // pro admin sem precisar auditar o disco de quem não é do CRM.
    private int $appDiskQuotaBytes;        // denominador (quota em bytes)

    public function __construct()
    {
        $this->apiBase = rtrim((string) config('services.hostinger.api_base', 'https://developers.hostinger.com'), '/');
        $this->apiKey  = config('services.hostinger.api_key');
        $this->vpsId   = config('services.hostinger.vps_id');

        // Quota em GB → bytes. Piso de 1 GB pra evitar divisão por 0
        // e mis-config absurda.
        $quotaGb = max(1, (int) config('services.alphacrm_disk.quota_gb', 30));
        $this->appDiskQuotaBytes = $quotaGb * 1024 * 1024 * 1024;
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

    /**
     * Força um refresh ignorando cache. Usado quando o admin clica "Atualizar".
     * Invalida TANTO o cache de status quanto o de backup — senão o botão
     * "Atualizar" mostraria info fresca de CPU/RAM/disco mas data de backup
     * travada (o cache de backup é mais longo, 15min).
     */
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
            // Mesmo caso do fetchStatusNow(): mensagem genérica pra UI/CLI,
            // detalhes só no log. O comando artisan é admin-only, mas o
            // texto ainda pode aparecer num print/bug report.
            return $this->errorResponse('exception', $this->humanizeException($e));
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
                return $this->errorResponse('upstream_error', 'Não foi possível obter o status do servidor no momento.');
            }

            // Cascata de janelas: tenta 1h, 6h, 24h. Assim que a série
            // principal (cpu/ram/disco) tiver pelo menos 1 ponto útil,
            // para e usa essa resposta. Se todas as janelas vierem vazias,
            // usa o último resultado mesmo (o builder cai nos fallbacks
            // de vmData e rende 0 se nem isso existir).
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
                // Mantém a última resposta mesmo vazia — melhor do que []
                // se todas forem vazias (pode ter a shape das chaves e
                // o builder ainda conseguir pegar unit/uptime).
                $metrics = $resp ?: $metrics;
            }

            return $this->buildPayload($vm, $metrics);

        } catch (\Throwable $e) {
            // Log: detalhes técnicos (URL, ID, stack) — pra dev/ops debugar.
            // User-facing: mensagem genérica sem nenhum detalhe de infra.
            // NUNCA concatenar $e->getMessage() na resposta porque o cURL
            // enfia a URL com VPS_ID no texto do erro, vazando infra pra UI.
            Log::warning('HostingerService.fetchStatusNow falhou', [
                'error' => $e->getMessage(),
                'vps_id' => $this->vpsId,
            ]);
            return $this->errorResponse('exception', $this->humanizeException($e));
        }
    }

    /**
     * Uma resposta de /metrics é "útil" se pelo menos uma das séries
     * principais (cpu, ram, disk) trouxe algum ponto numérico. A resposta
     * pode voltar com as chaves todas presentes mas `usage: {}` vazio —
     * isso aqui detecta esse caso pra gente poder ampliar a janela.
     */
    private function metricsHaveUsefulData($resp): bool
    {
        if (!is_array($resp) || empty($resp)) return false;
        $series = $resp['data'] ?? $resp;
        foreach (['cpu_usage', 'ram_usage', 'disk_space'] as $key) {
            $block = $series[$key] ?? null;
            if (!is_array($block)) continue;
            $usage = $block['usage'] ?? $block;
            if (is_array($usage) && count($usage) > 0) {
                // Achou pelo menos 1 ponto numérico em qualquer uma → útil.
                foreach ($usage as $v) {
                    if (is_numeric($v)) return true;
                }
            }
        }
        return false;
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

        // Fallback de uptime: a doc oficial da Hostinger expõe
        //   "uptime": {"unit": "milliseconds", "usage": {ts: val, ...}}
        // na resposta do /metrics. Quando a VM info não devolve uptime
        // (caso comum em alguns planos), a gente pega o último ponto
        // dessa série e converte pra segundos. Sem isso o card de
        // "Uptime" renderiza "—" permanentemente.
        if ($uptime <= 0) {
            $seriesTmp = $metrics['data'] ?? $metrics;
            $uptimeBlock = $seriesTmp['uptime'] ?? null;
            if (is_array($uptimeBlock)) {
                $n = $this->normalizeMetric($uptimeBlock);
                if (!empty($n['points'])) {
                    $lastVal = (float) end($n['points']);
                    $unit = strtolower($n['unit'] ?: 'milliseconds');
                    // Converte pra segundos conforme a unidade declarada.
                    $uptime = match ($unit) {
                        'milliseconds', 'ms' => (int) round($lastVal / 1000),
                        'microseconds', 'us' => (int) round($lastVal / 1_000_000),
                        'minutes', 'm'       => (int) round($lastVal * 60),
                        'hours', 'h'         => (int) round($lastVal * 3600),
                        default              => (int) $lastVal, // 'seconds', 's' ou desconhecido
                    };
                }
            }
        }

        // RAM/disk totais vêm na info da VM (em MB na maioria das APIs
        // Hostinger — normalizamos pra bytes). Se aparecer no formato
        // novo (bytes) também funciona porque detectamos o tamanho.
        // Obs.: API às vezes devolve como objeto em vez de escalar —
        // extractBytes já tem guarda contra is_numeric().
        //
        // IMPORTANTE sobre a unidade do disco: confirmado via
        // `hostinger:list-vps` que a Hostinger devolve `disk: 51200` pra
        // um plano de 50 GB — ou seja, está em MB, não em GB. Quem vinha
        // com 'GB' aqui gerava "51200 GB" na UI (= 51 TB). Agora tratamos
        // como MB; a heurística de `extractBytes` ainda detecta valores
        // grandes como bytes puros caso a API mude no futuro.
        $ramTotal  = $this->extractBytes($vmData, ['memory_bytes', 'memory', 'ram_bytes', 'ram'], 'MB');
        $diskTotal = $this->extractBytes($vmData, ['disk_bytes', 'disk_space', 'disk'], 'MB');

        // Shape REAL da resposta /metrics confirmado na doc oficial:
        //   {
        //     "cpu_usage":       {"unit": "%",            "usage": {ts: val, ...}},
        //     "ram_usage":       {"unit": "bytes",        "usage": {ts: val, ...}},
        //     "disk_space":      {"unit": "bytes",        "usage": {ts: val, ...}},
        //     "incoming_traffic":{"unit": "bytes",        "usage": {ts: val, ...}},
        //     "outgoing_traffic":{"unit": "bytes",        "usage": {ts: val, ...}},
        //     "uptime":          {"unit": "milliseconds", "usage": {ts: val, ...}}
        //   }
        // NOTA: A resposta vem flat no topo — sem wrapper "data". Mantemos
        // o `?? $metrics` só pra robustez caso a API mude no futuro.
        // NOTA 2: A chave de disco é `disk_space`, NÃO `disk_usage`. Era
        // essa a razão do "—" na UI.
        $series = $metrics['data'] ?? $metrics;

        // CPU: unit "%", o valor já é percent — não precisa conversão.
        $cpuAvg = $this->avgFromMetric($series['cpu_usage'] ?? []);
        // Fallback: algumas respostas antigas da Hostinger expõem cpu direto
        // no /virtual-machines/{id} em vez de série. Tentamos caso a série
        // venha vazia (VPS recém-criada, sem histórico ainda).
        if ($cpuAvg <= 0) {
            foreach (['cpu_usage', 'cpu_percent', 'cpu_load'] as $k) {
                if (isset($vmData[$k]) && is_numeric($vmData[$k])) {
                    $cpuAvg = (float) $vmData[$k];
                    break;
                }
            }
        }

        // RAM usada: unit "bytes" na série. Pegamos o ponto mais recente.
        $ramUsed = $this->lastBytesFromMetric($series['ram_usage'] ?? []);
        if ($ramUsed <= 0) {
            // Fallback: campo absoluto no objeto da VM (alguns planos expõem).
            $ramUsed = $this->extractBytes(
                $vmData,
                ['ram_used_bytes', 'ram_used', 'memory_used_bytes', 'memory_used', 'used_memory'],
                'MB'
            );
        }
        // Percent direto de RAM no VM info (raro mas coberto) — último fallback.
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

        // Disco usado: chave oficial `disk_space` (em bytes). Mantemos
        // alternativas pra robustez caso a API expanda.
        $diskUsed = $this->lastBytesFromMetric(
            $series['disk_space'] ?? $series['disk_usage'] ?? $series['disk_used'] ?? $series['disk'] ?? []
        );
        if ($diskUsed <= 0) {
            // Fallback: campos absolutos no próprio objeto da VM.
            $diskUsed = $this->extractBytes(
                $vmData,
                ['disk_used_bytes', 'disk_used', 'used_disk', 'disk_usage'],
                'MB'
            );
        }
        // Percent direto (se o provedor expuser) — última camada pra derivar.
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

        // Tráfego: chaves oficiais são `incoming_traffic` / `outgoing_traffic`.
        // Os valores são bytes por ponto amostral — usar avg dá ordem de
        // grandeza razoável (é mais pra indicador de "rede viva" do que
        // medida precisa). Mantemos network_in/out como fallback de nome.
        $netIn  = $this->avgFromMetric($series['incoming_traffic'] ?? $series['network_in']  ?? $series['net_in']  ?? []);
        $netOut = $this->avgFromMetric($series['outgoing_traffic'] ?? $series['network_out'] ?? $series['net_out'] ?? []);

        // -------- Disco: mantém o USED da Hostinger, troca só o TOTAL --------
        // O VPS é compartilhado com outro sistema. Em vez de expor o disco
        // total bruto do provedor (ex.: 50 GB), exibimos o consumo real do
        // VPS (que já inclui tudo — Laravel, uploads, logs, outros sistemas)
        // dividido por uma quota fictícia reservada pro CRM (default 30 GB).
        //
        // Tentativa anterior media só `du base_path()` pro USED, mas isso
        // não refletia uploads fora da pasta (storage em outro disk,
        // frontend, backups locais) e subia pra UI um número artificial.
        // Agora só trocamos o denominador — o numerador permanece o "disk
        // realmente ocupado no VPS" que a Hostinger já devolve.
        $appDiskTotal = $this->appDiskQuotaBytes;
        // Se o used exceder a quota (quota estourada) o percent trava em
        // 100% — visualmente avisa o admin sem quebrar a barra.
        $appDiskPct   = $appDiskTotal > 0
            ? round(min(100.0, $diskUsed / $appDiskTotal * 100), 1)
            : 0.0;

        // -------- Último backup (API /backups da Hostinger) --------
        // Cache interno de 15min — é seguro embutir no payload de status
        // sem virar gargalo. Se der qualquer erro, deixa null pra UI
        // renderizar "—" em vez de quebrar o card.
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
            // Prioridade idêntica à do disco: percent direto do provedor > calculado.
            'ram_percent'           => $ramPercentRaw !== null
                ? round($ramPercentRaw, 1)
                : ($ramTotal > 0 ? round($ramUsed / $ramTotal * 100, 1) : 0.0),

            // Disco: USED é o real do VPS (igual ao que a Hostinger devolve);
            // TOTAL é a quota fictícia de ALPHACRM_DISK_QUOTA_GB (.env,
            // default 30 GB). O VPS é compartilhado com outros sistemas,
            // então em vez do total bruto exibimos "usado / quota reservada".
            'disk_total_bytes'      => $appDiskTotal,
            'disk_used_bytes'       => $diskUsed,
            'disk_percent'          => $appDiskPct,

            'net_in_bytes_per_sec'  => round($netIn,  0),
            'net_out_bytes_per_sec' => round($netOut, 0),

            // Último backup disponível no VPS — pode ser null se a API
            // de backups falhar ou se a VPS ainda não tem snapshot.
            // Shape do backup: ver getLatestBackup().
            'latest_backup'         => $latestBackup,
        ];
    }

    /**
     * Busca o último backup disponível do VPS. Usado pela aba Sistema pra
     * mostrar "Último backup: DD/MM/AAAA HH:mm" — útil pra admin saber se
     * o snapshot automático da Hostinger está fresco antes de tentar uma
     * restauração.
     *
     * A API devolve uma lista paginada (15 por página); a gente só precisa
     * do mais recente. Como backups são criados ~1x/dia, cacheamos por
     * 15min — evita bater na API Hostinger a cada polling de 60s.
     *
     * Retorno:
     *   [
     *     'ok' => true,
     *     'backup' => [
     *       'id' => int,
     *       'created_at' => ISO-8601 string,
     *       'size_bytes' => int,
     *       'location' => string,                  // ex.: "nl-srv-nodebackups"
     *       'restore_time_seconds' => int,         // tempo estimado de restore
     *     ] | null,                                 // null se não houver backups
     *   ]
     * Em erro: ['ok' => false, 'reason' => ..., 'error' => ...]. A UI não
     * deve derrubar o resto do card por causa disso — é informacional.
     */
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
            // page=1 vem ordenada do mais recente pro mais antigo (confirmado
            // na doc: created_at desc). Pegamos só a primeira entrada.
            $resp = $this->request('GET', "/api/vps/v1/virtual-machines/{$this->vpsId}/backups", [
                'page' => 1,
            ]);

            if (!is_array($resp)) {
                return $this->errorResponse('upstream_error', 'Não foi possível obter o histórico de backups.');
            }

            $list = $resp['data'] ?? $resp;
            if (!is_array($list) || empty($list)) {
                // Sem backups é estado válido — não é erro. Pode acontecer
                // em VPS nova. Cacheamos pra não ficar batendo na API.
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
            Cache::put($cacheKey, $result, 900); // 15min
            return $result;

        } catch (\Throwable $e) {
            Log::warning('HostingerService.getLatestBackup falhou', [
                'error'  => $e->getMessage(),
                'vps_id' => $this->vpsId,
            ]);
            return $this->errorResponse('exception', $this->humanizeException($e));
        }
    }

    /** Força refresh do cache do último backup — usado no botão "Atualizar". */
    public function refreshLatestBackup(): array
    {
        Cache::forget(self::CACHE_PREFIX . 'latest_backup:' . $this->vpsId);
        return $this->getLatestBackup();
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

    /**
     * Normaliza um bloco de métrica da Hostinger pro formato canônico
     * que nossos helpers consomem.
     *
     * Formato canônico devolvido:
     *   ['unit' => '%'|'bytes'|'milliseconds'|..., 'points' => [float, float, ...]]
     * onde `points` está em ordem cronológica.
     *
     * Aceita dois shapes de entrada:
     *   A) Oficial atual (confirmado na doc):
     *      {"unit": "bytes", "usage": {"1742269632": 554176512, ...}}
     *   B) Legado / outras APIs parecidas:
     *      [[ts, value], ...] ou [value, value, ...]
     *
     * Qualquer outra coisa devolve ['unit' => '', 'points' => []].
     */
    private function normalizeMetric($block): array
    {
        if (!is_array($block) || empty($block)) {
            return ['unit' => '', 'points' => []];
        }

        // Shape A: {unit, usage: {ts: val, ...}} — oficial.
        if (isset($block['usage']) && is_array($block['usage'])) {
            $unit = is_string($block['unit'] ?? null) ? (string) $block['unit'] : '';
            // Ordena por timestamp (chave) pra lastBytes pegar o mais recente.
            $usage = $block['usage'];
            ksort($usage, SORT_NUMERIC);
            $points = [];
            foreach ($usage as $v) {
                if (is_numeric($v)) $points[] = (float) $v;
            }
            return ['unit' => $unit, 'points' => $points];
        }

        // Shape B: lista [[ts, val], ...] ou [val, val, ...]
        $points = [];
        foreach ($block as $pt) {
            $v = is_array($pt) ? ($pt[1] ?? $pt['value'] ?? null) : $pt;
            if (is_numeric($v)) $points[] = (float) $v;
        }
        return ['unit' => '', 'points' => $points];
    }

    /** Média do bloco de métrica — respeita o shape oficial da Hostinger. */
    private function avgFromMetric($block): float
    {
        $n = $this->normalizeMetric($block);
        if (empty($n['points'])) return 0.0;
        return array_sum($n['points']) / count($n['points']);
    }

    /**
     * Último valor do bloco de métrica, convertido pra bytes conforme a
     * unit declarada. Se a unit for ausente, usa $defaultUnit como palpite.
     */
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
            // Unit desconhecida: cai na heurística "grande demais pra não ser bytes".
            default                  => $v > 10_000_000 ? (int) $v : (int) ($v * 1024 * 1024),
        };
    }

    /** Média numérica de uma série [[ts, value], ...] — 0 se vazia. Legado. */
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
     * Converte qualquer exception (cURL, timeout, conexão recusada,
     * resolução DNS falha, etc.) em uma mensagem user-facing genérica
     * que NÃO vaza URL, ID de VPS, IP, nada de infra.
     *
     * A gente nunca concatena $e->getMessage() na resposta porque o
     * cURL formata o texto do erro incluindo a URL completa — por ex.:
     *   "Operation timed out ... for https://developers.hostinger.com/api/vps/v1/virtual-machines/1231138"
     * Isso estava aparecendo no card de "Sistema" e expondo o provedor +
     * ID da VM pra qualquer admin com acesso ao CRM.
     *
     * Classificamos por palavra-chave no motivo (timeout / conexão /
     * DNS) só pra dar uma dica útil ao admin — se precisar debugar de
     * verdade, os detalhes completos estão no log (storage/logs).
     */
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
