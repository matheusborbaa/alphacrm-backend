<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentParticipant;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Setting;
use App\Models\User;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;


// Fechamento da tarefa de agendamento (5 ações). Cada ação seta confirmation_status,
// move o lead conforme settings, e em no-show cria follow-up automático.
class AgendamentoActionController extends Controller
{

    public function visitRealized(Request $request, Appointment $appointment)
    {
        $this->ensureCanAct($appointment);

        $data = $request->validate([
            'observations' => 'required|string|min:5|max:5000',
            'doc'          => 'sometimes|file|max:10240',
        ]);

        $docPath = null;
        if ($request->hasFile('doc')) {
            $docPath = $request->file('doc')->store('agendamento/fichas', 'public');
        }

        DB::transaction(function () use ($appointment, $data, $docPath, $request) {
            $appointment->update([
                'visit_observations'  => $data['observations'],
                'visit_doc_path'      => $docPath ?: $appointment->visit_doc_path,
                'confirmation_status' => Appointment::CONFIRM_CONCLUIDA_VISITA,
                'completed_at'        => now(),
                'completed_by'        => $request->user()->id,
                'modality'            => $appointment->modality ?: Appointment::MODALITY_PRESENCIAL,
            ]);

            $this->moveLeadToConfiguredStage(
                $appointment,
                'agendamento_etapa_apos_visita',
                'Visita',
                'Visita realizada (lead avançou via tarefa de agendamento)'
            );

            $this->notifyParticipants($appointment, 'Visita marcada como realizada');
        });

        return response()->json([
            'success'    => true,
            'action'     => 'visit_realized',
            'doc_url'    => $docPath ? Storage::url($docPath) : null,
            'lead_status_id' => $appointment->fresh()->lead?->status_id,
        ]);
    }


    public function meetingDone(Request $request, Appointment $appointment)
    {
        $this->ensureCanAct($appointment);

        $data = $request->validate([
            'observations' => 'sometimes|nullable|string|max:5000',
        ]);

        DB::transaction(function () use ($appointment, $data, $request) {
            $appointment->update([
                'visit_observations'  => $data['observations'] ?? $appointment->visit_observations,
                'confirmation_status' => Appointment::CONFIRM_CONCLUIDA_REUNIAO,
                'modality'            => Appointment::MODALITY_ONLINE,
                'completed_at'        => now(),
                'completed_by'        => $request->user()->id,
            ]);

            $this->moveLeadToConfiguredStage(
                $appointment,
                'agendamento_etapa_apos_reuniao',
                'Visita',
                'Reunião online realizada (lead avançou via tarefa de agendamento)'
            );

            $this->notifyParticipants($appointment, 'Reunião online marcada como realizada');
        });

        return response()->json(['success' => true, 'action' => 'meeting_done']);
    }


    public function canceled(Request $request, Appointment $appointment)
    {
        $this->ensureCanAct($appointment);

        $data = $request->validate([
            'reason' => 'required|string|min:3|max:500',
        ]);

        $appointment->update([
            'cancellation_reason' => $data['reason'],
            'confirmation_status' => Appointment::CONFIRM_CANCELLED,
            'completed_at'        => now(),
            'completed_by'        => $request->user()->id,
        ]);

        $this->notifyParticipants($appointment, 'Agendamento cancelado: ' . $data['reason']);

        return response()->json(['success' => true, 'action' => 'canceled']);
    }


    public function rescheduled(Request $request, Appointment $appointment)
    {
        $this->ensureCanAct($appointment);

        $data = $request->validate([
            'new_starts_at' => 'required|date|after:now',
            'new_ends_at'   => 'sometimes|nullable|date|after:new_starts_at',
            'reason'        => 'sometimes|nullable|string|max:500',
        ]);

        $newAppointment = null;

        DB::transaction(function () use ($appointment, $data, $request, &$newAppointment) {

            $appointment->update([
                'confirmation_status' => Appointment::CONFIRM_REAGENDADA,
                'cancellation_reason' => $data['reason'] ?? null,
                'completed_at'        => now(),
                'completed_by'        => $request->user()->id,
            ]);


            $newAppointment = Appointment::create([
                'title'            => $appointment->title,
                'lead_id'          => $appointment->lead_id,
                'user_id'          => $appointment->user_id,
                'created_by'       => $request->user()->id,
                'type'             => $appointment->type,
                'task_kind'        => Appointment::KIND_AGENDAMENTO,
                'modality'         => $appointment->modality,
                'description'      => $appointment->description,
                'location'         => $appointment->location,
                'attendee_email'   => $appointment->attendee_email,
                'attendee_phone'   => $appointment->attendee_phone,
                'starts_at'        => $data['new_starts_at'],
                'ends_at'          => $data['new_ends_at'] ?? null,
                'due_at'           => $data['new_starts_at'],
                'priority'         => $appointment->priority,
                'status'           => Appointment::STATUS_PENDING,
                'previous_appointment_id' => $appointment->id,
                'scope'            => $appointment->scope,
            ]);


            foreach ($appointment->participants as $p) {
                AppointmentParticipant::create([
                    'appointment_id' => $newAppointment->id,
                    'user_id'        => $p->user_id,
                    'role'           => $p->role,
                ]);
            }

            $this->notifyParticipants($newAppointment, 'Agendamento reagendado para ' . Carbon::parse($data['new_starts_at'])->format('d/m/Y H:i'));
        });

        return response()->json([
            'success'           => true,
            'action'            => 'rescheduled',
            'new_appointment_id' => $newAppointment?->id,
        ]);
    }


