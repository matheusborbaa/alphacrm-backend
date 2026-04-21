<?php

namespace App\Services;

use App\Models\User;
use App\Models\Lead;
use App\Notifications\LeadAssignedNotification;
use Carbon\Carbon;

class LeadAssignmentService
{
    public function assign(Lead $lead): ?User
    {
        // Busca corretores ativos
        $corretores = User::where('role', 'corretor')
            ->where('active', true)
            ->orderByRaw('last_lead_assigned_at IS NULL DESC')
            ->orderBy('last_lead_assigned_at', 'asc')
            ->get();

        if ($corretores->isEmpty()) {
            return null;
        }

        // Seleciona o primeiro da fila
        $corretor = $corretores->first();

        // Atribui o lead
        $lead->update([
            'assigned_user_id' => $corretor->id,
            'assigned_at'      => now(),
            'sla_deadline_at'  => now()->addMinutes(15),
            'sla_status'       => 'pending',
        ]);

        // Atualiza o ponteiro do corretor
        $corretor->update([
            'last_lead_assigned_at' => now()
        ]);

        // 🔔 Dispara notificação (database + mail) — popup sonoro no CRM e e-mail.
        try {
            $corretor->notify(new LeadAssignedNotification($lead->fresh()));
        } catch (\Throwable $e) {
            // Não deve quebrar o fluxo de atribuição — só loga.
            \Log::warning('Falha ao notificar corretor de novo lead', [
                'lead_id' => $lead->id,
                'user_id' => $corretor->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return $corretor;
    }
}
