<?php

namespace App\Http\Controllers;

use App\Models\Commission;
use App\Models\Lead;
use App\Models\Appointment;
use App\Models\User;
use App\Models\UserMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class HomeController extends Controller
{

    public function summary(Request $request)
    {
        $user    = $request->user();

        [$finStart, $finEnd] = $this->resolveFinancePeriod($request);
        $finUserId           = $this->resolveFinanceUserId($request, $user);
        $empreendimentoId    = $this->resolveEmpreendimentoFilter($request);

        $start = now()->startOfMonth();
        $end   = now()->endOfMonth();
        $mes   = now()->month;
        $ano   = now()->year;

        $finUserScope = $this->resolveFinanceUserScope($request, $user, $finUserId);

        $comissQuery = Commission::query()
            ->whereBetween('created_at', [$finStart, $finEnd]);

        if (is_array($finUserScope)) {
            $comissQuery->whereIn('user_id', $finUserScope);
        } elseif ($finUserId !== null) {
            $comissQuery->where('user_id', $finUserId);
        }

        if ($empreendimentoId) {

            $comissQuery->whereHas('lead', fn($q) => $q->where('empreendimento_id', $empreendimentoId));
        }

        $comissPendente = (clone $comissQuery)->where('status', 'pending')->sum('commission_value');
        $comissRecebido = (clone $comissQuery)->where('status', 'paid')->sum('commission_value');

        $soldLeadsQ = Lead::query()
            ->whereBetween('status_changed_at', [$finStart, $finEnd])
            ->whereHas('status', fn($q) => $q->where('name', 'Vendido'));
        if (is_array($finUserScope)) {
            $soldLeadsQ->whereIn('assigned_user_id', $finUserScope);
        } elseif ($finUserId !== null) {
            $soldLeadsQ->where('assigned_user_id', $finUserId);
        }
        if ($empreendimentoId) {
            $soldLeadsQ->where('empreendimento_id', $empreendimentoId);
        }
        $soldLeads   = $soldLeadsQ->get(['id', 'value']);
        $vendasCount = $soldLeads->count();
        $vendasValor = (float) $soldLeads->sum('value');

        $vgvQ = Lead::query()
            ->whereDoesntHave('status', fn($q) => $q->whereIn('name', ['Vendido', 'Perdido']));
        if (is_array($finUserScope)) {
            $vgvQ->whereIn('assigned_user_id', $finUserScope);
        } elseif ($finUserId !== null) {
            $vgvQ->where('assigned_user_id', $finUserId);
        }
        if ($empreendimentoId) {
            $vgvQ->where('empreendimento_id', $empreendimentoId);
        }
        $vgvAtivo = (float) $vgvQ->sum('value');

        $meta = UserMeta::where('user_id', $user->id)
            ->where('mes', $mes)
            ->where('ano', $ano)
            ->first();

        $leadsRecebidos = Lead::where('assigned_user_id', $user->id)
            ->whereBetween('assigned_at', [$start, $end])
            ->count();

        $atendimentosCompletos = Appointment::where('user_id', $user->id)
            ->whereBetween('starts_at', [$start, $end])
            ->where('status', 'completed')
            ->count();

        $metas = [
            'leads' => [
                'feito' => $leadsRecebidos,
                'meta'  => $meta?->meta_leads ?? 0,
                'pct'   => ($meta && $meta->meta_leads > 0)
                    ? round(($leadsRecebidos / $meta->meta_leads) * 100, 1)
                    : null,
            ],
            'atendimentos' => [
                'feito' => $atendimentosCompletos,
                'meta'  => $meta?->meta_atendimentos ?? 0,
                'pct'   => ($meta && $meta->meta_atendimentos > 0)
                    ? round(($atendimentosCompletos / $meta->meta_atendimentos) * 100, 1)
                    : null,
            ],
            'vendas' => [
                'feito' => $vendasCount,
                'meta'  => $meta?->meta_vendas ?? 0,
                'pct'   => ($meta && $meta->meta_vendas > 0)
                    ? round(($vendasCount / $meta->meta_vendas) * 100, 1)
                    : null,
            ],
        ];

        $corretores = User::where('role', 'corretor')
            ->where('active', true)
            ->get();

        $rankingFull = $corretores->map(function ($c) use ($start, $end) {
            $totalLeads = Lead::where('assigned_user_id', $c->id)
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $soldByC = Lead::where('assigned_user_id', $c->id)
                ->whereBetween('status_changed_at', [$start, $end])
                ->whereHas('status', fn($q) => $q->where('name', 'Vendido'))
                ->count();

            $apptsByC = Appointment::where('user_id', $c->id)
                ->whereBetween('starts_at', [$start, $end])
                ->where('status', 'completed')
                ->count();

            return [
                'user_id'       => $c->id,
                'name'          => $c->name,
                'leads_total'   => $totalLeads,
                'leads_sold'    => $soldByC,
                'appointments'  => $apptsByC,
                'score'         => ($soldByC * 10) + ($apptsByC * 2) + $totalLeads,
            ];
        })->sortByDesc('score')->values();

        $myPos = null;
        foreach ($rankingFull as $i => $item) {
            if ($item['user_id'] === $user->id) { $myPos = $i + 1; break; }
        }

        $top5 = $rankingFull->take(5)->map(fn($r, $i) => array_merge($r, ['position' => $i + 1]));

        $atendimentosPendentes = Lead::where('assigned_user_id', $user->id)
            ->whereIn('sla_status', ['pending', 'expired'])
            ->count();

        $tarefasPendentes = Appointment::tasks()
            ->where('user_id', $user->id)
            ->whereNull('completed_at')
            ->count();

        $followupsAtrasados = Appointment::tasks()
            ->where('user_id', $user->id)
            ->where('task_kind', Appointment::KIND_FOLLOWUP)
            ->overdue()
            ->count();

        $leadsSemTarefa = Lead::where('assigned_user_id', $user->id)
            ->whereDoesntHave('appointments', function ($q) {
                $q->whereNull('completed_at')
                  ->whereNotNull('due_at')
                  ->where('due_at', '>=', now()->startOfDay());
            })
            ->count();

        return response()->json([
            'period' => [
                'start' => $start->toDateString(),
                'end'   => $end->toDateString(),
                'mes'   => $mes,
                'ano'   => $ano,
            ],
            'financeiro' => [
                'comissao_pendente' => (float) $comissPendente,
                'comissao_recebida' => (float) $comissRecebido,
                'comissao_total'    => (float) ($comissPendente + $comissRecebido),
                'vendas_count'      => $vendasCount,
                'vendas_valor'      => $vendasValor,
                'vgv_ativo'         => $vgvAtivo,
            ],
            'metas' => $metas,
            'pendentes' => [
                'atendimentos'        => $atendimentosPendentes,
                'tarefas'             => $tarefasPendentes,

                'followups_atrasados' => $followupsAtrasados,
                'leads_sem_tarefa'    => $leadsSemTarefa,
            ],
            'gamificacao' => [
                'minha_posicao' => $myPos,
                'total_corretores' => $rankingFull->count(),
                'top5' => $top5,
            ],
        ]);
    }

    public function nextCommissions(Request $request)
    {
        $user = $request->user();

        [$start, $end]    = $this->resolveFinancePeriod($request);
        $finUserId        = $this->resolveFinanceUserId($request, $user);
        $empreendimentoId = $this->resolveEmpreendimentoFilter($request);

        $query = Commission::query()
            ->with([
                'lead:id,name,empreendimento_id',
                'lead.empreendimento:id,name',

                'user:id,name',
            ])
            ->where(function ($q) use ($start, $end) {

                $q->whereBetween('expected_payment_date', [$start, $end])
                  ->orWhere(function ($q2) use ($start, $end) {
                      $q2->whereNull('expected_payment_date')
                         ->whereBetween('created_at', [$start, $end]);
                  });
            });

        $finUserScope = $this->resolveFinanceUserScope($request, $user, $finUserId);

        if (is_array($finUserScope)) {
            $query->whereIn('user_id', $finUserScope);
        } elseif ($finUserId !== null) {
            $query->where('user_id', $finUserId);
        }

        if ($empreendimentoId) {
            $query->whereHas('lead', fn($q) => $q->where('empreendimento_id', $empreendimentoId));
        }

        $rows = $query
            ->orderByRaw('COALESCE(expected_payment_date, DATE_ADD(created_at, INTERVAL 30 DAY)) ASC')
            ->limit(50)
            ->get();

        return response()->json($rows->map(function ($c) {
            $expected = $c->expected_payment_date
                ?: ($c->created_at ? $c->created_at->copy()->addDays(30) : null);

            return [
                'id'                   => $c->id,
                'expected_date'        => optional($expected)->toDateString(),
                'client_name'          => $c->lead?->name ?? '—',
                'empreendimento_name'  => $c->lead?->empreendimento?->name,
                'vgv'                  => (float) $c->sale_value,
                'commission_value'     => (float) $c->commission_value,
                'status'               => $c->status ?: 'pending',

                'corretor_name'        => $c->user?->name,
            ];
        }));
    }

    private function resolveFinancePeriod(Request $request): array
    {
        $from = trim((string) $request->input('from', ''));
        $to   = trim((string) $request->input('to', ''));

        if ($from !== '' && $to !== '') {
            try {
                $start = Carbon::parse($from)->startOfDay();
                $end   = Carbon::parse($to)->endOfDay();

                if ($start->gt($end)) [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
                return [$start, $end];
            } catch (\Throwable $e) {

            }
        }

        $periodo = (string) $request->input('periodo', 'mensal');
        return match ($periodo) {
            'diario'  => [now()->startOfDay(),   now()->endOfDay()],
            'semanal' => [now()->startOfWeek(),  now()->endOfWeek()],
            default   => [now()->startOfMonth(), now()->endOfMonth()],
        };
    }

    private function resolveFinanceUserId(Request $request, $user): ?int
    {
        $myId = (int) $user->id;
        $role = strtolower(trim((string) ($user->role ?? '')));
        if (!in_array($role, ['admin', 'gestor'], true)) {
            return $myId;
        }

        $raw = (string) $request->input('corretor_id', '');
        if ($raw === 'all') {
            return null;
        }

        $corretor = (int) $raw;
        return $corretor > 0 ? $corretor : $myId;
    }

    private function resolveEmpreendimentoFilter(Request $request): int
    {
        $id = (int) $request->input('empreendimento_id', 0);
        return $id > 0 ? $id : 0;
    }

    private function resolveFinanceUserScope(Request $request, $user, ?int $finUserId): ?array
    {

        $raw = (string) $request->input('corretor_id', '');
        if ($raw !== 'all') return null;

        $role = strtolower((string) ($user->role ?? ''));
        if ($role === 'admin') return null;
        if ($role === 'gestor') return $user->descendantIds();

        return null;
    }
}
