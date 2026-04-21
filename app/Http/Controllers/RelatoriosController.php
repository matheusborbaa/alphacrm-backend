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

/**
 * @group Relatórios
 *
 * Endpoints dos relatórios do CRM (corretor + gerente).
 * Escopo automático: quando o user autenticado é corretor, só vê dados dele.
 * Admin/gestor vê tudo, podendo filtrar por corretor via query param.
 */
class RelatoriosController extends Controller
{
    /* =========================================================
     | HELPERS
     |========================================================= */

    /**
     * Resolve o range de datas (start/end) a partir da request.
     * Default: mês corrente.
     */
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

    /**
     * Aplica o escopo do usuário autenticado: corretor só vê dados dele.
     * Admin/gestor pode filtrar por corretor_id opcional.
     */
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

    /**
     * Aplica filtros secundários de empreendimento/canal/origem se presentes.
     */
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

    /* =========================================================
     | FUNIL DE CONVERSÃO
     |=========================================================
     | GET /reports/funnel
     | Query: start_date, end_date, corretor_id, empreendimento_id, channel, source_id
     |
     | Retorna:
     |   total_leads, por status (com order), taxa de conversão geral,
     |   leads_convertidos (status "Vendido"), leads_perdidos (status "Perdido"),
     |   leads_ativos (demais).
     */
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

    /* =========================================================
     | PRODUTIVIDADE
     |=========================================================
     | GET /reports/productivity
     | Retorna: appointments realizados por tipo (call/visit/meeting/task),
     | tempo médio de resposta (first interaction), leads vencidos (sla expired),
     | leads sem interação nos últimos N dias.
     */
    public function productivity(Request $request)
    {
        [$start, $end] = $this->resolvePeriod($request);
        $user = auth()->user();

        // Appointments no período
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

        // Leads vencidos (SLA) e leads do período
        $leadsQuery = Lead::whereBetween('created_at', [$start, $end]);
        $this->applyScope($leadsQuery, $request);
        $this->applyFilters($leadsQuery, $request);

        $slaExpired = (clone $leadsQuery)->where('sla_status', 'expired')->count();
        $slaMet     = (clone $leadsQuery)->where('sla_status', 'met')->count();

        // Leads sem interação há mais de 5 dias (no escopo)
        $staleCutoff = now()->subDays(5);
        $staleQuery = Lead::query();
        $this->applyScope($staleQuery, $request);
        $stale = (clone $staleQuery)
            ->where(function ($q) use ($staleCutoff) {
                $q->whereNull('last_interaction_at')
                  ->orWhere('last_interaction_at', '<=', $staleCutoff);
            })
            ->count();

        // Tempo médio de resposta (em minutos) = assigned_at -> last_interaction_at
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

    /* =========================================================
     | ORIGEM / CAMPANHA
     |=========================================================
     | GET /reports/origin-campaign
     | Retorna leads agrupados por source, channel, campaign e cidade.
     */
    public function originCampaign(Request $request)
    {
        [$start, $end] = $this->resolvePeriod($request);

        $query = Lead::with(['source', 'status'])
            ->whereBetween('created_at', [$start, $end]);
        $this->applyScope($query, $request);
        $this->applyFilters($query, $request);

        $leads = (clone $query)->get();

        // por origem (source)
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

        // por canal
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

        // por campanha
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

        // por cidade
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

    /* =========================================================
     | RANKING / GAMIFICAÇÃO
     |=========================================================
     | GET /reports/ranking
     | Retorna ranking de corretores no período com:
     | - leads recebidos, atendidos, convertidos
     | - appointments realizados
     | - % da meta atingida (se tiver user_meta cadastrada no mês)
     |
     | Quando o user autenticado é corretor, ele ainda vê o ranking geral
     | (transparência da gamificação).
     */
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
                'score'         => ($sold * 10) + ($appts * 2) + $total, // simple weighted score
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

    /* =========================================================
     | EVOLUÇÃO MENSAL (últimos 6 meses)
     |=========================================================
     | GET /reports/evolution
     | Retorna série temporal por mês dos últimos 6 meses:
     | leads criados, leads vendidos.
     */
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
