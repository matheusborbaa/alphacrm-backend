<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\LeadHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder;

class TaskController extends Controller
{

    public function index(Request $request)
    {
        $user = Auth::user();

        $query = Appointment::tasks()
            ->withVisibleLead()
            ->with([
                'lead:id,name',
                'user:id,name',
            ]);

        $this->scopeByRole($query, $user);

        switch ($request->query('filter')) {
            case 'today':
                $query->dueToday();
                break;
            case 'overdue':
                $query->overdue();
                break;
            case 'upcoming':
                $query->upcoming();
                break;
            case 'done':
                $query->whereNotNull('completed_at');
                break;
            case 'open':
                $query->open();
                break;

        }

        if ($request->filled('lead_id')) {
            $query->where('lead_id', $request->lead_id);
        }

        if ($request->filled('user_id') && $this->isManager($user)) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('from')) {
            $query->where('due_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->where('due_at', '<=', $request->to);
        }

        if ($request->filled('q')) {
            $query->where('title', 'like', '%' . $request->q . '%');
        }

        $query->orderByRaw('completed_at IS NULL DESC')
              ->orderBy('due_at', 'asc')
              ->orderBy('id', 'desc');

        $perPage = min((int) $request->query('per_page', 50), 200);

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'lead_id'     => 'nullable|exists:leads,id',
            'due_at'      => 'nullable|date',
            'priority'    => 'nullable|in:low,medium,high',
            'reminder_at' => 'nullable|date',

            'task_kind'   => ['nullable', \Illuminate\Validation\Rule::in(\App\Models\Appointment::validKindSlugs())],

            'user_id'     => 'nullable|exists:users,id',

            'scope'       => 'nullable|in:private,company',


            'starts_at'      => 'nullable|date',
            'ends_at'        => 'nullable|date|after_or_equal:starts_at',
            'modality'       => 'nullable|string|in:presencial,online',
            'location'       => 'nullable|string|max:1000',
            'attendee_email' => 'nullable|email|max:191',
            'attendee_phone' => 'nullable|string|max:32',
        ]);

        $user = Auth::user();

        $assigneeId = isset($data['user_id']) && $this->isManager($user)
            ? $data['user_id']
            : $user->id;

        $scope = (isset($data['scope']) && $this->isManager($user))
            ? $data['scope']
            : 'private';

        $kind = $data['task_kind'] ?? Appointment::KIND_GENERICA;
        $isVisitKind = in_array($kind, [
            Appointment::KIND_VISITA,
            Appointment::KIND_AGENDAMENTO,
            Appointment::KIND_REUNIAO,
        ], true);


        $startsAt = $data['starts_at'] ?? ($isVisitKind ? ($data['due_at'] ?? null) : null);

        $task = Appointment::create([
            'title'          => $data['title'],
            'description'    => $data['description'] ?? null,
            'lead_id'        => $data['lead_id'] ?? null,
            'due_at'         => $data['due_at'] ?? null,
            'priority'       => $data['priority'] ?? Appointment::PRIORITY_MEDIUM,
            'reminder_at'    => $data['reminder_at'] ?? null,
            'type'           => Appointment::TYPE_TASK,
            'task_kind'      => $kind,
            'status'         => Appointment::STATUS_PENDING,
            'user_id'        => $assigneeId,
            'scope'          => $scope,
            'created_by'     => $user->id,


            'starts_at'      => $startsAt,
            'ends_at'        => $data['ends_at'] ?? null,
            'modality'       => $data['modality'] ?? null,
            'location'       => $data['location'] ?? null,
            'attendee_email' => $data['attendee_email'] ?? null,
            'attendee_phone' => $data['attendee_phone'] ?? null,
        ]);

        $this->logLeadHistory($task, 'task_created',
            'Tarefa criada: ' . $task->title
        );


        $this->pushToGoogleSafely($task);


        $this->notifyGestorOfNewVisitSafely($task);

        return response()->json($task->fresh(['lead:id,name', 'user:id,name']), 201);
    }


    private function notifyGestorOfNewVisitSafely(Appointment $appt): void
    {
        try {
            if (!$appt->isVisit()) return;

            $corretor = $appt->user ?: \App\Models\User::find($appt->user_id);
            if (!$corretor) return;


            $gestorId = $corretor->parent_user_id ?? null;
            if (!$gestorId) return;

            $gestor = \App\Models\User::find($gestorId);
            if (!$gestor || !$gestor->email) return;


            if (strcasecmp($gestor->email, $corretor->email ?? '') === 0) return;

            \Illuminate\Support\Facades\Mail::to($gestor->email)
                ->send(new \App\Mail\NewVisitForGestorMail($appt, $gestor, $corretor));
        } catch (\Throwable $e) {
            \Log::warning('[task] notifyGestorOfNewVisit falhou (silencioso): ' . $e->getMessage());
        }
    }


    private function pushToGoogleSafely(Appointment $appt): void
    {
        try {
            \Log::info('[task→google] tentando push', [
                'appt_id'    => $appt->id,
                'task_kind'  => $appt->task_kind,
                'type'       => $appt->type,
                'is_visit'   => $appt->isVisit(),
                'user_id'    => $appt->user_id,
            ]);

            if (!$appt->isVisit()) {
                \Log::info('[task→google] pulado: não é visita (kind=' . $appt->task_kind . ')');
                return;
            }

            $service = app(\App\Services\GoogleCalendarService::class);

            if (!$service->isConfigured()) {
                \Log::warning('[task→google] pulado: Google não configurado (faltam env vars ou pacote)');
                return;
            }

            $user = \App\Models\User::find($appt->user_id);
            if (!$user) {
                \Log::warning('[task→google] pulado: user_id ' . $appt->user_id . ' não encontrado');
                return;
            }

            if (!$service->isUserConnected($user)) {
                \Log::warning('[task→google] pulado: user ' . $user->id . ' (' . $user->name . ') não tem credencial Google conectada');
                return;
            }

            $result = $service->pushAppointment($appt);
            \Log::info('[task→google] resultado push', ['result' => $result]);
        } catch (\Throwable $e) {
            \Log::error('[task→google] EXCEPTION: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        }
    }

    private function deleteFromGoogleSafely(Appointment $appt): void
    {
        try {
            if (!$appt->external_event_id) return;
            app(\App\Services\GoogleCalendarService::class)->deleteAppointment($appt);
        } catch (\Throwable $e) {
            \Log::warning('[task] delete no Google falhou: ' . $e->getMessage());
        }
    }

    public function show(int $id)
    {
        $task = Appointment::tasks()
            ->with([
                'lead:id,name',
                'user:id,name',
                'creator:id,name',
                'completer:id,name',
                'comments',
            ])
            ->findOrFail($id);

        $this->authorizeRead($task);

        return response()->json($task);
    }

    public function update(Request $request, int $id)
    {
        $task = Appointment::tasks()->findOrFail($id);
        $this->authorizeEdit($task);

        $data = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'lead_id'     => 'nullable|exists:leads,id',
            'due_at'      => 'nullable|date',
            'priority'    => 'nullable|in:low,medium,high',
            'reminder_at' => 'nullable|date',

            'task_kind'   => ['nullable', \Illuminate\Validation\Rule::in(\App\Models\Appointment::validKindSlugs())],
            'user_id'     => 'nullable|exists:users,id',
            'scope'       => 'nullable|in:private,company',


            'starts_at'      => 'nullable|date',
            'ends_at'        => 'nullable|date|after_or_equal:starts_at',
            'modality'       => 'nullable|string|in:presencial,online',
            'location'       => 'nullable|string|max:1000',
            'attendee_email' => 'nullable|email|max:191',
            'attendee_phone' => 'nullable|string|max:32',
        ]);

        if (isset($data['user_id']) && !$this->isManager(Auth::user())) {
            unset($data['user_id']);
        }

        if (isset($data['scope']) && !$this->isManager(Auth::user())) {
            unset($data['scope']);
        }


        $newKind = $data['task_kind'] ?? $task->task_kind;
        $isVisitKind = in_array($newKind, [
            Appointment::KIND_VISITA,
            Appointment::KIND_AGENDAMENTO,
            Appointment::KIND_REUNIAO,
        ], true);

        if ($isVisitKind && empty($data['starts_at']) && !empty($data['due_at'])) {
            $data['starts_at'] = $data['due_at'];
        }

        $task->update($data);

        $changed = array_keys($task->getChanges());
        $significant = array_intersect($changed, ['title', 'due_at', 'starts_at', 'priority', 'user_id', 'modality', 'location']);
        if (!empty($significant)) {
            $this->logLeadHistory($task, 'task_updated',
                'Tarefa atualizada: ' . $task->title . ' (' . implode(', ', $significant) . ')'
            );
        }


        $this->pushToGoogleSafely($task->fresh());

        return response()->json($task->fresh(['lead:id,name', 'user:id,name']));
    }

    public function complete(int $id)
    {
        $task = Appointment::tasks()->findOrFail($id);
        $this->authorizeComplete($task);

        if ($task->isCompleted()) {
            return response()->json([
                'success' => true,
                'message' => 'Tarefa já estava concluída',
                'task'    => $task,
            ]);
        }

        $task->update([
            'completed_at' => now(),
            'completed_by' => Auth::id(),
            'status'       => Appointment::STATUS_DONE,
        ]);

        $this->logLeadHistory($task, 'task_completed',
            'Tarefa concluída: ' . $task->title
        );

        return response()->json([
            'success' => true,
            'task'    => $task->fresh(['lead:id,name', 'user:id,name']),
        ]);
    }

    public function reopen(int $id)
    {
        $task = Appointment::tasks()->findOrFail($id);
        $this->authorizeComplete($task);

        if (!$task->isCompleted()) {
            return response()->json([
                'success' => true,
                'message' => 'Tarefa já estava aberta',
                'task'    => $task,
            ]);
        }

        $task->update([
            'completed_at' => null,
            'completed_by' => null,
            'status'       => Appointment::STATUS_PENDING,
        ]);

        $this->logLeadHistory($task, 'task_reopened',
            'Tarefa reaberta: ' . $task->title
        );

        return response()->json([
            'success' => true,
            'task'    => $task->fresh(['lead:id,name', 'user:id,name']),
        ]);
    }

    public function destroy(int $id)
    {
        $task = Appointment::tasks()->findOrFail($id);
        $this->authorizeEdit($task);

        $title = $task->title;
        $leadId = $task->lead_id;


        $this->deleteFromGoogleSafely($task);

        $task->delete();

        if ($leadId) {
            LeadHistory::create([
                'lead_id'     => $leadId,
                'user_id'     => Auth::id(),
                'type'        => 'task_deleted',
                'description' => 'Tarefa removida: ' . $title,
            ]);
        }

        return response()->json(['success' => true]);
    }

    private function normalizedRole($user): string
    {
        return strtolower(trim((string) ($user->role ?? '')));
    }

    private function isManager($user): bool
    {
        return in_array($this->normalizedRole($user), ['admin', 'gestor'], true);
    }

    private function scopeByRole(Builder $query, $user): void
    {
        if ($this->isManager($user)) {

            $query->where(function (Builder $q) use ($user) {
                $q->where('scope', 'company')
                  ->orWhereNotNull('lead_id')
                  ->orWhere('user_id', $user->id)
                  ->orWhere('created_by', $user->id);
            });
            return;
        }

        $query->where(function (Builder $q) use ($user) {
            $q->where('user_id', $user->id)
              ->orWhere('created_by', $user->id)
              ->orWhere('scope', 'company');
        });
    }

    private function isOthersPrivateTask(Appointment $task, $user): bool
    {
        if ($task->scope !== 'private')   return false;
        if (!is_null($task->lead_id))      return false;
        if ((int) $task->user_id    === (int) $user->id) return false;
        if ((int) $task->created_by === (int) $user->id) return false;
        return true;
    }

    private function authorizeRead(Appointment $task): void
    {
        $user = Auth::user();

        if ($this->isManager($user)) {
            if ($this->isOthersPrivateTask($task, $user)) {
                Log::warning('TaskController::authorizeRead bloqueou manager em tarefa pessoal', [
                    'user_id'  => $user->id,
                    'task_id'  => $task->id,
                ]);
                abort(403, 'Esta é uma tarefa pessoal do corretor.');
            }
            return;
        }

        $ok = (int) $task->user_id    === (int) $user->id
            || (int) $task->created_by === (int) $user->id
            || $task->scope === 'company';

        if (!$ok) {
            Log::warning('TaskController::authorizeRead bloqueou acesso', [
                'user_id'          => $user->id,
                'user_role'        => $user->role,
                'task_id'          => $task->id,
                'task_user_id'     => $task->user_id,
                'task_created_by'  => $task->created_by,
                'task_scope'       => $task->scope,
            ]);
        }

        abort_if(!$ok, 403, 'Sem permissão pra visualizar esta tarefa.');
    }

    private function authorizeEdit(Appointment $task): void
    {
        $user = Auth::user();

        if ($this->isManager($user)) {

            if ($this->isOthersPrivateTask($task, $user)) {
                Log::warning('TaskController::authorizeEdit bloqueou manager em tarefa pessoal', [
                    'user_id' => $user->id,
                    'task_id' => $task->id,
                ]);
                abort(403, 'Esta é uma tarefa pessoal do corretor.');
            }
            return;
        }

        $isOwner   = (int) $task->user_id    === (int) $user->id;
        $isCreator = (int) $task->created_by === (int) $user->id;

        if (!$isOwner && !$isCreator) {
            Log::warning('TaskController::authorizeEdit bloqueou edição', [
                'user_id'          => $user->id,
                'user_role'        => $user->role,
                'task_id'          => $task->id,
                'task_user_id'     => $task->user_id,
                'task_created_by'  => $task->created_by,
                'task_scope'       => $task->scope,
            ]);
        }

        abort_if(
            !$isOwner && !$isCreator,
            403,
            'Sem permissão pra editar esta tarefa.'
        );
    }

    private function authorizeComplete(Appointment $task): void
    {
        $user = Auth::user();

        if ($this->isManager($user)) {
            if ($this->isOthersPrivateTask($task, $user)) {
                Log::warning('TaskController::authorizeComplete bloqueou manager em tarefa pessoal', [
                    'user_id' => $user->id,
                    'task_id' => $task->id,
                ]);
                abort(403, 'Esta é uma tarefa pessoal do corretor.');
            }
            return;
        }

        $ok = (int) $task->user_id    === (int) $user->id
            || (int) $task->created_by === (int) $user->id
            || $task->scope === 'company';

        if (!$ok) {
            Log::warning('TaskController::authorizeComplete bloqueou ação', [
                'user_id'          => $user->id,
                'user_role'        => $user->role,
                'task_id'          => $task->id,
                'task_user_id'     => $task->user_id,
                'task_created_by'  => $task->created_by,
                'task_scope'       => $task->scope,
            ]);
        }

        abort_if(!$ok, 403, 'Sem permissão pra concluir esta tarefa.');
    }

    private function logLeadHistory(Appointment $task, string $type, string $description): void
    {
        if (!$task->lead_id) {
            return;
        }

        LeadHistory::create([
            'lead_id'     => $task->lead_id,
            'user_id'     => Auth::id(),
            'type'        => $type,
            'description' => $description,
        ]);
    }
}
