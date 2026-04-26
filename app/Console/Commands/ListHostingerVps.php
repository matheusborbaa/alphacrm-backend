<?php

namespace App\Console\Commands;

use App\Services\HostingerService;
use Illuminate\Console\Command;

class ListHostingerVps extends Command
{
    protected $signature   = 'hostinger:list-vps {--raw : Imprime o JSON bruto da resposta (útil pra debug de schema)}';
    protected $description = 'Lista as VPSes da conta Hostinger pra descobrir o HOSTINGER_VPS_ID correto.';

    public function handle(HostingerService $hostinger): int
    {
        $res = $hostinger->listVirtualMachines();

        if (!($res['ok'] ?? false)) {
            $this->error('Falha ao listar VPSes: ' . ($res['error'] ?? 'erro desconhecido'));
            if (!empty($res['reason'])) {
                $this->line('  motivo: ' . $res['reason']);
            }
            return self::FAILURE;
        }

        if ($this->option('raw')) {
            $this->line(json_encode($res['raw'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $vms = $res['vms'] ?? [];
        if (empty($vms)) {
            $this->warn('A Hostinger retornou zero VPSes pra essa API key.');
            $this->line('Verifique se a key tem permissão "Read VPS" e está ativa.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($vms as $vm) {
            $rows[] = [
                'ID'       => $vm['id'],
                'Hostname' => $vm['hostname'],
                'Plano'    => $vm['plan'],
                'Estado'   => $vm['state'],
                'IPv4'     => $vm['ipv4'],
                'CPUs'     => $vm['cpus'],
                'RAM'      => $vm['memory'] ? round($vm['memory'] / 1024, 1) . ' GB' : '—',
                'Disco'    => $vm['disk']   ? round($vm['disk']   / 1024, 1) . ' GB' : '—',
            ];
        }

        $this->newLine();
        $this->info(sprintf('%d VPS(es) encontrada(s) na conta Hostinger:', count($rows)));
        $this->newLine();

        $this->table(
            ['ID', 'Hostname', 'Plano', 'Estado', 'IPv4', 'CPUs', 'RAM', 'Disco'],
            $rows
        );

        $this->newLine();
        $this->line('Copie o ID da VPS do CRM e cole em HOSTINGER_VPS_ID no .env.');
        $this->line('Depois: <info>php artisan config:clear</info> (ou reinicie o php-fpm) pra aplicar.');

        return self::SUCCESS;
    }
}
