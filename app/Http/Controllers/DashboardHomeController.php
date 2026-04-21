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

    public function funnel()
{
    $statuses = \App\Models\LeadStatus::query()
        ->withCount('leads') // relacionamento leads()
        ->orderBy('order')
        ->get()
        ->map(function ($status) {
            return [
                'name'      => $status->name,
                'order'     => $status->order,
                'color_hex' => $status->color_hex,
                'total'     => $status->leads_count,
            ];
        });

    return response()->json($statuses);
}

    public function index(Request $request)
    {
        $user = $request->user();

        $from = $request->get('from');
        $to   = $request->get('to');

        // =============================
        // BASE QUERY POR ROLE
        // =============================

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

        // =============================
        // FILTRO GLOBAL
        // =============================

        if ($from && $to) {
            $leadQuery->whereBetween('created_at', [$from, $to]);
            $commissionQuery->whereBetween('created_at', [$from, $to]);
            $agendaQuery->whereBetween('date', [$from, $to]);
            $taskQuery->whereBetween('due_date', [$from, $to]);
        }

        // =============================
        // FUNIL
        // =============================

        $funnel = [
            'leads' => (clone $leadQuery)->count(),
            'em_atendimento' => (clone $leadQuery)->where('status', 'em_atendimento')->count(),
            'agendamentos' => (clone $leadQuery)->where('status', 'agendamento')->count(),
            'visitas' => (clone $leadQuery)->where('status', 'visita')->count(),
            'negociacao' => (clone $leadQuery)->where('status', 'negociacao')->count(),
            'vendas' => (clone $leadQuery)->where('status', 'venda')->count(),
        ];

        // =============================
        // CARDS
        // =============================

        $pendingTasks = (clone $taskQuery)
            ->where('status', 'pending')
            ->count();

        $appointmentsToday = (clone $agendaQuery)
            ->whereDate('date', now()->toDateString())
            ->count();

        $totalCommissions = (clone $commissionQuery)
            ->sum('commission_value');

        // =============================
        // RESPONSE
        // =============================

        return response()->json([
            'funnel' => $funnel,
            'pending_tasks' => $pendingTasks,
            'appointments_today' => $appointmentsToday,
            'total_commissions' => $totalCommissions,
        ]);
    }
}
