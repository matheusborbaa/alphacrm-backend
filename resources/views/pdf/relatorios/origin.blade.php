@extends('pdf.relatorios._layout', ['title' => 'Relatório — Origem e Campanha'])

@section('content')
    <div class="kpi"><div class="lbl">Total de leads</div><div class="val">{{ $data['total'] ?? 0 }}</div></div>

    @foreach (['by_source' => 'Por Origem', 'by_channel' => 'Por Canal', 'by_campaign' => 'Por Campanha'] as $key => $label)
        <h2>{{ $label }}</h2>
        <table>
            <thead><tr><th>Nome</th><th class="right">Total</th><th class="right">Vendidos</th><th class="right">Conversão</th></tr></thead>
            <tbody>
            @foreach (($data[$key] ?? []) as $item)
                <tr>
                    <td>{{ $item['name'] }}</td>
                    <td class="right">{{ $item['total'] }}</td>
                    <td class="right">{{ $item['sold'] }}</td>
                    <td class="right">{{ $item['conversion_rate'] }}%</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endforeach

    <h2>Top Cidades de Interesse</h2>
    <table>
        <thead><tr><th>Cidade</th><th class="right">Leads</th></tr></thead>
        <tbody>
        @foreach (($data['by_city'] ?? []) as $item)
            <tr>
                <td>{{ $item['name'] }}</td>
                <td class="right">{{ $item['total'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
