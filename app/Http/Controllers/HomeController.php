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
        $start   = now()->startOfMonth();
        $end     = now()->endOfMonth();
        $mes     = now()->month;
        $ano     = now()->year;

        /* ----------------------------------------------------
         | FINANCEIRO — escopo por user (admin/gestor enxergam
         | os próprios números também; pra visão gerencial da
         | equipe, já existe /reports/ranking).
         |---------------------------------------------------- */
        $comissQuery = Commission::where('user_id', $user->id)
            ->whereBetween('created_at', [$start, $end]);

        $comissPendente = (clone $comissQuery)->where('status', 'pending')->sum('commission_value');
        $comissRecebido = (clone $comissQuery)->where('status', 'paid')->sum('commission_value');

        // Vendas fechadas (status 'Vendido') do mês, atribuídas ao user
        $soldLeads = Lead::where('assigned_user_id', $user->id)
            ->whereBetween('status_changed_at', [$start, $end])
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
            'gamificacao' => [
                'minha_posicao' => $myPos,
                'total_corretores' => $rankingFull->count(),
                'top5' => $top5,
            ],
        ]);
    }
}
