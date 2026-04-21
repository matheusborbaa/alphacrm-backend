@extends('pdf.relatorios._layout', ['title' => 'Relatório — Produtividade'])

@section('content')
    <div>
        <div class="kpi"><div class="lbl">Appointments totais</div><div class="val">{{ $data['appointments']['total'] ?? 0 }}</div></div>
        <div class="kpi"><div class="lbl">Concluídos</div><div class="val">{{ $data['appointments']['completed'] ?? 0 }}</div></div>
        <div class="kpi"><div class="lbl">Taxa de conclusão</div><div class="val">{{ $data['appointments']['completion_rate'] ?? 0 }}%</div></div>
        <div class="kpi"><div class="lbl">SLA cumpridos</div><div class="val">{{ $data['sla']['met'] ?? 0 }}</div></div>
        <div class="kpi"><div class="lbl">SLA vencidos</div><div class="val">{{ $data['sla']['expired'] ?? 0 }}</div></div>
        <div class="kpi"><div class="lbl">Tempo médio resp.</div><div class="val">{{ $data['avg_response_min'] !== null ? $data['avg_response_min'] . ' min' : '—' }}</div></div>
        <div class="kpi"><div class="lbl">Leads sem interação</div><div class="val">{{ $data['stale_leads'] ?? 0 }}</div></div>
    </div>

    <h2>Appointments por tipo</h2>
    <table>
        <thead><tr><th>Tipo</th><th class="right">Total</th><th class="right">Concluídos</th></tr></thead>
        <tbody>
        @foreach (($data['appointments']['by_type'] ?? []) as $t)
            <tr>
                <td>{{ $t['type'] ?? '—' }}</td>
                <td class="right">{{ $t['total'] ?? 0 }}</td>
                <td class="right">{{ $t['completed'] ?? 0 }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
