<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class RankingExport implements FromArray, WithHeadings, WithTitle
{
    public function __construct(private array $payload) {}

    public function title(): string { return 'Ranking'; }

    public function headings(): array
    {
        return ['Pos', 'Corretor', 'Leads', 'Vendas', 'Atendimentos', 'Score', '% Meta Leads', '% Meta Atd', '% Meta Vendas'];
    }

    public function array(): array
    {
        $rows = [];

        foreach (($this->payload['ranking'] ?? []) as $i => $r) {
            $meta = $r['meta'] ?? null;
            $rows[] = [
                $i + 1,
                $r['name']         ?? '—',
                $r['leads_total']  ?? 0,
                $r['leads_sold']   ?? 0,
                $r['appointments'] ?? 0,
                $r['score']        ?? 0,
                $meta['pct_leads']  ?? '—',
                $meta['pct_atd']    ?? '—',
                $meta['pct_vendas'] ?? '—',
            ];
        }

        return $rows;
    }
}
