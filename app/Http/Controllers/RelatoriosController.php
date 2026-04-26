<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use App\Models\Appointment;
use App\Models\LeadStatus;
use App\Models\UserMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RelatoriosController extends Controller
{

    private function resolvePeriod(Request $request): array
    {
        $start = $request->filled('start_date')
            ? Carbon::parse($request->start_date)->startOfDay()
            : now()->startOfMonth();

        $end = $request->filled('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : now()->endOfMonth();

        return [$start, $end];
    }

    private function applyScope($query, Request $request, string $column = 'assigned_user_id')
    {
        $user = auth()->user();

        if ($user && $user->role === 'corretor') {
            $query->where($column, $user->id);
            return $query;
        }

        if ($request->filled('corretor_id')) {
            $query->where($column, (int) $request->corretor_id);
        }

        return $query;
    }

    private function applyFilters($query, Request $request)
    {
        if ($request->filled('empreendimento_id')) {
            $query->where('empreendimento_id', (int) $request->empreendimento_id);
        }

        if ($request->filled('channel')) {
            $query->where('channel', $request->channel);
        }

        if ($request->filled('source_id')) {
            $query->where('source_id', (int) $request->source_id);
        }

        return $query;
    }

    public function funnel(Request $request)
    {
        [$start, $end] = $this->resolvePeriod($request);

        $query = Lead::whereBetween('created_at', [$start, $end]);
        $this->applyScope($query, $request);
        $this->applyFilters($query, $request);

        $total = (clone $query)->count();

        $statuses = LeadStatus::orderBy('order')->get();

        $byStatus = $statuses->map(function ($s) use ($query) {
            $count = (clone $query)->where('status_id', $s->id)->count();
            return [
                'id'    => $s->id,
                'name'  => $s->name,
                'order' => $s->order,
                'count' => $count,
            ];
        })->values();

        $sold = (clone $query)->whereHas('status', fn($q) => $q->where('name', 'Vendido'))->count();
        $lost = (clone $query)->whereHas('status', fn($q) => $q->where('name', 'Perdido'))->count();

        return response()->json([
            'period' => [
                'start' => $start->toDateString(),
                'end'   => $end->toDateString(),
            ],
            'summary' => [
                'total_leads'      => $total,
                'leads_sold'       => $sold,
                'leads_lost'       => $lost,
                'leads_active'     => max(0, $total - $sold - $lost),
                'conversion_rate'  => $total > 0 ? round(($sold / $total) * 100, 2) : 0,
            ],
            'by_status' => $byStatus,
        ]);
    }

    public function productivity(Request $request)
    {
        [$start, $end] = $this->resolvePeriod($request);
        $user = auth()->user();

        $apptQuery = Appointment::whereBetween('starts_at', [$start, $end]);

        if ($user && $user->role === 'corretor') {
            $apptQuery->where('user_id', $user->id);
        } elseif ($request->filled('corretor_id')) {
            $apptQuery->where('user_id', (int) $request->corretor_id);
        }

        $byType = (clone $apptQuery)
            ->select('type', DB::raw('count(*) as total'),
                DB::raw("sum(case when status = 'completed' then 1 else 0 end) as completed"))
            ->groupBy('type')
            ->get();

        $totalAppts     = (clone $apptQuery)->count();
        $completedAppts = (clone $apptQuery)->where('status', 'completed')->count();

        $leadsQuery = Lead::whereBetween('created_at', [$start, $end]);
        $this->applyScope($leadsQuery, $request);
        $this->applyFilters($leadsQuery, $request);

        $slaExpired = (clone $leadsQuery)->where('sla_status', 'expired')->count();
        $slaMet     = (clone $leadsQuery)->where('sla_status', 'met')->count();

        $staleCutoff = now()->subDays(5);
        $staleQuery = Lead::query();
        $this->applyScope($staleQuery, $request);
        $stale = (clone $staleQuery)
            ->where(function ($q) use ($staleCutoff) {
                $q->whereNull('last_interaction_at')
                  ->orWhere('last_interaction_at', '<=', $staleCutoff);
            })
            ->count();

        $avgResponseMin = (clone $leadsQuery)
            ->whereNotNull('assigned_at')
            ->whereNotNull('last_interaction_at')
            ->select(DB::raw('AVG(TIMESTAMPDIFF(MINUTE, assigned_at, last_interaction_at)) as avg_min'))
            ->value('avg_min');

        return response()->json([
            'period' => [
                'start' => $start->toDateString(),
                'end'   => $end->toDateString(),
            ],
            'appointments' => [
                'total'        => $totalAppts,
                'completed'    => $completedAppts,
                'completion_rate' => $totalAppts > 0 ? round(($completedAppts / $totalAppts) * 100, 2) : 0,
                'by_type'      => $byType,
            ],
            'sla' => [
                'met'     => $slaMet,
                'expired' => $slaExpired,
            ],
            'stale_leads'       => $stale,
            'avg_response_min'  => $avgResponseMin !== null ? (float) round($avgResponseMin, 1) : null,
        ]);
    }

    public function originCampaign(Request $request)
    {
        [$start, $end] = $this->resolvePeriod($request);

        $query = Lead::with(['source', 'status'])
            ->whereBetween('created_at', [$start, $end]);
        $this->applyScope($query, $request);
        $this->applyFilters($query, $request);

        $leads = (clone $query)->get();

        $bySource = $leads->groupBy(fn($l) => optional($l->source)->name ?? 'Indefinido')
            ->map(function ($items, $name) {
                $total = $items->count();
                $sold  = $items->filter(fn($l) => optional($l->status)->name === 'Vendido')->count();
                return [
                    'name'            => $name,
                    'total'           => $total,
                    'sold'            => $sold,
                    'conversion_rate' => $total > 0 ? round(($sold / $total) * 100, 2) : 0,
                ];
            })->sortByDesc('total')->values();

        $byChannel = $leads->groupBy(fn($l) => $l->channel ?: 'Indefinido')
            ->map(function ($items, $name) {
                $total = $items->count();
                $sold  = $items->filter(fn($l) => optional($l->status)->name === 'Vendido')->count();
                return [
                    'name'            => $name,
                    'total'           => $total,
                    'sold'            => $sold,
                    'conversion_rate' => $total > 0 ? round(($sold / $total) * 100, 2) : 0,
                ];
            })->sortByDesc('total')->values();

        $byCampaign = $leads->groupBy(fn($l) => $l->campaign ?: 'Sem campanha')
            ->map(function ($items, $name) {
                $total = $items->count();
                $sold  = $items->filter(fn($l) => optional($l->status)->name === 'Vendido')->count();
                return [
                    'name'            => $name,
                    'total'           => $total,
                    'sold'            => $sold,
                    'conversion_rate' => $total > 0 ? round(($sold / $total) * 100, 2) : 0,
                ];
            })->sortByDesc('total')->values();

        $byCity = $leads->groupBy(fn($l) => $l->city_of_interest ?: 'Indefinido')
            ->map(function ($items, $name) {
                return [
                    'name'  => $name,
                    'total' => $items->count(),
                ];
            })->sortByDesc('total')->values();

        return response()->json([
            'period' => [
                'start' => $start->toDateString(),
                'end'   => $end->toDateString(),
            ],
            'total'       => $leads->count(),
            'by_source'   => $bySource,
            'by_channel'  => $byChannel,
            'by_campaign' => $byCampaign,
            'by_city'     => $byCity,
        ]);
    }

    public function ranking(Request $request)
    {
        [$start, $end] = $this->resolvePeriod($request);

        $corretores = User::where('role', 'corretor')
            ->where('active', true)
            ->get();

        $mes = $start->month;
        $ano = $start->year;

        $data = $corretores->map(function ($corretor) use ($start, $end, $mes, $ano) {
            $leads = Lead::where('assigned_user_id', $corretor->id)
                ->whereBetween('created_at', [$start, $end]);

            $total = (clone $leads)->count();
            $sold  = (clone $leads)->whereHas('status', fn($q) => $q->where('name', 'Vendido'))->count();

            $appts = Appointment::where('user_id', $corretor->id)
                ->whereBetween('starts_at', [$start, $end])
                ->where('status', 'completed')
                ->count();

            $meta = null;
            if (class_exists(UserMeta::class)) {
                $metaRow = UserMeta::where('user_id', $corretor->id)
                    ->where('mes', $mes)
                    ->where('ano', $ano)
                    ->first();
                if ($metaRow) {
                    $meta = [
                        'leads'         => $metaRow->meta_leads,
                        'atendimentos'  => $metaRow->meta_atendimentos,
                        'vendas'        => $metaRow->meta_vendas,
                        'pct_leads'     => $metaRow->meta_leads > 0
                            ? round(($total / $metaRow->meta_leads) * 100, 1) : null,
                        'pct_atd'       => $metaRow->meta_atendimentos > 0
                            ? round(($appts / $metaRow->meta_atendimentos) * 100, 1) : null,
                        'pct_vendas'    => $metaRow->meta_vendas > 0
                            ? round(($sold / $metaRow->meta_vendas) * 100, 1) : null,
                    ];
                }
            }

            return [
                'user_id'       => $corretor->id,
                'name'          => $corretor->name,
                'leads_total'   => $total,
                'leads_sold'    => $sold,
                'appointments'  => $appts,
                'score'         => ($sold * 10) + ($appts * 2) + $total,
                'meta'          => $meta,
            ];
        })->sortByDesc('score')->values();

        return response()->json([
            'period' => [
                'start' => $start->toDateString(),
                'end'   => $end->toDateString(),
                'mes'   => $mes,
                'ano'   => $ano,
            ],
            'ranking' => $data,
        ]);
    }

    public function evolution(Request $request)
    {
        $months = (int) $request->get('months', 6);
        $months = max(1, min(24, $months));

        $end = now()->endOfMonth();
        $start = now()->copy()->subMonths($months - 1)->startOfMonth();

        $query = Lead::whereBetween('created_at', [$start, $end]);
        $this->applyScope($query, $request);

        $rows = (clone $query)
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as ym"),
                DB::raw('count(*) as total')
            )
            ->groupBy('ym')
            ->orderBy('ym')
            ->get()
            ->keyBy('ym');

        $soldRows = (clone $query)
            ->whereHas('status', fn($q) => $q->where('name', 'Vendido'))
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as ym"),
                DB::raw('count(*) as total')
            )
            ->groupBy('ym')
            ->orderBy('ym')
            ->get()
            ->keyBy('ym');

        $series = [];
        for ($i = 0; $i < $months; $i++) {
            $d = $start->copy()->addMonths($i);
            $ym = $d->format('Y-m');
            $series[] = [
                'ym'    => $ym,
                'label' => $d->translatedFormat('M/Y'),
                'total' => (int) ($rows[$ym]->total ?? 0),
                'sold'  => (int) ($soldRows[$ym]->total ?? 0),
            ];
        }

        return response()->json(['series' => $series]);
    }
}
