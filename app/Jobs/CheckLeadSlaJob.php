<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\LeadAssignmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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

        $assignmentService = new LeadAssignmentService();

        foreach ($leads as $lead) {
            // marca SLA como expirado
            $lead->update([
                'sla_status' => 'expired'
            ]);


            $old = [
    'assigned_user_id' => $lead->assigned_user_id,
    'sla_status' => $lead->sla_status
];
            // redistribui
            $assignmentService->assign($lead);

            AuditService::log(
    'sla_expired_reassigned',
    'Lead',
    $lead->id,
    null,
    $old,
    [
        'assigned_user_id' => $lead->assigned_user_id,
        'sla_status' => 'pending'
    ],
    'sla_job'
);
        }
    }
}
