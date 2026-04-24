<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\LeadHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
 *   - admin/gestor: enxergam e editam tudo, EXCETO tarefas "internas"
 *                   de outros corretores. "Interna" = scope='private'
 *                   SEM lead vinculado — é a lista pessoal do corretor,
 *                   usada pra se organizar; ninguém (nem admin) bisbilhota.
 *                   Se a tarefa tem lead_id (é trabalho) ou scope='company'
 *                   (é da empresa), admin/gestor VÊ normalmente.
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
            // Subtipo da tarefa — usado pelas regras de obrigatoriedade
            // pra exigir tipo específico (ligação, visita, anotação, genérica).
            'task_kind'   => 'nullable|in:ligacao,visita,anotacao,generica',
            // admin/gestor podem atribuir a outro user
            'user_id'     => 'nullable|exists:users,id',
            // admin/gestor podem marcar a tarefa como da EMPRESA (visível pra todos)
            'scope'       => 'nullable|in:private,company',
        ]);

        $user = Auth::user();

        // Corretor só cria tarefa pra si mesmo.
        $assigneeId = isset($data['user_id']) && $this->isManager($user)
            ? $data['user_id']
            : $user->id;

        // Scope só pode ser 'company' se quem está criando é admin/gestor.
        // Corretor sempre cria tarefas privadas, mesmo que mande scope=company no payload.
        $scope = (isset($data['scope']) && $this->isManager($user))
            ? $data['scope']
            : 'private';

        $task = Appointment::create([
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'lead_id'     => $data['lead_id'] ?? null,
            'due_at'      => $data['due_at'] ?? null,
            'priority'    => $data['priority'] ?? Appointment::PRIORITY_MEDIUM,
            'reminder_at' => $data['reminder_at'] ?? null,
            'type'        => Appointment::TYPE_TASK,
            'task_kind'   => $data['task_kind'] ?? Appointment::KIND_GENERICA,
            'status'      => Appointment::STATUS_PENDING,
            'user_id'     => $assigneeId,
            'scope'       => $scope,
            'created_by'  => $user->id,
        ]);

        $this->logLeadHistory($task, 'task_created',
            'Tarefa criada: ' . $task->title
        );

        return response()->json($task->load(['lead:id,name', 'user:id,name']), 201);
    }

    /* ==================================================================
     * SHOW — retorna uma tarefa com comentários já carregados
     * ================================================================== */
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
            'task_kind'   => 'nullable|in:ligacao,visita,anotacao,generica',
            'user_id'     => 'nullable|exists:users,id',
            'scope'       => 'nullable|in:private,company',
        ]);

        // Reatribuição só por admin/gestor.
        if (isset($data['user_id']) && !$this->isManager(Auth::user())) {
            unset($data['user_id']);
        }

        // Mudança de scope (private <-> company) só por admin/gestor.
        if (isset($data['scope']) && !$this->isManager(Auth::user())) {
            unset($data['scope']);
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

    /* ==================================================================
     * REOPEN — desmarca a conclusão (útil se concluiu por engano)
     * ================================================================== */
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
     * Normaliza o valor do campo role — evita falsos negativos por
     * espaço em branco ou caixa alta vindo do banco.
     */
    private function normalizedRole($user): string
    {
        return strtolower(trim((string) ($user->role ?? '')));
    }

    /**
     * True se o usuário é admin ou gestor (tem acesso total às tarefas).
     */
    private function isManager($user): bool
    {
        return in_array($this->normalizedRole($user), ['admin', 'gestor'], true);
    }

    /**
     * Restringe o query builder de acordo com o papel do usuário.
     *
     * admin/gestor → vê tudo, MENOS tarefa "interna" de outro corretor
     *                (scope='private' E sem lead_id E não é dele próprio/criada
     *                por ele). Isso respeita a privacidade da lista pessoal
     *                que o corretor usa pra se organizar.
     * corretor     → tarefas onde ele é dono (user_id), criador
     *                (created_by) ou scope='company'.
     */
    private function scopeByRole(Builder $query, $user): void
    {
        if ($this->isManager($user)) {
            // Filtra fora o "cantinho pessoal" do corretor. Só é considerado
            // pessoal se: scope=private AND lead_id IS NULL AND não é do próprio manager.
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

    /**
     * True se a tarefa é "pessoal de outro corretor" do ponto de vista
     * do usuário atual — ou seja: scope=private, sem lead, e nem dono
     * nem criador é o $user. Usado pra aplicar a regra de privacidade.
     */
    private function isOthersPrivateTask(Appointment $task, $user): bool
    {
        if ($task->scope !== 'private')   return false;
        if (!is_null($task->lead_id))      return false;
        if ((int) $task->user_id    === (int) $user->id) return false;
        if ((int) $task->created_by === (int) $user->id) return false;
        return true;
    }

    /**
     * LEITURA
     *   - manager: tudo, menos tarefa pessoal de outro corretor.
     *   - corretor: dono, criador ou scope='company'.
     */
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

    /**
     * EDIÇÃO de campos (title, due_at, priority, scope, user_id).
     * Só pode editar: manager, dono ou quem CRIOU a tarefa.
     * Tarefas scope='company' só podem ser editadas pelo criador/dono/manager
     * — visualização livre não implica edição.
     */
    private function authorizeEdit(Appointment $task): void
    {
        $user = Auth::user();

        if ($this->isManager($user)) {
            // Mesma regra de privacidade da leitura: manager não mexe
            // na lista pessoal do corretor. Se nem pode ver, não pode editar.
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

    /**
     * CONCLUIR / REABRIR — regra mais frouxa que edição.
     *   - manager, dono ou criador: sempre.
     *   - scope='company': qualquer corretor pode concluir (é uma tarefa
     *     da empresa, quem fez registra quem concluiu via completed_by).
     */
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
