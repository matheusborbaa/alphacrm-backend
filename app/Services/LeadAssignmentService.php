<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadHistory;
use App\Models\LeadStatus;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\LeadAssignedNotification;
use App\Notifications\OrphanLeadNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Distribui leads novos pelos corretores via rodízio simples.
 *
 * Regras atuais:
 *   - Só participa do rodízio quem é ativo (users.active = true),
 *     tem role 'corretor', status_corretor = 'disponivel' E
 *     não está em cooldown (cooldown_until no passado ou nulo).
 *     "Ocupado", "offline" ou cooldown ativo ficam de fora.
 *   - Ordem: quem foi atribuído há mais tempo recebe primeiro
 *     (orderBy last_lead_assigned_at ASC, NULL primeiro).
 *   - Se NINGUÉM está disponível, o lead vira "órfão": fica sem
 *     assigned_user_id e avisamos os admins. Quando um corretor
 *     voltar pro status 'disponivel' (manual ou por fim de cooldown),
 *     tryClaimNextOrphan() pega o órfão mais antigo e atribui pra ele.
 *
 * Configurações (Settings):
 *   - lead_cooldown_enabled (bool, default false): ativa cooldown pós-lead.
 *   - lead_cooldown_minutes (int, default 2): minutos de cooldown. 0 desativa.
 *   - lead_sla_enabled (bool, default true): ativa SLA automático.
 *   - lead_sla_minutes (int, default 15): minutos de SLA. 0 desativa.
 *   - lead_first_status_id (int|null): etapa pra qual o lead vai quando
 *     cair no rodízio (ex: "Aguardando atendimento"). Null = não mexe.
 *
 * Observação: o controle de disponibilidade persistente fica na
 * coluna users.status_corretor + users.cooldown_until. O dropdown do
 * sidebar no frontend sincroniza status via POST /users/me/status
 * (UserController@updateStatus).
 */
class LeadAssignmentService
{
    /**
     * Atribui um lead ao próximo corretor disponível no rodízio.
     * Retorna o User escolhido, ou null se o lead ficou órfão
     * (nenhum corretor disponível).
     */
    public function assign(Lead $lead): ?User
    {
        $corretor = $this->pickNextAvailable();

        if (!$corretor) {
            $this->handleOrphan($lead);
            return null;
        }

        $this->applyAssignment($lead, $corretor);
        $this->applyCooldown($corretor);

        $this->notifyBroker($corretor, $lead);

        return $corretor;
    }

    /**
     * Quando um corretor fica 'disponivel', chama esse método pra tentar
     * pegar o lead órfão mais antigo e atribuir pra ele. Retorna o Lead
     * atribuído ou null se não há órfãos.
     *
     * Isso fecha a porta aberta deixada pelo handleOrphan() — assim que
     * alguém vira disponível, o sistema auto-distribui o que ficou pendente.
     */
    public function tryClaimNextOrphan(User $corretor): ?Lead
    {
        // Segurança: só reclama pra quem realmente pode receber lead.
        if (!$this->isEligible($corretor)) return null;

        // Transação + lock pra evitar que dois corretores virando 'disponivel'
        // ao mesmo tempo pipem o mesmo órfão.
        $lead = DB::transaction(function () use ($corretor) {
            $lead = Lead::whereNull('assigned_user_id')
                ->orderBy('created_at', 'asc')
                ->lockForUpdate()
                ->first();

            if (!$lead) return null;

            $this->applyAssignment($lead, $corretor);

            return $lead->fresh();
        });

        if (!$lead) return null;

        // Cooldown e notificação FORA da transação — se o notify falhar,
        // não quero desfazer a atribuição. Idem cooldown (é independente).
        $this->applyCooldown($corretor);
        $this->notifyBroker($corretor, $lead);

        return $lead;
    }

