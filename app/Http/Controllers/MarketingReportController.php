<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\Request;
use Carbon\Carbon;

class MarketingReportController extends Controller
{

    public function index(Request $request)
    {

        $start = $request->filled('start_date')
            ? Carbon::parse($request->start_date)->startOfDay()
            : now()->startOfMonth();

        $end = $request->filled('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : now()->endOfMonth();

        $query = Lead::whereBetween('created_at', [$start, $end]);

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
