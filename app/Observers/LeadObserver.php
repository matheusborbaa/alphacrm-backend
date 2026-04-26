<?php

namespace App\Observers;

use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\LeadSubstatus;
use App\Models\LeadHistory;
use App\Models\Commission;
use App\Models\Setting;
use App\Services\AuditService;
use App\Services\MediaLibrarySync;

class LeadObserver
{

    public function created(Lead $lead): void
    {
        try {
            app(MediaLibrarySync::class)->ensureFolderForLead($lead);
        } catch (\Throwable $e) {
            \Log::warning('Falha ao criar pasta da biblioteca pro lead', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    public function updated(Lead $lead): void
    {
        $this->logStatusHistory($lead);
        $this->logSubstatusHistory($lead);
        $this->maybeCreateCommission($lead);
        $this->maybeSyncMediaFolder($lead);
    }

    public function deleting(Lead $lead): void
    {
        try {
            app(MediaLibrarySync::class)->handleLeadDeleted($lead->id);
        } catch (\Throwable $e) {
            \Log::warning('Falha ao remover pasta da biblioteca do lead', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    protected function maybeSyncMediaFolder(Lead $lead): void
    {
        if (!$lead->wasChanged('name')) return;
        try {
            app(MediaLibrarySync::class)->ensureFolderForLead($lead);
        } catch (\Throwable $e) {
            \Log::warning('Falha ao renomear pasta da biblioteca do lead', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    protected function logStatusHistory(Lead $lead): void
    {
        if (!$lead->wasChanged('status_id')) {
            return;
        }

        $fromId = $lead->getOriginal('status_id');
        $toId   = $lead->status_id;

        $fromName = $fromId ? LeadStatus::whereKey($fromId)->value('name') : null;
        $toName   = $toId   ? LeadStatus::whereKey($toId)->value('name')   : null;

        if (!$fromName && !$toName) {
            return;
        }

        LeadHistory::create([
            'lead_id'     => $lead->id,
            'user_id'     => auth()->id(),
            'type'        => 'status_change',
            'from'        => $fromName,
            'to'          => $toName,
            'description' => sprintf(
                'Status alterado: %s → %s',
                $fromName ?? '—',
                $toName   ?? '—'
            ),
        ]);
    }

    protected function logSubstatusHistory(Lead $lead): void
    {
        if (!$lead->wasChanged('lead_substatus_id')) {
            return;
        }

        $fromId = $lead->getOriginal('lead_substatus_id');
        $toId   = $lead->lead_substatus_id;

        $fromName = $fromId ? LeadSubstatus::whereKey($fromId)->value('name') : null;
        $toName   = $toId   ? LeadSubstatus::whereKey($toId)->value('name')   : null;

        if (!$fromName && !$toName) {
            return;
        }

        LeadHistory::create([
            'lead_id'     => $lead->id,
            'user_id'     => auth()->id(),
            'type'        => 'substatus_change',
            'from'        => $fromName,
            'to'          => $toName,
            'description' => sprintf(
                'Etapa alterada: %s → %s',
                $fromName ?? 'Sem etapa',
                $toName   ?? 'Sem etapa'
            ),
        ]);
    }

    protected function maybeCreateCommission(Lead $lead): void
    {
        $changedStatus    = $lead->wasChanged('status_id');
        $changedSubstatus = $lead->wasChanged('lead_substatus_id');

        if (!$changedStatus && !$changedSubstatus) {
            return;
        }

        if (Commission::where('lead_id', $lead->id)->exists()) {
            return;
        }

        if (!$this->matchesCommissionTrigger($lead, $changedStatus, $changedSubstatus)) {
            return;
        }

        $percentage = $lead->empreendimento?->commission_percentage ?? 5;

        $saleValue = $lead->sale_value
            ?? $lead->empreendimento?->average_sale_value
            ?? 0;

        if ($percentage === null || $percentage === '') {
            $percentage = 5;
        }

        $commissionValue = ($saleValue * $percentage) / 100;

        Commission::create([
            'lead_id'               => $lead->id,
            'user_id'               => $lead->assigned_user_id,
            'sale_value'            => $saleValue,
            'commission_percentage' => $percentage,
            'commission_value'      => $commissionValue,
            'status'                => Commission::STATUS_DRAFT,
        ]);

        AuditService::log(
            'commission_created',
            'Lead',
            $lead->id,
            $lead->assigned_user_id,
            null,
            [
                'commission_percentage' => $percentage,
                'commission_value'      => $commissionValue,
            ],
            'observer'
        );
    }

    protected function matchesCommissionTrigger(Lead $lead, bool $changedStatus, bool $changedSubstatus): bool
    {
        $statusIds    = array_map('intval', (array) Setting::get('commission_trigger_status_ids', []));
        $substatusIds = array_map('intval', (array) Setting::get('commission_trigger_substatus_ids', []));

        if (empty($statusIds) && empty($substatusIds)) {
            return $changedStatus && $lead->status?->name === 'Vendido';
        }

        if ($changedStatus
            && $lead->status_id !== null
            && in_array((int) $lead->status_id, $statusIds, true)
        ) {
            return true;
        }

        if ($changedSubstatus
            && $lead->lead_substatus_id !== null
            && in_array((int) $lead->lead_substatus_id, $substatusIds, true)
        ) {
            return true;
        }

        return false;
    }
}
