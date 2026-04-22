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

    /* ==============================================================
     * INTERNOS
     * ============================================================== */

    /**
     * Grava assigned_user_id, SLA, e (se configurado) status inicial
     * do lead + entrada de histórico quando muda o status.
     */
    private function applyAssignment(Lead $lead, User $corretor): void
    {
        $slaMinutes     = $this->configSlaMinutes();
        $firstStatusId  = $this->configFirstStatusId();
        $oldStatusId    = $lead->status_id;

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
        if ($firstStatusId && $firstStatusId !== $oldStatusId) {
            $update['status_id']         = $firstStatusId;
            $update['status_changed_at'] = now();
        }

        $lead->update($update);
        $corretor->update(['last_lead_assigned_at' => now()]);

        // Histórico da mudança de etapa (se houve).
        if (!empty($update['status_id'])) {
            try {
                $fromName = $oldStatusId
                    ? optional(LeadStatus::find($oldStatusId))->name
                    : null;
                $toName = optional(LeadStatus::find($firstStatusId))->name;

                // user_id pode ser null — em CLI (webhook, job) não há auth.
                // O loggerDiffs ignora se não tem autor; aqui gravamos direto
                // pra não perder o evento mesmo sem autor.
                LeadHistory::create([
                    'lead_id'     => $lead->id,
                    'user_id'     => auth()->check() ? auth()->id() : null,
                    'type'        => 'status_change',
                    'from'        => $fromName,
                    'to'          => $toName,
                    'description' => 'Etapa alterada pelo rodízio',
                ]);
            } catch (\Throwable $e) {
                Log::warning('Falha ao gravar histórico de status no rodízio', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
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

    private function pickNextAvailable(): ?User
    {
        return User::where('role', 'corretor')
            ->where('active', true)
            ->where('status_corretor', 'disponivel')
            ->where(function ($q) {
                // Cooldown nulo OU expirado = elegível.
                $q->whereNull('cooldown_until')
                  ->orWhere('cooldown_until', '<=', now());
            })
            ->orderByRaw('last_lead_assigned_at IS NULL DESC')
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
}
