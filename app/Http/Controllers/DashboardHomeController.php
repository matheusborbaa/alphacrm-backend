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

    /**
     * Sprint H1.4 — Funil reformulado.
     *
     * Filtra etapas terminais (Vendido/Perdido/Descartado/etc) e calcula
     * 2 métricas extras por etapa:
     *
     *   - conv_rate_pct  → percentual de conversão da etapa anterior pra
     *                      essa. Etapa 1 = 100% (não tem anterior).
     *                      Fórmula: total_etapa / total_etapa_anterior * 100
     *
     *   - avg_days       → tempo médio (em dias) que leads ficaram nessa
     *                      etapa antes de mudar pra outra. Calculado a
     *                      partir do lead_histories (type='status_change',
     *                      from/to com status_id stringificado).
     *                      Leads que ainda estão na etapa não contam —
     *                      a métrica é "tempo até sair", não "tempo até agora".
     *                      null = sem dados ainda (etapa nunca teve saída).
     *
     * Retorno: array de etapas em ordem cronológica, cada uma com name,
     * order, color_hex, total, conv_rate_pct e avg_days. Frontend usa
     * tudo pra renderizar o funil novo (números dentro, descrição+%+tempo
     * fora).
     */
    public function funnel(Request $request)
    {
        // Sprint H1.4g — filtro próprio do funil. Aceita periodo (chips) ou
        // from/to (range customizado). Sem filtro = mostra TODO o pipeline.
        // Filtra leads pelo created_at no período: "leads criados nesse
        // intervalo, agrupados por etapa atual onde estão agora".
        [$start, $end] = $this->resolveFunnelPeriod($request);

        $statuses = \App\Models\LeadStatus::query()
            ->where('is_terminal', false)         // tira terminais do funil
            ->withCount(['leads' => function ($q) use ($start, $end) {
                if ($start && $end) {
                    $q->whereBetween('created_at', [$start, $end]);
                }
            }])
            ->orderBy('order')
            ->get(['id', 'name', 'order', 'color_hex']);

        if ($statuses->isEmpty()) {
            return response()->json([]);
        }

        // Calcula tempo médio em cada etapa de uma vez (uma única passada
        // sobre o lead_histories). Mais barato que N queries por etapa.
        $avgDaysByStatus = $this->computeAvgDaysPerStatus(
            $statuses->pluck('id')->all()
        );

        // Monta resposta com taxa de conversão acumulada (cada etapa
        // comparada à imediatamente anterior). A primeira etapa fica em
        // 100% por definição — é o ponto de entrada do funil.
        $previousTotal = null;
        $payload = $statuses->map(function ($status) use (&$previousTotal, $avgDaysByStatus) {
            $total = (int) $status->leads_count;
            $convPct = ($previousTotal === null)
                ? 100.0
                : ($previousTotal > 0 ? round(($total / $previousTotal) * 100, 1) : 0.0);
            $previousTotal = $total;

            return [
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

    /**
     * Sprint H1.4 — Calcula o tempo médio (em dias) que leads passaram em
     * cada uma das etapas listadas, usando o histórico de mudanças.
     *
     * Algoritmo (uma passada O(N) por lead_histories):
     *   1. Busca todas as transições type='status_change' ordenadas por
     *      lead_id + created_at.
     *   2. Pra cada lead, mantém em memória a "etapa atual" e quando
     *      entrou nela. Quando vê a próxima transição, calcula o diff
     *      em dias e acumula no contador da etapa que SAIU.
     *   3. Leads que estão na etapa atual (sem próxima transição) NÃO
     *      contam — a métrica é "tempo até sair".
     *
     * Limita a 90 dias retroativos pra não pegar histórico antigo demais
     * (lead que mofou em 2024 não distorce o tempo médio atual).
     *
     * @param  array<int>  $statusIds  IDs das etapas que importam (filtra
     *                                 cálculo pra ignorar terminais já fora
     *                                 do funil; economiza memória).
     * @return array<int, float>       status_id => média em dias (1 casa)
     */
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
        $sumDays   = []; // status_id => soma dias
        $countObs  = []; // status_id => quantidade de saídas
        $entryByLead = []; // lead_id => ['status_id' => int, 'at' => Carbon]

        foreach ($transitions as $t) {
            $leadId = (int) $t->lead_id;
            $fromId = (int) $t->from;
            $toId   = (int) $t->to;

            // Se já tinha entrada registrada pra esse lead E o "from" da
            // transição atual bate com a etapa em que o lead estava → conta
            // o tempo que ficou nela.
            if (isset($entryByLead[$leadId]) &&
                $entryByLead[$leadId]['status_id'] === $fromId &&
                isset($statusIdSet[$fromId])) {
                $days = $entryByLead[$leadId]['at']->diffInDays($t->created_at);
                $sumDays[$fromId]  = ($sumDays[$fromId]  ?? 0) + $days;
                $countObs[$fromId] = ($countObs[$fromId] ?? 0) + 1;
            }

            // Atualiza pra próxima iteração: lead entrou em $toId agora.
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

    /**
     * Sprint H1.4g — Resolve [start, end] do filtro próprio do Funil.
     * Mesma estrutura do resolveFinancePeriod do HomeController, mas
     * isolado aqui pra não criar dependência cruzada entre controllers.
     *
     * Modos aceitos:
     *   1) ?from=YYYY-MM-DD&to=YYYY-MM-DD → range customizado
     *   2) ?periodo=diario|semanal|mensal → atalhos
     *   3) sem nada                       → null/null = sem filtro (todo o pipeline)
     *
     * Retorna [null, null] quando não tem filtro pra o caller pular o
     * whereBetween e contar todos os leads.
     */
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
                // Datas inválidas → sem filtro
            }
        }

        $periodo = (string) $request->input('periodo', '');
        return match ($periodo) {
            'diario'  => [now()->startOfDay(),   now()->endOfDay()],
            'semanal' => [now()->startOfWeek(),  now()->endOfWeek()],
            'mensal'  => [now()->startOfMonth(), now()->endOfMonth()],
            default   => [null, null],   // sem filtro = pipeline inteiro
        };
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
