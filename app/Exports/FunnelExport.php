<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class FunnelExport implements FromArray, WithHeadings, WithTitle
{
    public function __construct(private array $payload) {}

    public function title(): string { return 'Funil de Conversão'; }

    public function headings(): array
    {
        return ['Status', 'Ordem', 'Qtd de leads'];
    }

    public function array(): array
    {
        $rows = [];

        foreach (($this->payload['by_status'] ?? []) as $s) {
            $rows[] = [$s['name'], $s['order'], $s['count']];
        }

        $rows[] = ['', '', ''];
        $rows[] = ['Total de leads', '', $this->payload['summary']['total_leads'] ?? 0];
        $rows[] = ['Vendidos', '', $this->payload['summary']['leads_sold'] ?? 0];
        $rows[] = ['Perdidos', '', $this->payload['summary']['leads_lost'] ?? 0];
        $rows[] = ['Ativos', '', $this->payload['summary']['leads_active'] ?? 0];
        $rows[] = ['Taxa de conversão (%)', '', $this->payload['summary']['conversion_rate'] ?? 0];

        return $rows;
    }
}
