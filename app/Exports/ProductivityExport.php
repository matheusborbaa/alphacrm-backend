<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ProductivityExport implements FromArray, WithHeadings, WithTitle
{
    public function __construct(private array $payload) {}

    public function title(): string { return 'Produtividade'; }

    public function headings(): array
    {
        return ['Métrica', 'Valor'];
    }

    public function array(): array
    {
        $rows = [];
        $appt = $this->payload['appointments'] ?? [];
        $sla  = $this->payload['sla'] ?? [];

        $rows[] = ['Appointments totais',           $appt['total'] ?? 0];
        $rows[] = ['Appointments concluídos',       $appt['completed'] ?? 0];
        $rows[] = ['Taxa de conclusão (%)',         $appt['completion_rate'] ?? 0];
        $rows[] = ['SLA cumpridos',                 $sla['met'] ?? 0];
        $rows[] = ['SLA vencidos',                  $sla['expired'] ?? 0];
        $rows[] = ['Leads sem interação (>5 dias)', $this->payload['stale_leads'] ?? 0];
        $rows[] = ['Tempo médio de resposta (min)', $this->payload['avg_response_min'] ?? '—'];

        $rows[] = ['', ''];
        $rows[] = ['Appointments por tipo', ''];
        foreach (($appt['by_type'] ?? []) as $t) {
            $rows[] = [$t['type'] ?? '—', ($t['total'] ?? 0) . " (concluídos: " . ($t['completed'] ?? 0) . ")"];
        }

        return $rows;
    }
}
