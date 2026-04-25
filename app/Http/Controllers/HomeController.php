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
        // Sprint H1.1 — agora aceita também range customizado (from/to),
        // corretor_id (admin/gestor) e empreendimento_id pra filtrar o
        // bloco financeiro com mais granularidade.
        [$finStart, $finEnd] = $this->resolveFinancePeriod($request);
        $finUserId           = $this->resolveFinanceUserId($request, $user);
        $empreendimentoId    = $this->resolveEmpreendimentoFilter($request);

        $start = now()->startOfMonth();
        $end   = now()->endOfMonth();
        $mes   = now()->month;
        $ano   = now()->year;

        /* ----------------------------------------------------
         | FINANCEIRO — escopo padrão por user logado, mas admin/gestor
         | pode trocar via ?corretor_id=X (specific) ou =all (agrega
         | toda a equipe). Quando finUserId é null = sem filtro de user.
         | Empreendimento opcional aplica nos 4 KPIs sempre.
         |---------------------------------------------------- */
        $comissQuery = Commission::query()
            ->whereBetween('created_at', [$finStart, $finEnd]);

        if ($finUserId !== null) {
            $comissQuery->where('user_id', $finUserId);
        }

        if ($empreendimentoId) {
            // Comissão é polimórfica via lead → empreendimento. Usa whereHas
            // pra não precisar de JOIN duplicado em cada SUM (clones abaixo).
            $comissQuery->whereHas('lead', fn($q) => $q->where('empreendimento_id', $empreendimentoId));
        }

        $comissPendente = (clone $comissQuery)->where('status', 'pending')->sum('commission_value');
        $comissRecebido = (clone $comissQuery)->where('status', 'paid')->sum('commission_value');

        // Vendas fechadas (status 'Vendido') no período, atribuídas ao user
        // (ou todos os users se finUserId for null).
        $soldLeadsQ = Lead::query()
            ->whereBetween('status_changed_at', [$finStart, $finEnd])
            ->whereHas('status', fn($q) => $q->where('name', 'Vendido'));
        if ($finUserId !== null) {
            $soldLeadsQ->where('assigned_user_id', $finUserId);
        }
        if ($empreendimentoId) {
            $soldLeadsQ->where('empreendimento_id', $empreendimentoId);
        }
        $soldLeads   = $soldLeadsQ->get(['id', 'value']);
        $vendasCount = $soldLeads->count();
        $vendasValor = (float) $soldLeads->sum('value');

        // VGV ativo: leads NÃO Vendidos/Perdidos. Quando agregando todos,
        // inclui leads sem corretor atribuído também (assigned_user_id null) —
        // faz sentido pro "VGV total da carteira da imobiliária".
        $vgvQ = Lead::query()
            ->whereDoesntHave('status', fn($q) => $q->whereIn('name', ['Vendido', 'Perdido']));
        if ($finUserId !== null) {
            $vgvQ->where('assigned_user_id', $finUserId);
        }
        if ($empreendimentoId) {
            $vgvQ->where('empreendimento_id', $empreendimentoId);
        }
        $vgvAtivo = (float) $vgvQ->sum('value');

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

        // Sprint H1.2 — Follow-ups atrasados: tarefas com kind=followup,
        // due_at no passado, ainda não concluídas. Subset das "tarefas
        // pendentes" filtrado por kind. Usado no novo mini-card "Follow-ups
        // atrasados" da Home pra dar visibilidade extra ao tipo mais
        // sensível (sem follow-up, lead esfria).
        $followupsAtrasados = Appointment::tasks()
            ->where('user_id', $user->id)
            ->where('task_kind', Appointment::KIND_FOLLOWUP)
            ->overdue()
            ->count();

        // Sprint H1.2 — Leads sem tarefa agendada: leads atribuídos ao user
        // que não têm NENHUMA appointment aberta no futuro (ou hoje). Usa
        // NOT EXISTS via whereDoesntHave pra evitar JOIN+DISTINCT pesado.
        // Não filtra por status (vendido/descartado) — fica simples e honesto;
        // quando o Sprint H1.4 adicionar is_terminal em lead_statuses, dá
        // pra refinar aqui pra ignorar leads em etapa terminal.
        $leadsSemTarefa = Lead::where('assigned_user_id', $user->id)
            ->whereDoesntHave('appointments', function ($q) {
                $q->whereNull('completed_at')
                  ->whereNotNull('due_at')
                  ->where('due_at', '>=', now()->startOfDay());
            })
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
                'atendimentos'        => $atendimentosPendentes,
                'tarefas'             => $tarefasPendentes,
                // Sprint H1.2 — 2 contadores novos pros mini-cards extras.
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
        $user = $request->user();

        // Sprint H1.1 — mesmo conjunto de filtros que o summary usa, via
        // helpers compartilhados pra garantir comportamento idêntico (range
        // customizado, troca de corretor por admin, filtro empreendimento).
        [$start, $end]    = $this->resolveFinancePeriod($request);
        $finUserId        = $this->resolveFinanceUserId($request, $user);
        $empreendimentoId = $this->resolveEmpreendimentoFilter($request);

        $query = Commission::query()
            ->with([
                'lead:id,name,empreendimento_id',
                'lead.empreendimento:id,name',
                // Sprint H1.1b — nome do corretor; útil quando admin/gestor
                // tá olhando 'Todos os corretores' pra saber quem é o dono
                // de cada linha. Eager-load barato (só id+name).
                'user:id,name',
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
            });

        // Sprint H1.1b — quando admin/gestor escolhe "Todos os corretores"
        // ($finUserId é null), pula o where de user_id pra retornar a
        // lista agregada da equipe inteira.
        if ($finUserId !== null) {
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
                // Sprint H1.1b — nome do corretor dono da comissão. Frontend
                // só renderiza essa coluna quando filtro = 'all'; nos outros
                // casos é redundante (todas as linhas teriam o mesmo nome).
                'corretor_name'        => $c->user?->name,
            ];
        }));
    }

    /* ==========================================================
     * Sprint H1.1 — Helpers de filtro do bloco Financeiro.
     * Compartilhados entre summary() e nextCommissions() pra
     * comportamento idêntico nos dois endpoints.
     * ======================================================== */

    /**
     * Resolve [start, end] do bloco Financeiro. Aceita 3 modos:
     *   1) ?from=YYYY-MM-DD&to=YYYY-MM-DD  → range customizado
     *   2) ?periodo=diario|semanal|mensal  → atalhos comuns
     *   3) sem nada                         → mensal (default histórico)
     *
     * `to` no parse vira endOfDay pra incluir o dia inteiro. Se vier um
     * só (só from OU só to), trata como mensal — meia-bagunça não vale.
     * Retorna sempre Carbon instances pra usar em whereBetween.
     */
    private function resolveFinancePeriod(Request $request): array
    {
        $from = trim((string) $request->input('from', ''));
        $to   = trim((string) $request->input('to', ''));

        if ($from !== '' && $to !== '') {
            try {
                $start = Carbon::parse($from)->startOfDay();
                $end   = Carbon::parse($to)->endOfDay();
                // Se inverteram from/to, conserta automaticamente em vez de
                // explodir — UX mais tolerante.
                if ($start->gt($end)) [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
                return [$start, $end];
            } catch (\Throwable $e) {
                // Datas malformadas → cai no padrão mensal. Não vale a pena
                // 422 pra um filtro auxiliar.
            }
        }

        $periodo = (string) $request->input('periodo', 'mensal');
        return match ($periodo) {
            'diario'  => [now()->startOfDay(),   now()->endOfDay()],
            'semanal' => [now()->startOfWeek(),  now()->endOfWeek()],
            default   => [now()->startOfMonth(), now()->endOfMonth()],
        };
    }

    /**
     * Decide qual user_id alimenta os números do bloco Financeiro.
     *
     * Retorno:
     *   - int  → filtra `user_id` por esse valor específico (caso normal)
     *   - null → "todos os corretores" (admin/gestor escolheu agregar a
     *            equipe inteira); chamadores devem PULAR o where('user_id')
     *
     * Regra:
     *   - Corretor comum SEMPRE vê só os próprios dados — `corretor_id`
     *     vindo na query string é ignorado pra ele.
     *   - Admin/gestor pode trocar pra outro user OU pedir 'all' pra
     *     agregar toda a equipe num único conjunto de números.
     *   - Vazio / inválido → cai no próprio user (no-op silencioso).
     */
    private function resolveFinanceUserId(Request $request, $user): ?int
    {
        $myId = (int) $user->id;
        $role = strtolower(trim((string) ($user->role ?? '')));
        if (!in_array($role, ['admin', 'gestor'], true)) {
            return $myId;
        }

        $raw = (string) $request->input('corretor_id', '');
        if ($raw === 'all') {
            return null; // sem filtro de user — agrega tudo
        }

        $corretor = (int) $raw;
        return $corretor > 0 ? $corretor : $myId;
    }

    /**
     * Resolve filtro opcional por empreendimento_id. Retorna 0 se ausente
     * ou inválido — chamadores devem checar com `if ($id)` antes de aplicar.
     * Aceito pra qualquer role.
     */
    private function resolveEmpreendimentoFilter(Request $request): int
    {
        $id = (int) $request->input('empreendimento_id', 0);
        return $id > 0 ? $id : 0;
    }
}