    /**
     * Reatribuição por expiração de SLA.
     *
     * Regras (decididas com o produto):
     *   - Pega o primeiro da fila disponível (orderBy last_lead_assigned_at).
     *   - Se o escolhido é OUTRO corretor: reatribui (applyAssignment +
     *     cooldown + notificação) — o applyAssignment já loga 'assigned'
     *     e eventuais status_change/substatus_change.
     *   - Se o ÚNICO disponível é o mesmo corretor atual: mantém ele,
     *     reseta só sla_status/sla_deadline_at (novo prazo) e loga
     *     'sla_retry_same_broker'. Ele tem mais uma chance dentro do
     *     novo prazo — não ficamos "tirando e devolvendo" o lead dele.
     *   - Se NINGUÉM está disponível (nem o corretor atual): lead vira
     *     órfão (handleOrphan) — mantém o fluxo já existente.
     *
     * Retorna o User responsável pelo lead ao final, ou null se ficou órfão.
     */
    public function reassignForSla(Lead $lead): ?User
    {
        $currentId   = $lead->assigned_user_id;
        $current     = $currentId ? User::find($currentId) : null;
        $currentName = $current?->name;

        // Exclui o corretor atual da fila pra esse ciclo — ele FALHOU o SLA,
        // não pode receber o lead de volta imediatamente. Se ele for o único
        // elegível, cai no branch "só ele está disponível" e mantemos com
        // novo prazo (conforme política "tirar só se houver outro").
        $next = $this->pickNextAvailable($currentId);

        // Ninguém além do corretor atual disponível: mantém com ele +
        // novo prazo (política "tirar só se houver outro").
        if (!$next) {
            if ($current && $this->isEligible($current)) {
                $this->resetSlaOnly($lead, $current);
                return $current;
            }
            // Nem o atual tá elegível (offline/inativo) e ninguém mais disponível:
            // vai pra fila de órfãos.
            $this->logReturnedToQueue($lead, $currentName, 'orphan');
            $this->handleOrphan($lead);
            return null;
        }

        // Outro corretor disponível: reatribui. Usa preserveStage=true pra
        // NÃO forçar lead_first_status_id (decisão de produto: lead mantém
        // a etapa atual quando sai do corretor por breach de SLA).
        $this->logReturnedToQueue($lead, $currentName, 'reassigned', $next->name);
        $this->applyAssignment($lead, $next, true);
        $this->applyCooldown($next);
        $this->notifyBroker($next, $lead);
        return $next;
    }