    public function noShow(Request $request, Appointment $appointment)
    {
        $this->ensureCanAct($appointment);

        $followUp = null;

        DB::transaction(function () use ($appointment, $request, &$followUp) {
            $appointment->update([
                'confirmation_status' => Appointment::CONFIRM_NO_SHOW,
                'completed_at'        => now(),
                'completed_by'        => $request->user()->id,
            ]);


            $followUp = $this->createNoShowFollowUp($appointment, $request->user());
        });

        return response()->json([
            'success'         => true,
            'action'          => 'no_show',
            'followup_id'     => $followUp?->id,
            'followup_due_at' => $followUp?->due_at?->toIso8601String(),
        ]);
    }



    private function ensureCanAct(Appointment $appointment): void
    {
        $user = auth()->user();
        if (!$user) abort(401);


        if ($appointment->task_kind !== Appointment::KIND_AGENDAMENTO) {
            abort(422, 'Esta ação só é válida para tarefas de Agendamento.');
        }


        if (Appointment::isFinalConfirmStatus($appointment->confirmation_status)) {
            abort(422, 'Este agendamento já foi finalizado e não pode ser alterado.');
        }
    }

    // Bypassa o bloqueio de transição manual: aqui a mudança é consequência da ação na tarefa,
    // não do usuário arrastando o card no kanban.
    private function moveLeadToConfiguredStage(Appointment $appointment, string $settingKey, string $fallbackName, string $logMessage): void
    {
        $lead = $appointment->lead;
        if (!$lead) return;

        $targetStatusId = Setting::get($settingKey, null);

        if (!$targetStatusId) {
            $byName = LeadStatus::whereRaw('LOWER(name) = ?', [strtolower($fallbackName)])->first();
            $targetStatusId = $byName?->id;
        }

        if (!$targetStatusId) return;

        $lead->update(['status_id' => $targetStatusId]);
    }


    private function createNoShowFollowUp(Appointment $appointment, User $actor): ?Appointment
    {
        $days  = (int) Setting::get('agendamento_no_show_followup_days', 1);
        $hour  = (int) Setting::get('agendamento_no_show_followup_hour', 9);
        $skipW = (bool) Setting::get('agendamento_no_show_followup_skip_weekends', true);

        $when = now()->addDays(max(0, $days))->setTime($hour, 0, 0);
        if ($skipW) {
            while ($when->isWeekend()) {
                $when->addDay();
            }
        }

        $titleTpl = (string) Setting::get('agendamento_no_show_followup_title_template', 'Follow-up: visita não realizada com {lead_name}');
        $descTpl  = (string) Setting::get('agendamento_no_show_followup_desc_template',  'Lead não compareceu à visita do dia {visit_date}. Entre em contato para entender o motivo e tentar reagendar.');

        $vars = [
            '{lead_name}'  => $appointment->lead?->name ?? '(lead)',
            '{visit_date}' => optional($appointment->starts_at)->format('d/m/Y H:i') ?? '—',
        ];
        $title = strtr($titleTpl, $vars);
        $desc  = strtr($descTpl,  $vars);

        return Appointment::create([
            'title'       => $title,
            'description' => $desc,
            'lead_id'     => $appointment->lead_id,
            'user_id'     => $appointment->user_id,
            'created_by'  => $actor->id,
            'type'        => Appointment::TYPE_TASK,
            'task_kind'   => Appointment::KIND_FOLLOWUP,
            'due_at'      => $when,
            'starts_at'   => $when,
            'priority'    => Appointment::PRIORITY_HIGH,
            'status'      => Appointment::STATUS_PENDING,
            'scope'       => $appointment->scope,
        ]);
    }


    private function notifyParticipants(Appointment $appointment, string $message): void
    {
        $userIds = $appointment->participants->pluck('user_id')->all();
        if ($appointment->user_id) $userIds[] = $appointment->user_id;
        $userIds = array_unique(array_filter($userIds));

        $title = 'Agendamento atualizado';
        if ($appointment->lead) {
            $title .= ': ' . $appointment->lead->name;
        }

        foreach ($userIds as $uid) {
            try {
                Notification::create([
                    'user_id' => $uid,
                    'title'   => $title,
                    'message' => $message,
                    'read'    => false,
                ]);
            } catch (\Throwable $e) {
                \Log::warning('Falha ao notificar participante de agendamento', [
                    'appointment_id' => $appointment->id,
                    'user_id'        => $uid,
                    'error'          => $e->getMessage(),
                ]);
            }
        }
    }
}
