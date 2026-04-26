<?php

namespace App\Http\Controllers;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

use App\Models\Lead;
use App\Models\LeadInteraction;
use App\Models\LeadHistory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use App\Services\AuditService;
use App\Notifications\VisitScheduledNotification;

class LeadInteractionController extends Controller
{
        use AuthorizesRequests;

public function store(Request $request, Lead $lead)
{
    $user = $request->user();

    if (
        $user->role === 'corretor' &&
        $lead->assigned_user_id !== $user->id
    ) {
        return response()->json([
            'message' => 'Unauthorized'
        ], 403);
    }

    $data = $request->validate([
        'type' => 'required|in:whatsapp,call,email,visit,note,task',
        'note' => 'nullable|string',
        'appointment_date' => 'nullable|date'
    ]);

    $interaction = LeadInteraction::create([
        'lead_id' => $lead->id,
        'user_id' => $user->id,
        'type'    => $data['type'],
        'note'    => $data['note'] ?? null,
    ]);

if (in_array($data['type'], ['visit', 'task'])) {

    $scheduledAt = $data['appointment_date'] ?? now();

    $conflict = \App\Models\Appointment::where('user_id', $user->id)
        ->where('starts_at', $scheduledAt)
        ->where('status', 'scheduled')
        ->exists();

    if ($conflict) {
        return response()->json([
            'message' => 'Você já possui uma visita agendada nesse horário.'
        ], 422);
    }

    $appointment = \App\Models\Appointment::create([
        'lead_id'   => $lead->id,
        'user_id'   => $user->id,
        'title'     => $lead->name,
        'type'      => $data['type'],
        'starts_at' => $scheduledAt,
        'status'    => 'scheduled',
    ]);

    $interaction->update([
        'appointment_id' => $appointment->id
    ]);

    $user->notify(new VisitScheduledNotification($lead));

    LeadHistory::create([
    'lead_id' => $lead->id,
    'user_id' => Auth::id(),
    'type' => 'appointment_created',
    'description' => 'Tarefa criada: ' . $appointment->type,
]);

    if ($data['type'] === "visit") {
        $status = \App\Models\LeadStatus::where('name', 'Visita Agendada')->first();
    } else {
        $status = $lead->status;
    }

    $lead->update([
        'status_id' => $status->id
    ]);
}

    $interaction->load('user:id,name');

    return response()->json([
        'data' => $interaction
    ], 201);
}
}