    /**
     * Loga histórico explícito de "lead devolvido à fila por SLA vencido",
     * detalhando de qual corretor saiu e pra onde foi (outro corretor ou
     * fila de órfãos). Complementa o 'sla_expired' (genérico) com um evento
     * direcional pra auditoria.
     */
    private function logReturnedToQueue(Lead $lead, ?string $fromName, string $mode, ?string $toName = null): void
    {
        try {
            LeadHistory::create([
                'lead_id'     => $lead->id,
                'user_id'     => null,
                'type'        => $mode === 'orphan'
                    ? 'sla_breach_orphaned'
                    : 'sla_breach_returned_to_queue',
                'from'        => $fromName,
                'to'          => $toName,
                'description' => $mode === 'orphan'
                    ? 'Lead removido do corretor por SLA vencido e devolvido à fila (nenhum corretor disponível — órfão)'
                    : 'Lead removido do corretor por SLA vencido e reatribuído ao próximo da fila',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Falha ao gravar histórico de SLA returned_to_queue', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reseta só SLA do lead pra dar "mais uma chance" pro corretor atual.
     * Loga LeadHistory type='sla_retry_same_broker'.
     */
    private function resetSlaOnly(Lead $lead, User $current): void
    {
        $slaMinutes = $this->configSlaMinutes();
        $lead->update([
            'sla_status'      => 'pending',
            'sla_deadline_at' => $slaMinutes > 0 ? now()->addMinutes($slaMinutes) : null,
        ]);

        try {
            LeadHistory::create([
                'lead_id'     => $lead->id,
                'user_id'     => null,
                'type'        => 'sla_retry_same_broker',
                'from'        => null,
                'to'          => $current->name,
                'description' => 'SLA expirou; como era o único corretor disponível, manteve a atribuição e novo prazo foi aplicado',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Falha ao gravar histórico de sla_retry_same_broker', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /* ==============================================================
     * INTERNOS
     * ============================================================== */

    /**
     * Grava assigned_user_id, SLA, e (se configurado) status+subetapa inicial
     * do lead + entradas de histórico pra:
     *   - atribuição (sempre — from/to corretor)
     *   - mudança de etapa/subetapa (se houve)
     *
     * @param bool $preserveStage Quando true, NÃO aplica
     *   lead_first_status_id/substatus_id — usado pelo fluxo de reassign
     *   por SLA pra preservar o contexto da etapa atual (ex: lead já tava
     *   em "Em atendimento" e o SLA estourou → o novo corretor continua
     *   em "Em atendimento", não volta pra "Aguardando atendimento").
     */
    private function applyAssignment(Lead $lead, User $corretor, bool $preserveStage = false): void
    {
        $slaMinutes       = $this->configSlaMinutes();
        $firstStatusId    = $preserveStage ? null : $this->configFirstStatusId();
        $firstSubstatusId = $preserveStage ? null : $this->configFirstSubstatusId();

        $oldStatusId    = $lead->status_id;
        $oldSubstatusId = $lead->lead_substatus_id;
        $oldAssigned    = $lead->assigned_user_id;

        $update = [
            'assigned_user_id' => $corretor->id,
            'assigned_at'      => now(),
            'sla_status'       => 'pending',
            // SLA: só grava deadline se o SLA estiver habilitado.
            // Se 0/desabilitado, deixamos NULL pra não acionar o job de breach.
            'sla_deadline_at'  => $slaMinutes > 0 ? now()->addMinutes($slaMinutes) : null,
        ];

        // Se admin configurou um status inicial pro rodízio (ex: "Aguardando
        // atendimento"), mudamos pra ele. Só mexe se o status realmente
        // muda — evita histórico ruidoso quando já está no status certo.
        $statusChanged = false;
        if ($firstStatusId && $firstStatusId !== $oldStatusId) {
            $update['status_id']         = $firstStatusId;
            $update['status_changed_at'] = now();
            $statusChanged = true;
        }

        // Subetapa inicial (opcional). Só aplica se a subetapa configurada
        // pertencer à etapa atual (ou à nova etapa, se também mudou).
        // Caso contrário, zera — evita inconsistência de hierarquia.
        $substatusChanged = false;
        if ($firstSubstatusId) {
            $targetStatusId = $update['status_id'] ?? $oldStatusId;
            if ($this->substatusBelongsToStatus($firstSubstatusId, $targetStatusId)) {
                if ($firstSubstatusId !== $oldSubstatusId) {
                    $update['lead_substatus_id'] = $firstSubstatusId;
                    $substatusChanged = true;
                }
            }
        }

        $lead->update($update);
        $corretor->update(['last_lead_assigned_at' => now()]);

        // ---- HISTÓRICOS -----------------------------------------------
        $uid = auth()->check() ? auth()->id() : null;

        try {
            // 1) Atribuição (sempre grava — é o evento-raiz).
            $fromCorretor = $oldAssigned
                ? optional(User::find($oldAssigned))->name
                : null;
            LeadHistory::create([
                'lead_id'     => $lead->id,
                'user_id'     => $uid,
                'type'        => 'assigned',
                'from'        => $fromCorretor,
                'to'          => $corretor->name,
                'description' => $oldAssigned
                    ? 'Lead reatribuído via rodízio'
                    : 'Lead atribuído via rodízio',
            ]);

            // 2) Mudança de etapa (se houve).
            if ($statusChanged) {
                $fromName = $oldStatusId
                    ? optional(LeadStatus::find($oldStatusId))->name
                    : null;
                $toName = optional(LeadStatus::find($firstStatusId))->name;
                LeadHistory::create([
                    'lead_id'     => $lead->id,
                    'user_id'     => $uid,
                    'type'        => 'status_change',
                    'from'        => $fromName,
                    'to'          => $toName,
                    'description' => 'Etapa alterada pelo rodízio',
                ]);
            }

            // 3) Mudança de subetapa (se houve).
            if ($substatusChanged) {
                $fromSub = $oldSubstatusId
                    ? optional(\App\Models\LeadSubstatus::find($oldSubstatusId))->name
                    : null;
                $toSub = optional(\App\Models\LeadSubstatus::find($firstSubstatusId))->name;
                LeadHistory::create([
                    'lead_id'     => $lead->id,
                    'user_id'     => $uid,
                    'type'        => 'substatus_change',
                    'from'        => $fromSub,
                    'to'          => $toSub,
                    'description' => 'Subetapa alterada pelo rodízio',
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Falha ao gravar histórico de atribuição', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Verifica se a subetapa pertence à etapa. Retorna true se não há nada
     * pra validar (null ou parent correto). Previne inconsistência quando
     * admin configura uma subetapa que não pertence à etapa inicial.
     */
    private function substatusBelongsToStatus(?int $substatusId, ?int $statusId): bool
    {
        if (!$substatusId) return true;
        if (!$statusId) return false;

        $sub = \App\Models\LeadSubstatus::find($substatusId);
        return $sub && (int) $sub->lead_status_id === (int) $statusId;
    }

    /**
     * Se o cooldown estiver habilitado e > 0 min, marca o corretor como
     * 'ocupado' e grava cooldown_until = now() + X min.
     *
     * O frontend (sidebar.js) trava o select do corretor enquanto esse
     * timestamp estiver no futuro. O command `leads:release-cooldowns`
     * libera automaticamente quando expira.
     */
    private function applyCooldown(User $corretor): void
    {
        $minutes = $this->configCooldownMinutes();
        if ($minutes <= 0) return;

        $corretor->update([
            'status_corretor' => 'ocupado',
            'cooldown_until'  => now()->addMinutes($minutes),
        ]);
    }

    /**
     * Corretor ativo, role=corretor, status=disponivel E sem cooldown ativo.
     */
    private function isEligible(User $u): bool
    {
        if (!(bool) $u->active) return false;
        if (strtolower((string) $u->role) !== 'corretor') return false;
        if (strtolower((string) ($u->status_corretor ?? '')) !== 'disponivel') return false;

        // Cooldown: se tem timestamp no futuro, não é elegível.
        if ($u->cooldown_until && $u->cooldown_until->isFuture()) return false;

        return true;
    }

    /**
     * @param int|null $excludeUserId User a ser ignorado na seleção (ex:
     *   corretor que acabou de falhar o SLA — não pode receber de volta
     *   imediatamente). Se o único elegível for o excluído, retorna null.
     */
    private function pickNextAvailable(?int $excludeUserId = null): ?User
    {
        $q = User::where('role', 'corretor')
            ->where('active', true)
            ->where('status_corretor', 'disponivel')
            ->where(function ($q) {
                // Cooldown nulo OU expirado = elegível.
                $q->whereNull('cooldown_until')
                  ->orWhere('cooldown_until', '<=', now());
            });

        if ($excludeUserId) {
            $q->where('id', '!=', $excludeUserId);
        }

        return $q->orderByRaw('last_lead_assigned_at IS NULL DESC')
            ->orderBy('last_lead_assigned_at', 'asc')
            ->first();
    }

    /**
     * Ninguém disponível pra receber. Lead fica sem corretor (órfão)
     * e os admins recebem uma notificação pra distribuir manualmente
     * ou aguardar um corretor voltar pra 'disponivel'.
     */
    private function handleOrphan(Lead $lead): void
    {
        // Zera dados de atribuição pra deixar claro que tá sem dono.
        // Mantemos o status_id original — o lead "aguardando atendimento"
        // só faz sentido quando efetivamente tem dono atribuído.
        $lead->update([
            'assigned_user_id' => null,
            'assigned_at'      => null,
            'sla_deadline_at'  => null,
            'sla_status'       => 'pending',
        ]);

        try {
            $admins = User::where('role', 'admin')
                ->where('active', true)
                ->get();

            foreach ($admins as $admin) {
                $admin->notify(new OrphanLeadNotification($lead->fresh()));
            }
        } catch (\Throwable $e) {
            Log::warning('Falha ao notificar admins de lead órfão', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function notifyBroker(User $corretor, Lead $lead): void
    {
        try {
            $corretor->notify(new LeadAssignedNotification($lead->fresh()));
        } catch (\Throwable $e) {
            Log::warning('Falha ao notificar corretor de novo lead', [
                'lead_id' => $lead->id,
                'user_id' => $corretor->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /* ==============================================================
     * CONFIG HELPERS
     * ============================================================== */

    private function configCooldownMinutes(): int
    {
        $enabled = (bool) Setting::get('lead_cooldown_enabled', false);
        if (!$enabled) return 0;
        $m = (int) Setting::get('lead_cooldown_minutes', 2);
        return max(0, $m);
    }

    private function configSlaMinutes(): int
    {
        $enabled = (bool) Setting::get('lead_sla_enabled', true);
        if (!$enabled) return 0;
        $m = (int) Setting::get('lead_sla_minutes', 15);
        return max(0, $m);
    }

    private function configFirstStatusId(): ?int
    {
        $v = Setting::get('lead_first_status_id', null);
        return is_numeric($v) ? (int) $v : null;
    }

    private function configFirstSubstatusId(): ?int
    {
        $v = Setting::get('lead_first_substatus_id', null);
        return is_numeric($v) ? (int) $v : null;
    }
}
