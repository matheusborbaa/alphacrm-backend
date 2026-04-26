<?php

namespace App\Http\Controllers;

use App\Exports\FunnelExport;
use App\Exports\ProductivityExport;
use App\Exports\OriginExport;
use App\Exports\RankingExport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;

class RelatoriosExportController extends Controller
{
    public function export(Request $request, string $tipo, string $formato)
    {

        $relatorios = app(RelatoriosController::class);

        $payload = match ($tipo) {
            'funnel'       => $relatorios->funnel($request)->getData(true),
            'productivity' => $relatorios->productivity($request)->getData(true),
            'origin'       => $relatorios->originCampaign($request)->getData(true),
            'ranking'      => $relatorios->ranking($request)->getData(true),
            default        => abort(404, 'Tipo inválido'),
        };

        $periodo = ($payload['period']['start'] ?? '') . '_' . ($payload['period']['end'] ?? '');
        $filename = "relatorio-{$tipo}-{$periodo}";

        if ($formato === 'xlsx') {
            $exportClass = match ($tipo) {
                'funnel'       => new FunnelExport($payload),
                'productivity' => new ProductivityExport($payload),
                'origin'       => new OriginExport($payload),
                'ranking'      => new RankingExport($payload),
            };
            return Excel::download($exportClass, "{$filename}.xlsx");
        }

        $view = "pdf.relatorios.{$tipo}";
        $pdf  = Pdf::loadView($view, ['data' => $payload]);
        return $pdf->download("{$filename}.pdf");
    }
}
