<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class OriginExport implements FromArray, WithHeadings, WithTitle
{
    public function __construct(private array $payload) {}

    public function title(): string { return 'Origem e Campanha'; }

    public function headings(): array
    {
        return ['Seção', 'Nome', 'Total', 'Vendidos', 'Conversão %'];
    }

    public function array(): array
    {
        $rows = [];

        foreach (['by_source' => 'Origem', 'by_channel' => 'Canal', 'by_campaign' => 'Campanha'] as $key => $label) {
            foreach (($this->payload[$key] ?? []) as $item) {
                $rows[] = [
                    $label,
                    $item['name']            ?? '—',
                    $item['total']           ?? 0,
                    $item['sold']            ?? 0,
                    $item['conversion_rate'] ?? 0,
                ];
            }
        }

        $rows[] = ['', '', '', '', ''];
        $rows[] = ['Seção', 'Cidade', 'Total', '', ''];
        foreach (($this->payload['by_city'] ?? []) as $item) {
            $rows[] = ['Cidade', $item['name'] ?? '—', $item['total'] ?? 0, '', ''];
        }

        return $rows;
    }
}
