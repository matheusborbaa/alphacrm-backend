<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Lead;
use App\Models\Commission;
use App\Models\Agenda;
use App\Models\Task;
use App\Models\LeadStatus;

class DashboardHomeController extends Controller
{

    public function funnel(Request $request)
    {

        [$start, $end] = $this->resolveFunnelPeriod($request);

        $statuses = \App\Models\LeadStatus::query()
            ->where('is_terminal', false)
            ->orderBy('order')
            ->get(['id', 'name', 'order', 'color_hex']);

        if ($statuses->isEmpty()) {
            return response()->json([]);
        }

        $allStatusOrders = \App\Models\LeadStatus::query()
            ->orderBy('order')
            ->pluck('order', 'id');

        $leadsByStatusId = \App\Models\Lead::query()
            ->when($start && $end, fn($q) => $q->whereBetween('created_at', [$start, $end]))
            ->whereNotNull('status_id')
            ->selectRaw('status_id, COUNT(*) as cnt')
            ->groupBy('status_id')
            ->pluck('cnt', 'status_id');

        $cumulativeByEtapa = [];
        foreach ($statuses as $stage) {
            $sum = 0;
            foreach ($allStatusOrders as $sid => $order) {
                if ($order >= (int) $stage->order) {
                    $sum += (int) ($leadsByStatusId[$sid] ?? 0);
                }
            }
            $cumulativeByEtapa[$stage->id] = $sum;
        }

        $avgDaysByStatus = $this->computeAvgDaysPerStatus(
            $statuses->pluck('id')->all()
        );

        $previousTotal = null;
        $payload = $statuses->map(function ($status) use (&$previousTotal, $avgDaysByStatus, $cumulativeByEtapa) {
            $total = (int) ($cumulativeByEtapa[$status->id] ?? 0);
            $convPct = ($previousTotal === null)
                ? 100.0
                : ($previousTotal > 0 ? round(($total / $previousTotal) * 100, 1) : 0.0);
            $previousTotal = $total;

            return [
                'id'             => (int) $status->id,
                'name'           => $status->name,
                'order'          => (int) $status->order,
                'color_hex'      => $status->color_hex,
                'total'          => $total,
                'conv_rate_pct'  => $convPct,
                'avg_days'       => $avgDaysByStatus[$status->id] ?? null,
            ];
        });

        return response()->json($payload);
    }

    private function computeAvgDaysPerStatus(array $statusIds): array
    {
        if (empty($statusIds)) return [];

        $transitions = \App\Models\LeadHistory::query()
            ->where('type', 'status_change')
            ->where('created_at', '>=', now()->subDays(90))
            ->orderBy('lead_id')
            ->orderBy('created_at')
            ->get(['lead_id', 'from', 'to', 'created_at']);

        $statusIdSet = array_flip($statusIds);
        $sumDays   = [];
        $countObs  = [];
        $entryByLead = [];

        foreach ($transitions as $t) {
            $leadId = (int) $t->lead_id;
            $fromId = (int) $t->from;
            $toId   = (int) $t->to;

            if (isset($entryByLead[$leadId]) &&
                $entryByLead[$leadId]['status_id'] === $fromId &&
                isset($statusIdSet[$fromId])) {
                $days = $entryByLead[$leadId]['at']->diffInDays($t->created_at);
                $sumDays[$fromId]  = ($sumDays[$fromId]  ?? 0) + $days;
                $countObs[$fromId] = ($countObs[$fromId] ?? 0) + 1;
            }

            $entryByLead[$leadId] = [
                'status_id' => $toId,
                'at'        => $t->created_at,
            ];
        }

        $avgs = [];
        foreach ($sumDays as $statusId => $sum) {
            $count = $countObs[$statusId] ?? 0;
            if ($count > 0) {
                $avgs[$statusId] = round($sum / $count, 1);
            }
        }

        return $avgs;
    }

    private function resolveFunnelPeriod(\Illuminate\Http\Request $request): array
    {
        $from = trim((string) $request->input('from', ''));
        $to   = trim((string) $request->input('to', ''));

        if ($from !== '' && $to !== '') {
            try {
                $start = \Carbon\Carbon::parse($from)->startOfDay();
                $end   = \Carbon\Carbon::parse($to)->endOfDay();
                if ($start->gt($end)) [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
                return [$start, $end];
            } catch (\Throwable $e) {

            }
        }

        $periodo = (string) $request->input('periodo', '');
        return match ($periodo) {
            'diario'  => [now()->startOfDay(),   now()->endOfDay()],
            'semanal' => [now()->startOfWeek(),  now()->endOfWeek()],
            'mensal'  => [now()->startOfMonth(), now()->endOfMonth()],
            default   => [null, null],
        };
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $from = $request->get('from');
        $to   = $request->get('to');

        $leadQuery = Lead::query();
        $commissionQuery = Commission::query();
        $agendaQuery = Agenda::query();
        $taskQuery = Task::query();

        if (!in_array($user->role, ['admin', 'gestor'])) {
            $leadQuery->where('assigned_user_id', $user->id);
            $commissionQuery->where('user_id', $user->id);
            $agendaQuery->where('user_id', $user->id);
            $taskQuery->where('user_id', $user->id);
        }

        if ($from && $to) {
            $leadQuery->whereBetween('created_at', [$from, $to]);
            $commissionQuery->whereBetween('created_at', [$from, $to]);
            $agendaQuery->whereBetween('date', [$from, $to]);
            $taskQuery->whereBetween('due_date', [$from, $to]);
        }

        $funnel = [
            'leads' => (clone $leadQuery)->count(),
            'em_atendimento' => (clone $leadQuery)->where('status', 'em_atendimento')->count(),
            'agendamentos' => (clone $leadQuery)->where('status', 'agendamento')->count(),
            'visitas' => (clone $leadQuery)->where('status', 'visita')->count(),
            'negociacao' => (clone $leadQuery)->where('status', 'negociacao')->count(),
            'vendas' => (clone $leadQuery)->where('status', 'venda')->count(),
        ];

        $pendingTasks = (clone $taskQuery)
            ->where('status', 'pending')
            ->count();

        $appointmentsToday = (clone $agendaQuery)
            ->whereDate('date', now()->toDateString())
            ->count();

        $totalCommissions = (clone $commissionQuery)
            ->sum('commission_value');

        return response()->json([
            'funnel' => $funnel,
            'pending_tasks' => $pendingTasks,
            'appointments_today' => $appointmentsToday,
            'total_commissions' => $totalCommissions,
        ]);
    }
}
