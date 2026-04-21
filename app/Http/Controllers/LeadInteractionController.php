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

/**
 * @group Leads
 *
 * Registro de contatos realizados pelos corretores com os leads.
 * Usado para encerrar SLA, histórico do lead e auditoria.
 */
class LeadInteractionController extends Controller
{
        use AuthorizesRequests;

    /**
     * Registrar contato com lead
     *
     * Registra uma interação (contato) entre o corretor e o lead.
     * Ao registrar um contato, o SLA do lead é encerrado automaticamente.
     *
     * @urlParam lead int ID do lead. Example: 1
     *
     * @bodyParam type string required
     * Tipo do contato realizado.
     * Valores possíveis: whatsapp, call, email, visit.
     * Example: whatsapp
     *
     * @bodyParam note string Observação opcional sobre o contato. Example: Cliente pediu retorno amanhã
     *
     * @bodyParam user_id int required ID do corretor que realizou o contato. Example: 2
     *
     * @response 200 {
     *   "success": true
     * }
     *
     * @response 422 {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "type": ["The type field is required."]
     *   }
     * }
     */
    /*
   public function store(Request $request, Lead $lead)
{
    $this->authorize('interact', $lead);

    $data = $request->validate([
        'type' => 'required|in:whatsapp,call,email,visit',
        'note' => 'nullable|string',
    ]);

    $user = $request->user();


    $interaction = LeadInteraction::create([
    'lead_id' => $lead->id,
    'user_id' => $user->id,
    'type'    => $data['type'],
    'note'    => $data['note'] ?? null,
]);

$interaction->load('user:id,name');

return response()->json([
    'data' => [
        'id' => $interaction->id,
        'type' => $interaction->type,
        'note' => $interaction->note,
        'created_at' => $interaction->created_at,
        'updated_at' => $interaction->updated_at,
        'user' => [
            'id' => $interaction->user->id,
            'name' => $interaction->user->name,
        ]
    ]
], 201);
} */

public function store(Request $request, Lead $lead)
{
    $user = $request->user();

    // 🔐 Proteção
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

    // 1️⃣ Cria interação
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

    // 🔥 CRIA O APPOINTMENT
    $appointment = \App\Models\Appointment::create([
        'lead_id'   => $lead->id,
        'user_id'   => $user->id,
        'title'     => $lead->name,
        'type'      => $data['type'],
        'starts_at' => $scheduledAt,
        'status'    => 'scheduled',
    ]);

    // 💥 AQUI É O PULO DO GATO
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