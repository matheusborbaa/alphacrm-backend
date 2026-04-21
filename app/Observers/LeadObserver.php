<?php

namespace App\Observers;

use App\Models\Lead;
use App\Models\Commission;
use App\Services\AuditService;

class LeadObserver
{
    public function updated(Lead $lead): void
    {
        // Só age se o status mudou
        if (!$lead->wasChanged('status_id')) {
            return;
        }

        // Verifica se o novo status é "Vendido"
        if ($lead->status?->name !== 'Vendido') {
            return;
        }

        // Evita comissão duplicada
        if (Commission::where('lead_id', $lead->id)->exists()) {
            return;
        }
$percentage = $lead->empreendimento
    ? $lead->empreendimento->commission_percentage
    : 5;

$saleValue = $lead->sale_value
    ?? $lead->empreendimento?->average_sale_value
    ?? 0;

        // 🔢 Valores padrão (TEMPORÁRIO)
        $percentage = $lead->empreendimento
    ? $lead->empreendimento->commission_percentage
    : 5;

$saleValue = $lead->sale_value
    ?? $lead->empreendimento?->average_sale_value
    ?? 0;
        $commissionValue = ($saleValue * $percentage) / 100;

        // Cria comissão
        Commission::create([
            'lead_id' => $lead->id,
            'user_id' => $lead->assigned_user_id,
            'sale_value' => $saleValue,
            'commission_percentage' => $percentage,
            'commission_value' => $commissionValue,
            'status' => 'pending'
        ]);

        // Auditoria
        AuditService::log(
            'commission_created',
            'Lead',
            $lead->id,
            $lead->assigned_user_id,
            null,
            [
                'commission_percentage' => $percentage,
                'commission_value' => $commissionValue
            ],
            'observer'
        );
    }
}
