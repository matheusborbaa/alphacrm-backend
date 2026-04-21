@extends('pdf.relatorios._layout', ['title' => 'Relatório — Ranking de Corretores'])

@section('content')
    <h2>Ranking do período</h2>
    <table>
        <thead>
            <tr>
                <th>Pos</th>
                <th>Corretor</th>
                <th class="right">Leads</th>
                <th class="right">Vendas</th>
                <th class="right">Atd.</th>
                <th class="right">Score</th>
                <th class="right">% Meta Leads</th>
                <th class="right">% Meta Atd</th>
                <th class="right">% Meta Vendas</th>
            </tr>
        </thead>
        <tbody>
        @foreach (($data['ranking'] ?? []) as $i => $r)
            <tr>
                <td>{{ $i + 1 }}º</td>
                <td>{{ $r['name'] }}</td>
                <td class="right">{{ $r['leads_total'] }}</td>
                <td class="right">{{ $r['leads_sold'] }}</td>
                <td class="right">{{ $r['appointments'] }}</td>
                <td class="right">{{ $r['score'] }}</td>
                <td class="right">{{ $r['meta']['pct_leads'] ?? '—' }}{{ isset($r['meta']['pct_leads']) ? '%' : '' }}</td>
                <td class="right">{{ $r['meta']['pct_atd'] ?? '—' }}{{ isset($r['meta']['pct_atd']) ? '%' : '' }}</td>
                <td class="right">{{ $r['meta']['pct_vendas'] ?? '—' }}{{ isset($r['meta']['pct_vendas']) ? '%' : '' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
