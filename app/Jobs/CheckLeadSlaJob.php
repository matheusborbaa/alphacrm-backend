<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\LeadHistory;
use App\Models\Setting;
use App\Models\User;
use App\Services\LeadAssignmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fiscaliza SLA de primeira resposta.
 *
 * Pra cada lead com sla_status='pending', assigned_user_id != null e
 * sla_deadline_at no passado:
 *   1) marca sla_status='expired' e loga LeadHistory type='sla_expired';
 *   2) chama LeadAssignmentService::reassignForSla($lead), que:
 *      - pega o primeiro da fila que esteja disponível (rodízio normal);
 *      - se o escolhido for OUTRO corretor, reatribui (applyAssignment
 *        + cooldown + notificação) — o próprio service já loga 'assigned'
 *        e eventuais mudanças de etapa/subetapa;
 *      - se o ÚNICO corretor disponível for o mesmo que tinha o lead,
 *        reseta só o SLA (novo prazo) e loga 'sla_retry_same_broker';
 *      - se ninguém está disponível, o lead vira órfão (notificação
 *        pros admins via handleOrphan).
 *
 * Agendamento: routes/console.php chama esse job everyMinute()
 * ->onOneServer()->withoutOverlapping().
 */
class CheckLeadSlaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $leads = Lead::where('sla_status', 'pending')
            ->whereNotNull('assigned_user_id')
            ->whereNotNull('sla_deadline_at')
            ->where('sla_deadline_at', '<', now())
            ->get();

        if ($leads->isEmpty()) {
            return;
        }

        // Flag de configuração. Se desligada, o job só marca o lead como
        // expired + loga histórico, mas NÃO reatribui — o gestor decide
        // manualmente se devolve pra fila ou deixa com o mesmo corretor.
        $reassignEnabled = (bool) Setting::get('lead_sla_reassign_on_breach', true);

        $assignmentService = new LeadAssignmentService();

        foreach ($leads as $lead) {
            $oldCorretorId   = $lead->assigned_user_id;
            $oldCorretorName = optional(User::find($oldCorretorId))->name;

            // 1) marca expirado
            $lead->update(['sla_status' => 'expired']);

            // 2) histórico do 'sla_expired' — registro auditoria que o prazo
            //    passou antes do primeiro contato. O registro da eventual
            //    reatribuição sai do próprio LeadAssignmentService
            //    ('sla_breach_returned_to_queue' ou 'sla_breach_orphaned').
            try {
                LeadHistory::create([
                    'lead_id'     => $lead->id,
                    'user_id'     => null,
                    'type'        => 'sla_expired',
                    'from'        => $oldCorretorName,
                    'to'          => null,
                    'description' => $reassignEnabled
                        ? 'SLA de primeira resposta expirou — buscando reatribuição'
                        : 'SLA de primeira resposta expirou — lead mantido com o corretor (reatribuição automática desligada)',
                ]);
            } catch (\Throwable $e) {
                Log::warning('Falha ao gravar histórico de sla_expired', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }

            // 3) reatribui (outro corretor, mesmo corretor, ou órfão) —
            //    SÓ se a flag permitir. Quando desligada, paramos aqui:
            //    lead fica marcado 'expired' e o corretor continua com ele.
            if (!$reassignEnabled) {
                continue;
            }

            try {
                $assignmentService->reassignForSla($lead);
            } catch (\Throwable $e) {
                Log::warning('Falha ao reatribuir lead após SLA', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }
}
