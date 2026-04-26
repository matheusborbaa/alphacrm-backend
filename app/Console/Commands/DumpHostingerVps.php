<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class DumpHostingerVps extends Command
{
    protected $signature = 'hostinger:dump-vps
                            {id? : ID do VPS (opcional, default=HOSTINGER_VPS_ID)}
                            {--minutes=15 : Janela de tempo das métricas em minutos}';

    protected $description = 'Dumpa o JSON cru de /virtual-machines/{id} e /metrics — pra debugar mismatch de campos.';

    public function handle(): int
    {
        $apiBase = rtrim((string) config('services.hostinger.api_base', 'https://developers.hostinger.com'), '/');
        $apiKey  = config('services.hostinger.api_key');
        $vpsId   = $this->argument('id') ?? config('services.hostinger.vps_id');
        $minutes = (int) $this->option('minutes');

        if (empty($apiKey)) {
            $this->error('HOSTINGER_API_KEY não está definida no .env.');
            return self::FAILURE;
        }
        if (empty($vpsId)) {
            $this->error('Nenhum VPS ID passado e HOSTINGER_VPS_ID está vazio no .env.');
            $this->line('Use: php artisan hostinger:dump-vps 12345');
            return self::FAILURE;
        }

        $this->info("=== GET /api/vps/v1/virtual-machines/{$vpsId} ===");
        $vm = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(10)
            ->get($apiBase . "/api/vps/v1/virtual-machines/{$vpsId}");
        $this->line("HTTP {$vm->status()}");
        $this->line(json_encode($vm->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->newLine();

        $this->info("=== GET /api/vps/v1/virtual-machines/{$vpsId}/metrics (últimos {$minutes}min) ===");
        $metrics = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(10)
            ->get($apiBase . "/api/vps/v1/virtual-machines/{$vpsId}/metrics", [
                'date_from' => now()->subMinutes($minutes)->toIso8601String(),
                'date_to'   => now()->toIso8601String(),
            ]);
        $this->line("HTTP {$metrics->status()}");
        $body = $metrics->json();
        $this->line(json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->info('=== Chaves de métricas detectadas ===');
        $data = is_array($body) ? ($body['data'] ?? $body) : [];
        if (is_array($data) && $data) {
            foreach ($data as $metric => $block) {
                if (is_array($block) && isset($block['unit'], $block['usage']) && is_array($block['usage'])) {

                    $pts  = count($block['usage']);
                    $unit = (string) $block['unit'];
                    $last = $pts > 0 ? end($block['usage']) : null;
                    $this->line(sprintf(
                        '  • %-18s unit=%-14s pontos=%-4d último=%s',
                        $metric,
                        $unit,
                        $pts,
                        is_numeric($last) ? $last : 'n/d'
                    ));
                } else {
                    $type = gettype($block);
                    $size = is_array($block) ? count($block) : '-';
                    $this->line("  • {$metric}  (tipo={$type}, itens={$size})  — shape desconhecido");
                }
            }
        } else {
            $this->warn('  (nenhuma chave no nível superior da resposta de métricas)');
        }

        return self::SUCCESS;
    }
}
