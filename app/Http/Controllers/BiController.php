<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Commission;
use App\Models\Lead;
use App\Models\LeadHistory;
use App\Models\LeadStatus;
use App\Models\User;
use App\Models\Empreendimento;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * R1 — BI Executivo.
 *
 * Visão consolidada pra dono / gestor: KPIs, comparativo MoM,
 * forecast de comissões, aging de leads, motivos de perda,
 * performance por empreendimento e tipologia, heatmap de chegada de leads.
 *
 * Todos os endpoints aceitam filtros via querystring:
 *   period: current_month | last_month | last_30_days | last_90_days | year_to_date | custom
 *   start_date / end_date (yyyy-mm-dd) — usado quando period=custom
 *   corretor_id (opcional)
 *   empreendimento_id (opcional)
 */
class BiController extends Controller
{

    private function resolvePeriod(Request $request): array
    {
        $preset = $request->input('period', 'current_month');
        $now = now();

        switch ($preset) {
            case 'last_month':
                $start = $now->copy()->subMonthNoOverflow()->startOfMonth();
                $end   = $now->copy()->subMonthNoOverflow()->endOfMonth();
                break;
            case 'last_30_days':
                $start = $now->copy()->subDays(30)->startOfDay();
                $end   = $now->copy()->endOfDay();
                break;
            case 'last_90_days':
                $start = $now->copy()->subDays(90)->startOfDay();
                $end   = $now->copy()->endOfDay();
                break;
            case 'year_to_date':
                $start = $now->copy()->startOfYear();
                $end   = $now->copy()->endOfDay();
                break;
            case 'custom':
                $start = $request->filled('start_date')
                    ? Carbon::parse($request->start_date)->startOfDay()
                    : $now->copy()->startOfMonth();
                $end = $request->filled('end_date')
                    ? Carbon::parse($request->end_date)->endOfDay()
                    : $now->copy()->endOfMonth();
                break;
            case 'current_month':
            default:
                $start = $now->copy()->startOfMonth();
                $end   = $now->copy()->endOfMonth();
        }

        return [$start, $end];
    }


    private function previousPeriod(Carbon $start, Carbon $end): array
    {
        $days = $start->diffInDays($end) + 1;
        $prevEnd   = $start->copy()->subDay()->endOfDay();
        $prevStart = $prevEnd->copy()->subDays($days - 1)->startOfDay();
        return [$prevStart, $prevEnd];
    }


    private function applyScope($query, Request $request, string $userColumn = 'assigned_user_id')
    {
        $user = auth()->user();
        if ($user && strcasecmp((string) $user->role, 'corretor') === 0) {
            $query->where($userColumn, $user->id);
            return $query;
        }
        if ($request->filled('corretor_id')) {
            $query->where($userColumn, (int) $request->corretor_id);
        }
        return $query;
    }

    private function applyEmp($query, Request $request)
    {
        if ($request->filled('empreendimento_id')) {
            $query->where('empreendimento_id', (int) $request->empreendimento_id);
        }
        return $query;
    }


    private function pctChange(?float $current, ?float $previous): ?float
    {
        $current  = (float) ($current  ?? 0);
        $previous = (float) ($previous ?? 0);
        if ($previous == 0) return $current > 0 ? 100.0 : null;
        return round((($current - $previous) / $previous) * 100, 1);
    }


