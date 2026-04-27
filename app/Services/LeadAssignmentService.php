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

class LeadAssignmentService
{

    public function assign(Lead $lead): ?User
    {

        $corretor = $this->pickNextAvailable(null, $lead->empreendimento_id);

        if (!$corretor) {
            $this->handleOrphan($lead);
            return null;
        }

        $this->applyAssignment($lead, $corretor);
        $this->applyCooldown($corretor);

        $this->notifyBroker($corretor, $lead);

        return $corretor;
    }

    public function tryClaimNextOrphan(User $corretor): ?Lead
    {

        if (!$this->isEligible($corretor)) return null;

        $accessibleEmpIds = $corretor->accessibleEmpreendimentoIds()->all();

        $lead = DB::transaction(function () use ($corretor, $accessibleEmpIds) {
            $q = Lead::whereNull('assigned_user_id');

            if (!empty($accessibleEmpIds)) {
                $q->where(function ($q) use ($accessibleEmpIds) {
                    $q->whereNull('empreendimento_id')
                      ->orWhereIn('empreendimento_id', $accessibleEmpIds);
                });
            } else {

                $q->whereNull('empreendimento_id');
            }

            $lead = $q->orderBy('created_at', 'asc')
                ->lockForUpdate()
                ->first();

            if (!$lead) return null;

            $this->applyAssignment($lead, $corretor);

            return $lead->fresh();
        });

        if (!$lead) return null;

        $this->applyCooldown($corretor);
        $this->notifyBroker($corretor, $lead);

        return $lead;
    }

    public function reassignForSla(Lead $lead): ?User
    {
        $currentId   = $lead->assigned_user_id;
        $current     = $currentId ? User::find($currentId) : null;
        $currentName = $current?->name;

        $next = $this->pickNextAvailable($currentId, $lead->empreendimento_id);

        if (!$next) {
            if ($current && $this->isEligible($current)) {
                $this->resetSlaOnly($lead, $current);
                return $current;
            }

            $this->logReturnedToQueue($lead, $currentName, 'orphan');
            $this->handleOrphan($lead);
            return null;
        }

        $this->logReturnedToQueue($lead, $currentName, 'reassigned', $next->name);
        $this->applyAssignment($lead, $next, true);
        $this->applyCooldown($next);
        $this->notifyBroker($next, $lead);
        return $next;
    }

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

            'sla_deadline_at'  => $slaMinutes > 0 ? now()->addMinutes($slaMinutes) : null,
        ];

        $statusChanged = false;
        if ($firstStatusId && $firstStatusId !== $oldStatusId) {
            $update['status_id']         = $firstStatusId;
            $update['status_changed_at'] = now();
            $statusChanged = true;
        }

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

        $uid = auth()->check() ? auth()->id() : null;

        try {

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

    private function substatusBelongsToStatus(?int $substatusId, ?int $statusId): bool
    {
        if (!$substatusId) return true;
        if (!$statusId) return false;

        $sub = \App\Models\LeadSubstatus::find($substatusId);
        return $sub && (int) $sub->lead_status_id === (int) $statusId;
    }

    private function applyCooldown(User $corretor): void
    {
        $minutes = $this->configCooldownMinutes();
        if ($minutes <= 0) return;

        $corretor->update([
            'status_corretor' => 'ocupado',
            'cooldown_until'  => now()->addMinutes($minutes),
        ]);
    }

    private function isEligible(User $u): bool
    {
        if (!(bool) $u->active) return false;
        if (strtolower((string) $u->role) !== 'corretor') return false;
        if (strtolower((string) ($u->status_corretor ?? '')) !== 'disponivel') return false;

        if ($u->cooldown_until && $u->cooldown_until->isFuture()) return false;

        if ($u->paused_until && $u->paused_until->isFuture()) return false;

        return true;
    }

    private function pickNextAvailable(?int $excludeUserId = null, ?int $empreendimentoId = null): ?User
    {
        $q = User::where('role', 'corretor')
            ->where('active', true)
            ->where('status_corretor', 'disponivel')
            ->where(function ($q) {

                $q->whereNull('cooldown_until')
                  ->orWhere('cooldown_until', '<=', now());
            })
            ->where(function ($q) {

                $q->whereNull('paused_until')
                  ->orWhere('paused_until', '<=', now());
            });

        if ($empreendimentoId !== null) {
            $q->where(function ($q) use ($empreendimentoId) {
                $q->where('empreendimento_access_mode', 'all')
                  ->orWhereExists(function ($sub) use ($empreendimentoId) {
                      $sub->from('user_empreendimentos')
                          ->whereColumn('user_empreendimentos.user_id', 'users.id')
                          ->where('user_empreendimentos.empreendimento_id', $empreendimentoId);
                  });
            });
        }

        if ($excludeUserId) {
            $q->where('id', '!=', $excludeUserId);
        }

        return $q->orderByRaw('last_lead_assigned_at IS NULL DESC')
            ->orderBy('last_lead_assigned_at', 'asc')
            ->first();
    }

    private function handleOrphan(Lead $lead): void
    {

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
