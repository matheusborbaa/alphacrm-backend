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

        $reassignEnabled = (bool) Setting::get('lead_sla_reassign_on_breach', true);

        $assignmentService = new LeadAssignmentService();

        foreach ($leads as $lead) {
            $oldCorretorId   = $lead->assigned_user_id;
            $oldCorretorName = optional(User::find($oldCorretorId))->name;

            $lead->update(['sla_status' => 'expired']);

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