    public function overview(Request $request)
    {
        [$start, $end] = $this->resolvePeriod($request);
        [$prevStart, $prevEnd] = $this->previousPeriod($start, $end);

        $cur  = $this->overviewMetrics($request, $start,    $end);
        $prev = $this->overviewMetrics($request, $prevStart, $prevEnd);

        return response()->json([
            'period' => [
                'start' => $start->toDateString(),
                'end'   => $end->toDateString(),
                'previous_start' => $prevStart->toDateString(),
                'previous_end'   => $prevEnd->toDateString(),
            ],
            'kpis' => [
                'leads_in'       => ['current' => $cur['leads_in'],    'previous' => $prev['leads_in'],    'pct' => $this->pctChange($cur['leads_in'], $prev['leads_in'])],
                'sales_count'    => ['current' => $cur['sales_count'], 'previous' => $prev['sales_count'], 'pct' => $this->pctChange($cur['sales_count'], $prev['sales_count'])],
                'revenue'        => ['current' => $cur['revenue'],     'previous' => $prev['revenue'],     'pct' => $this->pctChange($cur['revenue'], $prev['revenue'])],
                'avg_ticket'     => ['current' => $cur['avg_ticket'],  'previous' => $prev['avg_ticket'],  'pct' => $this->pctChange($cur['avg_ticket'], $prev['avg_ticket'])],
                'conversion_pct' => ['current' => $cur['conversion'],  'previous' => $prev['conversion'],  'pct' => $this->pctChange($cur['conversion'], $prev['conversion'])],
                'commission_paid'=> ['current' => $cur['commission_paid'], 'previous' => $prev['commission_paid'], 'pct' => $this->pctChange($cur['commission_paid'], $prev['commission_paid'])],
            ],
        ]);
    }

    private function overviewMetrics(Request $request, Carbon $start, Carbon $end): array
    {
        $leadsQ = Lead::whereBetween('created_at', [$start, $end]);
        $this->applyScope($leadsQ, $request);
        $this->applyEmp($leadsQ, $request);
        $leadsIn = (clone $leadsQ)->count();


        $soldStatusIds = LeadStatus::where('name', 'Vendido')
            ->orWhereRaw('LOWER(name) LIKE ?', ['%vendid%'])
            ->pluck('id');

        $salesQ = Lead::whereIn('status_id', $soldStatusIds)
            ->whereBetween('updated_at', [$start, $end]);
        $this->applyScope($salesQ, $request);
        $this->applyEmp($salesQ, $request);

        $salesCount = (clone $salesQ)->count();
        $revenue    = (float) (clone $salesQ)->sum('value');
        $avgTicket  = $salesCount > 0 ? round($revenue / $salesCount, 2) : 0.0;
        $conversion = $leadsIn > 0 ? round(($salesCount / $leadsIn) * 100, 1) : 0.0;


        $commQ = Commission::where('status', 'paid')
            ->whereBetween('paid_at', [$start, $end]);
        $this->applyScope($commQ, $request, 'user_id');
        $commissionPaid = (float) $commQ->sum('commission_value');

        return [
            'leads_in'        => $leadsIn,
            'sales_count'     => $salesCount,
            'revenue'         => $revenue,
            'avg_ticket'      => $avgTicket,
            'conversion'      => $conversion,
            'commission_paid' => $commissionPaid,
        ];
    }


    public function visitToSale(Request $request)
    {
        [$start, $end] = $this->resolvePeriod($request);

        $visitsQ = Appointment::where(function ($q) {
            $q->where('type', 'visit')
              ->orWhereIn('task_kind', ['visita', 'agendamento']);
        })
        ->where('confirmation_status', 'completed')
        ->whereBetween('completed_at', [$start, $end]);

        $this->applyScope($visitsQ, $request, 'user_id');
        if ($request->filled('empreendimento_id')) {
            $visitsQ->whereHas('lead', fn($q) => $q->where('empreendimento_id', (int) $request->empreendimento_id));
        }

        $totalVisits = (clone $visitsQ)->count();
        $visitedLeadIds = (clone $visitsQ)->pluck('lead_id')->filter()->unique();


        $soldStatusIds = LeadStatus::orWhereRaw('LOWER(name) LIKE ?', ['%vendid%'])->pluck('id');
        $convertedCount = Lead::whereIn('id', $visitedLeadIds)
            ->whereIn('status_id', $soldStatusIds)
            ->count();

        $rate = $totalVisits > 0 ? round(($convertedCount / $totalVisits) * 100, 1) : 0.0;

        return response()->json([
            'visits_completed' => $totalVisits,
            'visits_converted' => $convertedCount,
            'conversion_rate'  => $rate,
        ]);
    }


