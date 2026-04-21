@extends('pdf.relatorios._layout', ['title' => 'Relatório — Funil de Conversão'])

@section('content')
    <div>
        <div class="kpi"><div class="lbl">Total de leads</div><div class="val">{{ $data['summary']['total_leads'] ?? 0 }}</div></div>
        <div class="kpi"><div class="lbl">Vendidos</div><div class="val">{{ $data['summary']['leads_sold'] ?? 0 }}</div></div>
        <div class="kpi"><div class="lbl">Perdidos</div><div class="val">{{ $data['summary']['leads_lost'] ?? 0 }}</div></div>
        <div class="kpi"><div class="lbl">Ativos</div><div class="val">{{ $data['summary']['leads_active'] ?? 0 }}</div></div>
        <div class="kpi"><div class="lbl">Conversão</div><div class="val">{{ $data['summary']['conversion_rate'] ?? 0 }}%</div></div>
    </div>

    <h2>Distribuição por status</h2>
    <table>
        <thead><tr><th>Status</th><th class="right">Qtd</th></tr></thead>
        <tbody>
        @foreach (($data['by_status'] ?? []) as $s)
            <tr>
                <td>{{ $s['name'] }}</td>
                <td class="right">{{ $s['count'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
