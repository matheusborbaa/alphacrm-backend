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

/**
 * @group Home
 *
 * Endpoint consolidado pra Home do CRM (#45).
 * Junta financeiro + gamificação num único payload pra evitar 5 requests simultâneos.
 */
class HomeController extends Controller
{
    /**
     * GET /home/summary
     *
     * Retorna:
     * - financeiro: comissões do mês (pendente, recebido), VGV ativo, vendas fechadas (count + valor), meta de comissão
     * - gamificacao: minha posição no ranking + top 5 do mês
     * - metas: progresso do mês atual (leads/atendimentos/vendas com % e valores absolutos)
     *
     * Escopo:
     * - Corretor: só dados dele.
     * - Admin/gestor: vê dados do próprio user + pode olhar todo o ranking.
     */
    public function summary(Request $request)
    {
        $user    = $request->user();

        // Sprint 3.5b — bloco Financeiro tem filtro próprio (diario/semanal/
        // mensal). O resto continua usando o mês corrente como antes — metas
        // mensais e ranking do mês não fazem sentido diário/semanal.
        $periodo = (string) $request->input('periodo', 'mensal');
        [$finStart, $finEnd] = match ($periodo) {
            'diario'  => [now()->startOfDay(),   now()->endOfDay()],
            'semanal' => [now()->startOfWeek(),  now()->endOfWeek()],
            default   => [now()->startOfMonth(), now()->endOfMonth()],
        };
        $start = now()->startOfMonth();
        $end   = now()->endOfMonth();
        $mes   = now()->month;
        $ano   = now()->year;

        /* ----------------------------------------------------
         | FINANCEIRO — escopo por user (admin/gestor enxergam
         | os próprios números também; pra visão gerencial da
         | equipe, já existe /reports/ranking).
         |---------------------------------------------------- */
        $comissQuery = Commission::where('user_id', $user->id)
            ->whereBetween('created_at', [$finStart, $finEnd]);

        $comissPendente = (clone $comissQuery)->where('status', 'pending')->sum('commission_value');
        $comissRecebido = (clone $comissQuery)->where('status', 'paid')->sum('commission_value');

        // Vendas fechadas (status 'Vendido') no período, atribuídas ao user
        $soldLeads = Lead::where('assigned_user_id', $user->id)
            ->whereBetween('status_changed_at', [$finStart, $finEnd])
            ->whereHas('status', fn($q) => $q->where('name', 'Vendido'))
            ->get(['id', 'value']);

        $vendasCount = $soldLeads->count();
        $vendasValor = (float) $soldLeads->sum('value');

        // VGV ativo: leads NÃO Vendidos/Perdidos atribuídos ao user
        $vgvAtivo = (float) Lead::where('assigned_user_id', $user->id)
            ->whereDoesntHave('status', fn($q) => $q->whereIn('name', ['Vendido', 'Perdido']))
            ->sum('value');

        /* ----------------------------------------------------
         | META DO MÊS — pega de user_metas
         |---------------------------------------------------- */
        $meta = UserMeta::where('user_id', $user->id)
            ->where('mes', $mes)
            ->where('ano', $ano)
            ->first();

        // Progresso real: leads recebidos, atendimentos completos, vendas
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

        /* ----------------------------------------------------
         | GAMIFICAÇÃO — mini ranking (top 5 + eu)
         |---------------------------------------------------- */
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

        // minha posição (1-based). Se o user logado não é corretor, posição = null
        $myPos = null;
        foreach ($rankingFull as $i => $item) {
            if ($item['user_id'] === $user->id) { $myPos = $i + 1; break; }
        }

        $top5 = $rankingFull->take(5)->map(fn($r, $i) => array_merge($r, ['position' => $i + 1]));

        /* ----------------------------------------------------
         | PENDENTES — cards da dashboard
         |----------------------------------------------------
         | atendimentos: leads ATRIBUÍDOS ao user que ainda não
         |   tiveram primeiro contato registrado. O SLA rastreia
         |   isso via sla_status: 'pending' (dentro do prazo) e
         |   'expired' (passou do prazo, ainda sem contato) — ambos
         |   contam como pendente. 'met' = primeiro contato feito;
         |   'na' = SLA desativado; esses não contam.
         |
         | tarefas: appointments com type='task' + overdue scope
         |   (completed_at NULL AND due_at < now). Mostra só as do
         |   user logado pra manter consistência com os outros cards.
         |---------------------------------------------------- */
        $atendimentosPendentes = Lead::where('assigned_user_id', $user->id)
            ->whereIn('sla_status', ['pending', 'expired'])
            ->count();

        // Sprint 3.5+ — "Tarefas Pendentes" agora conta TODAS as tarefas
        // abertas do user (pendentes + atrasadas), batendo com a aba
        // Tarefa da Agenda. Antes usava só o scope overdue() (due_at < now),
        // ignorando a tarefa pendente que ainda não venceu — por isso no
        // card da home aparecia 0 mesmo tendo 1 pendente e 1 atrasada.
        $tarefasPendentes = Appointment::tasks()
            ->where('user_id', $user->id)
            ->whereNull('completed_at')
            ->count();

        /* ----------------------------------------------------
         | RESPONSE
         |---------------------------------------------------- */
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
                'atendimentos' => $atendimentosPendentes,
                'tarefas'      => $tarefasPendentes,
            ],
            'gamificacao' => [
                'minha_posicao' => $myPos,
                'total_corretores' => $rankingFull->count(),
                'top5' => $top5,
            ],
        ]);
    }

    /**
     * GET /dashboard/next-commissions
     *
     * Sprint 3.5b — alimenta a lista "Minhas Próximas Comissões" no bloco
     * Financeiro. Retorna array ordenado por data prevista (expected_payment_date)
     * ou, na falta dela, created_at + 30 dias.
     *
     * Payload por item:
     *   - id
     *   - expected_date  (YYYY-MM-DD)
     *   - client_name    (nome do lead)
     *   - empreendimento_name
     *   - vgv            (sale_value da venda — bate com o mockup da pág 6)
     *   - commission_value
     *   - status         'paid' | 'partial' | 'pending'
     */
    public function nextCommissions(Request $request)
    {
        $user    = $request->user();
        $periodo = (string) $request->input('periodo', 'mensal');

        // Mesmo esquema de períodos do summary. Corretor vê só as dele.
        [$start, $end] = match ($periodo) {
            'diario'  => [now()->startOfDay(),   now()->endOfDay()],
            'semanal' => [now()->startOfWeek(),  now()->endOfWeek()],
            default   => [now()->startOfMonth(), now()->endOfMonth()],
        };

        $rows = Commission::where('user_id', $user->id)
            ->with([
                'lead:id,name,empreendimento_id',
                'lead.empreendimento:id,name',
            ])
            ->where(function ($q) use ($start, $end) {
                // Cobre comissões com expected_date no período OU comissões
                // criadas no período (fallback quando a imobiliária ainda não
                // definiu expected_payment_date nos registros antigos).
                $q->whereBetween('expected_payment_date', [$start, $end])
                  ->orWhere(function ($q2) use ($start, $end) {
                      $q2->whereNull('expected_payment_date')
                         ->whereBetween('created_at', [$start, $end]);
                  });
            })
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
            ];
        }));
    }
}
