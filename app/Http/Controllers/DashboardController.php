<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\LeadStatus;
use Carbon\Carbon;


/**
 * @group Dashboard
 *
 * Métricas gerais do CRM.
 * Usado na Home do gestor para acompanhamento de leads e performance.
 */
class DashboardController extends Controller
{
    /**
     * Métricas do dashboard
     *
     * Retorna indicadores principais do CRM:
     * - Leads de hoje
     * - Leads sem contato
     * - SLA expirado
     * - Leads atendidos
     * - Performance dos corretores
     *
     * @response 200 {
     *   "leads_today": 25,
     *   "pending_contact": 8,
     *   "sla_expired": 3,
     *   "sla_met": 14,
     *   "by_corretor": [
     *     {
     *       "id": 2,
     *       "name": "Corretor A",
     *       "leads_total": 12,
     *       "sla_met": 9,
     *       "sla_expired": 1
     *     }
     *   ]
     * }
     */
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

        /**
     * Funil de vendas
     *
     * Retorna os dados do funil de vendas por etapa,
     * com base no status do lead.
     * Usado na Home para exibição do funil visual.
     *
     * @queryParam month string Mês no formato YYYY-MM. Example: 2026-01
     *
     * @response 200 [
     *   {
     *     "id": 1,
     *     "name": "Leads cadastrados",
     *     "total": 230
     *   },
     *   {
     *     "id": 2,
     *     "name": "Em atendimento",
     *     "total": 180
     *   },
     *   {
     *     "id": 3,
     *     "name": "Agendado",
     *     "total": 50
     *   },
     *   {
     *     "id": 4,
     *     "name": "Visitou",
     *     "total": 42
     *   },
     *   {
     *     "id": 5,
     *     "name": "Em negociação",
     *     "total": 18
     *   },
     *   {
     *     "id": 6,
     *     "name": "Vendido",
     *     "total": 4
     *   }
     * ]
     */
    public function funnel(Request $request)
{
    $periodo = $request->get('periodo', 'mensal');

    switch ($periodo) {
        case 'diario':
            $start = now()->startOfDay();
            $end   = now()->endOfDay();
            break;

        case 'semanal':
            $start = now()->startOfWeek();
            $end   = now()->endOfWeek();
            break;

        case 'mensal':
        default:
            $start = now()->startOfMonth();
            $end   = now()->endOfMonth();
            break;
    }

    $funnel = LeadStatus::withCount([
        'leads as total' //=> function ($q) use ($start, $end) {
           //  $q->whereBetween('created_at', [$start, $end]);
        //}
    ])
    ->orderBy('order')
    ->get(['id', 'name']);

    return response()->json($funnel);
}

public function resumo(Request $request)
{
    $periodo = $request->get('periodo', 'mensal');

    switch ($periodo) {
        case 'diario':
            $start = now()->startOfDay();
            $end   = now()->endOfDay();
            break;
        case 'semanal':
            $start = now()->startOfWeek();
            $end   = now()->endOfWeek();
            break;
        default:
            $start = now()->startOfMonth();
            $end   = now()->endOfMonth();
            break;
    }

    // ⚠️ AJUSTA AQUI COM SEUS IDS REAIS
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

    // 💰 VGV (se tiver campo valor)
  $vgv = Lead::where('status_id', $STATUS_VENDIDO)
    ->whereBetween('leads.updated_at', [$start, $end]) // 👈 AQUI
    ->join('empreendimentos', 'leads.empreendimento_id', '=', 'empreendimentos.id')
    ->sum('empreendimentos.average_sale_value');

    // 📈 métricas
    $conversao = $total > 0 ? ($vendas / $total) * 100 : 0;

    $aproveitamento = $total > 0
        ? (($total - $descartados) / $total) * 100
        : 0;

    // ⏱️ tempo médio
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
