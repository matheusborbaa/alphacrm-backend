<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\Request;
use Carbon\Carbon;

/**
 * @group Relatórios de Marketing
 *
 * Indicadores de performance por origem e campanha.
 * Usado para análise de ROI e eficiência de marketing.
 */
class MarketingReportController extends Controller
{
    /**
     * Relatório de marketing
     *
     * Retorna métricas agrupadas por origem e campanha:
     * - Total de leads
     * - Leads atendidos
     * - Leads convertidos (vendas)
     *
     * @queryParam start_date date Data inicial (YYYY-MM-DD). Example: 2026-01-01
     * @queryParam end_date date Data final (YYYY-MM-DD). Example: 2026-01-31
     * @queryParam source string Filtrar por origem do lead. Example: ManyChat
     * @queryParam campaign string Filtrar por campanha. Example: Lançamento Alpha
     *
     * @response 200 [
     *   {
     *     "source": "ManyChat",
     *     "campaign": "Lançamento Alpha",
     *     "leads_total": 230,
     *     "leads_attended": 180,
     *     "sales": 4,
     *     "conversion_rate": 1.74
     *   }
     * ]
     */
    public function index(Request $request)
    {
        // 📅 Período
        $start = $request->filled('start_date')
            ? Carbon::parse($request->start_date)->startOfDay()
            : now()->startOfMonth();

        $end = $request->filled('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : now()->endOfMonth();

        $query = Lead::whereBetween('created_at', [$start, $end]);

        // 🎯 Filtros
        if ($request->filled('source')) {
            $query->whereHas('source', function ($q) use ($request) {
                $q->where('name', $request->source);
            });
        }

        if ($request->filled('campaign')) {
            $query->where('campaign', $request->campaign);
        }

        $leads = $query->get();

        $grouped = $leads->groupBy(function ($lead) {
            return ($lead->source->name ?? 'Indefinido') . '|' . ($lead->campaign ?? 'Sem campanha');
        });

        $result = $grouped->map(function ($items, $key) {
            [$source, $campaign] = explode('|', $key);

            $total = $items->count();
            $attended = $items->where('sla_status', 'met')->count();
            $sales = $items->whereHas('status', function ($q) {
                $q->where('name', 'Vendido');
            })->count();

            return [
                'source' => $source,
                'campaign' => $campaign,
                'leads_total' => $total,
                'leads_attended' => $attended,
                'sales' => $sales,
                'conversion_rate' => $total > 0
                    ? round(($sales / $total) * 100, 2)
                    : 0
            ];
        })->values();

        return response()->json($result);
    }
}
