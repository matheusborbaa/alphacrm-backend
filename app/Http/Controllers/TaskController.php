<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\LeadHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;

/**
 * TaskController — gerencia TAREFAS / FOLLOW-UPS.
 *
 * Tarefas são um tipo especial de Appointment (type='task'). Mantemos
 * controller separado porque o fluxo é bem diferente do de eventos de
 * agenda:
 *
 *   - Agenda trabalha com horário (starts_at/ends_at) e é usada pra
 *     calendário/day-view.
 *   - Tarefa trabalha com PRAZO (due_at) e tem ciclo de vida
 *     pending → done / cancelled → reopen.
 *
 * Regras de autorização (inline, não Policy, pra manter padrão do
 * AppointmentController):
 *   - admin/gestor: enxergam e editam qualquer tarefa.
 *   - corretor:    enxergam as próprias (user_id=self) + scope='company'.
 *                  Editam/concluem/deletam só as próprias.
 *
 * Histórico do lead: cada operação grava LeadHistory semanticamente
 * tipada (task_created, task_completed, task_reopened, task_deleted)
 * — facilita filtrar a timeline por tipo no futuro.
 */
class TaskController extends Controller
{
    /* ==================================================================
     * INDEX — lista tarefas com filtros combináveis
     *
     * Filtros suportados (todos opcionais):
     *   filter    = today | overdue | upcoming | done | open
     *   lead_id   = id do lead (tarefas vinculadas a ele)
     *   user_id   = id do dono (só admin/gestor podem filtrar por outro)
     *   priority  = low | medium | high
     *   from/to   = intervalo de datas no due_at
     *   q         = busca textual no title
     *   per_page  = paginação (default 50)
     * ================================================================== */
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = Appointment::tasks()->with([
            'lead:id,name',
            'user:id,name',
        ]);

        $this->scopeByRole($query, $user);

        // ---- filtro por estado ----
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
            // sem filtro → retorna tudo (paginado)
        }

        // ---- escopos adicionais ----
        if ($request->filled('lead_id')) {
            $query->where('lead_id', $request->lead_id);
        }

        // Só admin/gestor podem consultar tarefas de OUTRO user.
        if ($request->filled('user_id') && in_array($user->role, ['admin', 'gestor'])) {
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

        // Ordenação: abertas primeiro (por prazo asc), concluídas depois.
        $query->orderByRaw('completed_at IS NULL DESC')
              ->orderBy('due_at', 'asc')
              ->orderBy('id', 'desc');

        $perPage = min((int) $request->query('per_page', 50), 200);

        return response()->json($query->paginate($perPage));
    }

    /* ==================================================================
     * STORE — cria nova tarefa
     * ================================================================== */
    public function store(Request $request)
    {
        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'lead_id'     => 'nullable|exists:leads,id',
            'due_at'      => 'nullable|date',
            'priority'    => 'nullable|in:low,medium,high',
            'reminder_at' => 'nullable|date',
            // admin/gestor podem atribuir a outro user
            'user_id'     => 'nullable|exists:users,id',
        ]);

        $user = Auth::user();

        // Corretor só cria tarefa pra si mesmo.
        $assigneeId = isset($data['user_id']) && in_array($user->role, ['admin', 'gestor'])
            ? $data['user_id']
            : $user->id;

        $task = Appointment::create([
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'lead_id'     => $data['lead_id'] ?? null,
            'due_at'      => $data['due_at'] ?? null,
            'priority'    => $data['priority'] ?? Appointment::PRIORITY_MEDIUM,
            'reminder_at' => $data['reminder_at'] ?? null,
            'type'        => Appointment::TYPE_TASK,
            'status'      => Appointment::STATUS_PENDING,
            'user_id'     => $assigneeId,
            'created_by'  => $user->id,
        ]);

        $this->logLeadHistory($task, 'task_created',
            'Tarefa criada: ' . $task->title
        );

        return response()->json($task->load(['lead:id,name', 'user:id,name']), 201);
    }

    /* ==================================================================
     * SHOW — retorna uma tarefa
     * ================================================================== */
    public function show(int $id)
    {
        $task = Appointment::tasks()
            ->with(['lead:id,name', 'user:id,name', 'creator:id,name', 'completer:id,name'])
            ->findOrFail($id);

        $this->authorizeRead($task);

        return response()->json($task);
    }

    /* ==================================================================
     * UPDATE — altera campos editáveis da tarefa
     * ================================================================== */
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
            'user_id'     => 'nullable|exists:users,id',
        ]);

        // Reatribuição só por admin/gestor.
        if (isset($data['user_id']) && !in_array(Auth::user()->role, ['admin', 'gestor'])) {
            unset($data['user_id']);
        }

        $task->update($data);

        // Histórico só se mudou algo significativo.
        $changed = array_keys($task->getChanges());
        $significant = array_intersect($changed, ['title', 'due_at', 'priority', 'user_id']);
        if (!empty($significant)) {
            $this->logLeadHistory($task, 'task_updated',
                'Tarefa atualizada: ' . $task->title . ' (' . implode(', ', $significant) . ')'
            );
        }

        return response()->json($task->fresh(['lead:id,name', 'user:id,name']));
    }

    /* ==================================================================
     * COMPLETE — marca como concluída
     * ================================================================== */
    public function complete(int $id)
    {
        $task = Appointment::tasks()->findOrFail($id);
        $this->authorizeEdit($task);

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

    /* ==================================================================
     * REOPEN — desmarca a conclusão (útil se concluiu por engano)
     * ================================================================== */
    public function reopen(int $id)
    {
        $task = Appointment::tasks()->findOrFail($id);
        $this->authorizeEdit($task);

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

    /* ==================================================================
     * DESTROY — remove a tarefa
     * ================================================================== */
    public function destroy(int $id)
    {
        $task = Appointment::tasks()->findOrFail($id);
        $this->authorizeEdit($task);

        $title = $task->title;
        $leadId = $task->lead_id;

        $task->delete();

        // Histórico registrado manualmente (a tarefa já foi removida).
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

    /* ==================================================================
     * HELPERS PRIVADOS
     * ================================================================== */

    /**
     * Restringe o query builder de acordo com o papel do usuário.
     * admin/gestor → tudo; corretor → próprias + scope='company'.
     */
    private function scopeByRole(Builder $query, $user): void
    {
        if (in_array($user->role, ['admin', 'gestor'])) {
            return;
        }

        $query->where(function (Builder $q) use ($user) {
            $q->where('user_id', $user->id)
              ->orWhere('scope', 'company');
        });
    }

    /**
     * Autorização de LEITURA — corretor lê só próprias + company.
     */
    private function authorizeRead(Appointment $task): void
    {
        $user = Auth::user();
        if (in_array($user->role, ['admin', 'gestor'])) {
            return;
        }

        $ok = $task->user_id === $user->id || $task->scope === 'company';
        abort_if(!$ok, 403, 'Sem permissão pra visualizar esta tarefa.');
    }

    /**
     * Autorização de EDIÇÃO — corretor edita só as próprias.
     * (Mesmo scope='company' não pode ser alterado por não-dono.)
     */
    private function authorizeEdit(Appointment $task): void
    {
        $user = Auth::user();
        if (in_array($user->role, ['admin', 'gestor'])) {
            return;
        }

        abort_if($task->user_id !== $user->id, 403, 'Sem permissão pra editar esta tarefa.');
    }

    /**
     * Grava uma entrada no histórico do lead, se a tarefa estiver
     * vinculada a um. Tarefas internas (lead_id=null) ficam fora da
     * timeline do lead — isso é intencional.
     */
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