    public function commissionForecast(Request $request)
    {
        [$start, $end] = $this->resolvePeriod($request);


        $months = collect();
        $cursor = $start->copy()->startOfMonth();
        while ($cursor <= $end) {
            $months->push($cursor->format('Y-m'));
            $cursor->addMonth();
        }


        $rows = Commission::query()
            ->select(
                DB::raw("DATE_FORMAT(COALESCE(expected_payment_date, paid_at, approved_at, created_at), '%Y-%m') as ym"),
                'status',
                DB::raw('SUM(commission_value) as total'),
                DB::raw('COUNT(*) as cnt')
            )
            ->whereIn('status', ['pending', 'approved', 'paid'])
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('expected_payment_date', [$start, $end])
                  ->orWhereBetween('paid_at', [$start, $end])
                  ->orWhereBetween('approved_at', [$start, $end])
                  ->orWhereBetween('created_at', [$start, $end]);
            });
        $this->applyScope($rows, $request, 'user_id');
        $rows = $rows->groupBy('ym', 'status')->get();


        $byMonth = $months->mapWithKeys(fn($m) => [$m => ['pending' => 0, 'approved' => 0, 'paid' => 0]]);
        foreach ($rows as $r) {
            if (!isset($byMonth[$r->ym])) continue;
            $byMonth[$r->ym][$r->status] = (float) $r->total;
        }


        $totalsQ = Commission::query();
        $this->applyScope($totalsQ, $request, 'user_id');
        $totals = [
            'paid'     => (float) (clone $totalsQ)->where('status', 'paid')->sum('commission_value'),
            'approved' => (float) (clone $totalsQ)->where('status', 'approved')->sum('commission_value'),
            'pending'  => (float) (clone $totalsQ)->where('status', 'pending')->sum('commission_value'),
        ];

        return response()->json([
            'months'  => $byMonth,
            'totals'  => $totals,
        ]);
    }


    public function leadAging(Request $request)
    {
        $threshold = (int) $request->input('days_threshold', 7);
        if ($threshold < 1) $threshold = 7;
        if ($threshold > 90) $threshold = 90;

        $cutoff = now()->subDays($threshold);

        $statuses = LeadStatus::where(function ($q) {
                $q->whereNull('is_terminal')->orWhere('is_terminal', false);
            })
            ->where(function ($q) {
                $q->whereNull('is_discard')->orWhere('is_discard', false);
            })
            ->orderBy('order')
            ->get();

        $byStatus = [];
        foreach ($statuses as $st) {
            $q = Lead::where('status_id', $st->id)
                ->where('updated_at', '<', $cutoff)
                ->whereNull('deleted_at');
            $this->applyScope($q, $request);
            $this->applyEmp($q, $request);

            $count = (clone $q)->count();
            if ($count === 0) continue;

            $samples = (clone $q)
                ->orderBy('updated_at', 'asc')
                ->limit(5)
                ->get(['id', 'name', 'value', 'assigned_user_id', 'updated_at']);

            $byStatus[] = [
                'status_id'    => $st->id,
                'status_name'  => $st->name,
                'status_color' => $st->color_hex ?? '#94a3b8',
                'count'        => $count,
                'samples'      => $samples->map(fn($l) => [
                    'id'              => $l->id,
                    'name'            => $l->name,
                    'value'           => $l->value,
                    'days_since'      => (int) abs(round($l->updated_at->diffInDays(now()))),
                    'assigned_user_id'=> $l->assigned_user_id,
                ]),
            ];
        }

        return response()->json([
            'days_threshold' => $threshold,
            'by_status'      => $byStatus,
        ]);
    }


    public function lostReasons(Request $request)
    {
        [$start, $end] = $this->resolvePeriod($request);


        $lostStatusIds = LeadStatus::where('is_discard', true)
            ->orWhereRaw('LOWER(name) LIKE ?', ['%perdid%'])
            ->pluck('id');

        if ($lostStatusIds->isEmpty()) {
            return response()->json([
                'total' => 0,
                'reasons' => [],
            ]);
        }


        $q = Lead::whereIn('status_id', $lostStatusIds)
            ->whereBetween('updated_at', [$start, $end])
            ->whereNotNull('lost_reason');
        $this->applyScope($q, $request);
        $this->applyEmp($q, $request);

        $rows = (clone $q)
            ->select('lost_reason', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(value) as lost_value'))
            ->groupBy('lost_reason')
            ->orderByDesc('cnt')
            ->limit(15)
            ->get();

        $totalQ = Lead::whereIn('status_id', $lostStatusIds)
            ->whereBetween('updated_at', [$start, $end]);
        $this->applyScope($totalQ, $request);
        $this->applyEmp($totalQ, $request);
        $total = $totalQ->count();

        return response()->json([
            'total'   => $total,
            'reasons' => $rows->map(fn($r) => [
                'reason'     => $r->lost_reason ?: '(sem motivo informado)',
                'count'      => (int) $r->cnt,
                'lost_value' => (float) ($r->lost_value ?? 0),
            ]),
        ]);
    }


    public function empreendimentoPerformance(Request $request)
    {
        [$start, $end] = $this->resolvePeriod($request);

        $soldStatusIds = LeadStatus::orWhereRaw('LOWER(name) LIKE ?', ['%vendid%'])->pluck('id');

        $emps = Empreendimento::where('active', true)->orderBy('name')->get(['id', 'name', 'code']);

        $rows = $emps->map(function ($emp) use ($start, $end, $soldStatusIds, $request) {
            $leadsQ = Lead::where('empreendimento_id', $emp->id)
                ->whereBetween('created_at', [$start, $end]);
            $this->applyScope($leadsQ, $request);

            $leadsTotal = (clone $leadsQ)->count();
            if ($leadsTotal === 0 && !$request->boolean('include_zero')) return null;

            $visitsCount = Appointment::where(function ($q) {
                    $q->where('type', 'visit')->orWhereIn('task_kind', ['visita','agendamento']);
                })
                ->where('confirmation_status', 'completed')
                ->whereBetween('completed_at', [$start, $end])
                ->whereHas('lead', fn($q) => $q->where('empreendimento_id', $emp->id))
                ->count();

            $salesQ = Lead::where('empreendimento_id', $emp->id)
                ->whereIn('status_id', $soldStatusIds)
                ->whereBetween('updated_at', [$start, $end]);
            $this->applyScope($salesQ, $request);
            $salesCount = (clone $salesQ)->count();
            $revenue    = (float) (clone $salesQ)->sum('value');

            return [
                'id'            => $emp->id,
                'name'          => $emp->name,
                'code'          => $emp->code,
                'leads'         => $leadsTotal,
                'visits'        => $visitsCount,
                'sales'         => $salesCount,
                'revenue'       => $revenue,
                'conv_lead_to_sale'  => $leadsTotal > 0 ? round(($salesCount / $leadsTotal) * 100, 1) : 0.0,
                'conv_visit_to_sale' => $visitsCount > 0 ? round(($salesCount / $visitsCount) * 100, 1) : 0.0,
            ];
        })->filter()->values();

        return response()->json([
            'empreendimentos' => $rows,
        ]);
    }


    public function tipologiaAnalysis(Request $request)
    {
        [$start, $end] = $this->resolvePeriod($request);


        if (!\Illuminate\Support\Facades\Schema::hasTable('empreendimento_tipologias')) {
            return response()->json(['tipologias' => []]);
        }


        $rows = DB::table('lead_empreendimentos as le')
            ->join('leads as l', 'l.id', '=', 'le.lead_id')
            ->join('empreendimentos as e', 'e.id', '=', 'le.empreendimento_id')
            ->join('empreendimento_tipologias as t', 't.empreendimento_id', '=', 'e.id')
            ->whereBetween('l.created_at', [$start, $end])
            ->whereNull('l.deleted_at')
            ->select(
                't.id as tip_id',
                't.name as tip_name',
                'e.name as emp_name',
                DB::raw('COUNT(DISTINCT l.id) as leads_count'),
                DB::raw('AVG(t.area_min_m2) as avg_area_min'),
                DB::raw('AVG(t.area_max_m2) as avg_area_max'),
                DB::raw('AVG(t.price_from) as avg_price_from')
            )
            ->groupBy('t.id', 't.name', 'e.name')
            ->orderByDesc('leads_count')
            ->limit(30)
            ->get();

        return response()->json([
            'tipologias' => $rows->map(fn($r) => [
                'id'          => $r->tip_id,
                'name'        => $r->tip_name,
                'empreendimento' => $r->emp_name,
                'leads'       => (int) $r->leads_count,
                'avg_area_min'=> $r->avg_area_min ? (float) $r->avg_area_min : null,
                'avg_area_max'=> $r->avg_area_max ? (float) $r->avg_area_max : null,
                'avg_price'   => $r->avg_price_from ? (float) $r->avg_price_from : null,
            ]),
        ]);
    }


    public function leadHeatmap(Request $request)
    {
        [$start, $end] = $this->resolvePeriod($request);

        $q = Lead::whereBetween('created_at', [$start, $end]);
        $this->applyScope($q, $request);
        $this->applyEmp($q, $request);

        $rows = $q->select(
                DB::raw('DAYOFWEEK(created_at) as dow'),
                DB::raw('HOUR(created_at) as hour'),
                DB::raw('COUNT(*) as cnt')
            )
            ->groupBy('dow', 'hour')
            ->get();


        $matrix = [];
        for ($d = 1; $d <= 7; $d++) {
            $matrix[$d] = array_fill(0, 24, 0);
        }
        foreach ($rows as $r) {
            $matrix[(int) $r->dow][(int) $r->hour] = (int) $r->cnt;
        }


        $totalByDow = [];
        $totalByHour = array_fill(0, 24, 0);
        foreach ($matrix as $dow => $hours) {
            $totalByDow[$dow] = array_sum($hours);
            foreach ($hours as $h => $c) {
                $totalByHour[$h] += $c;
            }
        }

        return response()->json([
            'matrix'        => $matrix,
            'total_by_dow'  => $totalByDow,
            'total_by_hour' => $totalByHour,
            'days_labels'   => ['', 'Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'],
        ]);
    }


    public function topRanking(Request $request)
    {
        [$start, $end] = $this->resolvePeriod($request);
        $soldStatusIds = LeadStatus::orWhereRaw('LOWER(name) LIKE ?', ['%vendid%'])->pluck('id');


        $emps = Empreendimento::where('active', true)
            ->withCount(['leads as sales_count' => function ($q) use ($start, $end, $soldStatusIds) {
                $q->whereIn('status_id', $soldStatusIds)
                  ->whereBetween('updated_at', [$start, $end]);
            }])
            ->orderByDesc('sales_count')
            ->limit(10)
            ->get(['id', 'name', 'code']);


        $corretores = User::where('role', 'corretor')
            ->where('active', true)
            ->withCount(['leads as sales_count' => function ($q) use ($start, $end, $soldStatusIds) {
                $q->whereIn('status_id', $soldStatusIds)
                  ->whereBetween('updated_at', [$start, $end]);
            }])
            ->orderByDesc('sales_count')
            ->limit(10)
            ->get(['id', 'name']);

        return response()->json([
            'top_empreendimentos' => $emps->map(fn($e) => [
                'id'    => $e->id,
                'name'  => $e->name,
                'sales' => (int) $e->sales_count,
            ]),
            'top_corretores' => $corretores->map(fn($u) => [
                'id'    => $u->id,
                'name'  => $u->name,
                'sales' => (int) $u->sales_count,
            ]),
        ]);
    }
}
