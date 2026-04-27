<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\LeadHistory;
use Illuminate\Http\Request;

// Lead confirma/cancela visita via link assinado sem login. Token sai do Appointment::booted().
class PublicVisitController extends Controller
{

    public function show(string $token)
    {
        $appt = $this->findByToken($token);

        return response()->json([
            'id'                  => $appt->id,
            'title'               => $appt->title,
            'modality'            => $appt->modality,
            'location'            => $appt->location,
            'starts_at'           => $appt->starts_at,
            'ends_at'             => $appt->ends_at,
            'meeting_url'         => $appt->meeting_url,
            'description'         => $appt->description,
            'confirmation_status' => $appt->confirmation_status,
            'corretor'            => $appt->user ? ['name' => $appt->user->name] : null,
            'lead'                => $appt->lead ? ['name' => $appt->lead->name] : null,
        ]);
    }

    public function confirm(string $token)
    {
        $appt = $this->findByToken($token);


        if (in_array($appt->confirmation_status, ['cancelled','no_show','completed'], true)) {
            return response()->json([
                'message' => 'Esta visita não pode mais ser confirmada (status: ' . $appt->confirmation_status . ').',
            ], 422);
        }

        $appt->update([
            'confirmation_status' => Appointment::CONFIRM_CONFIRMED,
            'lead_confirmed_at'   => now(),
        ]);


        if ($appt->lead_id) {
            try {
                LeadHistory::create([
                    'lead_id'     => $appt->lead_id,
                    'user_id'     => $appt->user_id,
                    'type'        => 'visit_confirmed',
                    'description' => 'Lead confirmou a visita "' . $appt->title . '" via link público.',
                ]);
            } catch (\Throwable $e) {  }
        }

        return response()->json(['success' => true, 'confirmation_status' => 'confirmed']);
    }

    public function cancel(Request $request, string $token)
    {
        $appt = $this->findByToken($token);

        if (in_array($appt->confirmation_status, ['cancelled','no_show','completed'], true)) {
            return response()->json([
                'message' => 'Esta visita já foi finalizada (status: ' . $appt->confirmation_status . ').',
            ], 422);
        }

        $data = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        $appt->update([
            'confirmation_status' => Appointment::CONFIRM_CANCELLED,
            'cancellation_reason' => $data['reason'] ?? 'Cancelada pelo lead via link público.',
            'status'              => 'cancelled',
        ]);

        if ($appt->lead_id) {
            try {
                LeadHistory::create([
                    'lead_id'     => $appt->lead_id,
                    'user_id'     => $appt->user_id,
                    'type'        => 'visit_cancelled',
                    'description' => 'Lead cancelou a visita "' . $appt->title . '" via link público.'
                        . (!empty($data['reason']) ? ' Motivo: ' . $data['reason'] : ''),
                ]);
            } catch (\Throwable $e) {  }
        }

        return response()->json(['success' => true, 'confirmation_status' => 'cancelled']);
    }

    private function findByToken(string $token): Appointment
    {

        if (!preg_match('/^[a-f0-9]{32,128}$/i', $token)) {
            abort(404);
        }
        $appt = Appointment::where('confirmation_token', $token)->first();
        if (!$appt) abort(404);
        return $appt;
    }
}
