<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>{{ $title ?? 'Relatório' }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; margin: 24px; }
        h1 { font-size: 18px; margin: 0 0 4px; color: #111827; }
        h2 { font-size: 14px; margin: 18px 0 6px; color: #374151; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
        .muted { color: #6b7280; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #e5e7eb; padding: 6px 8px; text-align: left; }
        th { background: #f5f6f8; font-weight: 600; }
        .right { text-align: right; }
        .kpi { display: inline-block; padding: 8px 12px; margin: 4px 6px 4px 0; background: #f5f6f8; border-radius: 6px; min-width: 140px; }
        .kpi .val { font-size: 18px; font-weight: 600; color: #111827; }
        .kpi .lbl { font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: .03em; }
        .header { border-bottom: 2px solid #111827; padding-bottom: 10px; margin-bottom: 14px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $title ?? 'Relatório' }}</h1>
        <div class="muted">
            Período: {{ $data['period']['start'] ?? '' }} a {{ $data['period']['end'] ?? '' }} &nbsp;·&nbsp;
            Gerado em {{ now()->format('d/m/Y H:i') }} &nbsp;·&nbsp; Alpha Domus Imobiliária
        </div>
    </div>

    @yield('content')
</body>
</html>
