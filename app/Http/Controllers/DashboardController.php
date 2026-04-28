<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\LeadStatus;
use Carbon\Carbon;

class DashboardController extends Controller
{

    public function index(Request $request)
    {
        return response()->json([
            'leads_today' => Lead::whereDate('created_at', today())->count(),

            'pending_contact' => Lead::where('sla_status', 'pending')->count(),

            'sla_expired' => Lead::where('sla_status', 'expired')->count(),

            'sla_met' => Lead::where('sla_status', 'met')->count(),

            'by_corretor' => User::where('role', 'corretor')
                ->withCount([
                    'leads as leads_total',
                    'leads as sla_met' => function ($q) {
                        $q->where('sla_status', 'met');
                    },
                    'leads as sla_expired' => function ($q) {
                        $q->where('sla_status', 'expired');
                    }
                ])
                ->get(['id', 'name'])
        ]);
    }

    public function funnel(Request $request)
{

    [$start, $end] = \App\Support\DashboardPeriod::resolve($request);

    $funnel = LeadStatus::withCount([

        'leads as total' => function ($q) use ($start, $end) {
            $q->whereBetween('created_at', [$start, $end]);
        },
    ])
    ->orderBy('order')
    ->get()
    ->map(fn ($s) => [
        'id'        => (int) $s->id,
        'name'      => $s->name,
        'color_hex' => $s->color_hex,
        'order'     => (int) $s->order,
        'total'     => (int) ($s->total ?? 0),
    ]);

    return response()->json($funnel);
}

public function resumo(Request $request)
{

    [$start, $end] = \App\Support\DashboardPeriod::resolve($request);

    $STATUS_VENDIDO = 6;
    $STATUS_DESCARTADO = 99;

    $base = Lead::whereBetween('created_at', [$start, $end]);

    $total = (clone $base)->count();

    $descartados = (clone $base)
        ->where('status_id', $STATUS_DESCARTADO)
        ->count();

    $vendas = (clone $base)
        ->where('status_id', $STATUS_VENDIDO)
        ->count();

    $ativos = (clone $base)
        ->whereNotIn('status_id', [$STATUS_DESCARTADO])
        ->count();

  $vgv = Lead::where('status_id', $STATUS_VENDIDO)
    ->whereBetween('leads.updated_at', [$start, $end])
    ->join('empreendimentos', 'leads.empreendimento_id', '=', 'empreendimentos.id')
    ->sum('empreendimentos.average_sale_value');

    $conversao = $total > 0 ? ($vendas / $total) * 100 : 0;

    $aproveitamento = $total > 0
        ? (($total - $descartados) / $total) * 100
        : 0;

    $tempoMedio = Lead::where('status_id', $STATUS_VENDIDO)
        ->whereBetween('created_at', [$start, $end])
        ->selectRaw('AVG(TIMESTAMPDIFF(DAY, created_at, updated_at)) as media')
        ->value('media');

    return response()->json([
        'total' => $total,
        'ativos' => $ativos,
        'descartados' => $descartados,
        'vendas' => $vendas,
        'vgv' => $vgv ?? 0,
        'conversao' => round($conversao, 2),
        'aproveitamento' => round($aproveitamento, 2),
        'tempo_medio' => round($tempoMedio ?? 0),
    ]);
}

}
