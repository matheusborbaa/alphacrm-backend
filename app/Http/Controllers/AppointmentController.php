<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\LeadHistory;
class AppointmentController extends Controller
{

public function byDate(Request $request)
{
    $request->validate([
        'date' => 'required|date'
    ]);

    $user = auth()->user();

    $start = \Carbon\Carbon::parse($request->date)->startOfDay();
    $end   = \Carbon\Carbon::parse($request->date)->endOfDay();

    $appointments = Appointment::whereBetween('starts_at', [$start, $end])
       ->when(!in_array($user->role, ['admin','gestor']), function ($q) use ($user) {

    $q->where(function ($query) use ($user) {

        $query->where('user_id', $user->id)
              ->orWhere('scope', 'company');

    });

})
        ->orderBy('starts_at')
        ->get([
            'id',
            'title',
            'type',
            'starts_at',
            'status',
            'lead_id',  // usado pelo frontend pra navegar pro lead ao clicar
            'user_id',
        ]);

    // Marca cada agendamento como atrasado quando está pendente e já passou.
    $now = \Carbon\Carbon::now();
    $appointments->transform(function ($app) use ($now) {
        $app->overdue = $app->status === 'pending'
            && $app->starts_at
            && $app->starts_at->lt($now);
        return $app;
    });

    return response()->json($appointments);
}

/**
 * Resumo quantitativo do dia: totais por tipo, por situação e atrasadas.
 * Usado pelo painel superior da agenda.
 */
public function summary(Request $request)
{
    $request->validate([
        'date' => 'required|date'
    ]);

    $user = auth()->user();

    $start = \Carbon\Carbon::parse($request->date)->startOfDay();
    $end   = \Carbon\Carbon::parse($request->date)->endOfDay();

    $query = Appointment::whereBetween('starts_at', [$start, $end])
        ->when(!in_array($user->role, ['admin','gestor']), function ($q) use ($user) {
            $q->where(function ($sub) use ($user) {
                $sub->where('user_id', $user->id)
                    ->orWhere('scope', 'company');
            });
        });

    $list = (clone $query)->get(['id','type','status','starts_at']);
    $now  = \Carbon\Carbon::now();

    $byType = [];
    foreach ($list as $a) {
        $t = $a->type ?: 'outro';
        $byType[$t] = ($byType[$t] ?? 0) + 1;
    }

    $overdue = $list->filter(fn($a) =>
        $a->status === 'pending'
        && $a->starts_at
        && $a->starts_at->lt($now)
    )->count();

    $completed = $list->where('status', 'completed')->count();
    $pending   = $list->where('status', 'pending')->count();

    // Atrasadas globais (todas as datas anteriores ainda pendentes) —
    // útil pra mostrar o badge persistente no topo da agenda.
    $overdueGlobal = Appointment::where('status', 'pending')
        ->where('starts_at', '<', $now)
        ->when(!in_array($user->role, ['admin','gestor']), function ($q) use ($user) {
            $q->where(function ($sub) use ($user) {
                $sub->where('user_id', $user->id)
                    ->orWhere('scope', 'company');
            });
        })
        ->count();

    return response()->json([
        'date'             => $request->date,
        'total'            => $list->count(),
        'by_type'          => $byType,
        'completed'        => $completed,
        'pending'          => $pending,
        'overdue_day'      => $overdue,
        'overdue_global'   => $overdueGlobal,
    ]);
}

/**
 * Lista todas as tarefas atrasadas do usuário (status=pending e starts_at passado).
 */
public function overdueList(Request $request)
{
    $user = auth()->user();
    $now  = \Carbon\Carbon::now();

    $appointments = Appointment::where('status', 'pending')
        ->where('starts_at', '<', $now)
        ->when(!in_array($user->role, ['admin','gestor']), function ($q) use ($user) {
            $q->where(function ($sub) use ($user) {
                $sub->where('user_id', $user->id)
                    ->orWhere('scope', 'company');
            });
        })
        ->with(['lead:id,name'])
        ->orderBy('starts_at', 'asc')
        ->limit(50)
        ->get(['id','title','type','starts_at','status','lead_id']);

    $appointments->transform(function ($app) use ($now) {
        $app->overdue      = true;
        $app->overdue_days = $app->starts_at
            ? $app->starts_at->diffInDays($now)
            : 0;
        return $app;
    });

    return response()->json($appointments);
}

public function byMonth(Request $request)
{
    $request->validate([
        'year' => 'required|integer',
        'month' => 'required|integer'
    ]);

    $user = auth()->user();

    // Pega tudo no mês tanto pelo starts_at (visitas/reuniões) quanto pelo
    // due_at (tasks/follow-ups). Sem essa union, tasks com só due_at nunca
    // apareciam no calendário, apesar de aparecerem na lista.
    $appointments = Appointment::where(function ($q) use ($request) {
            $q->where(function ($q2) use ($request) {
                $q2->whereYear('starts_at', $request->year)
                   ->whereMonth('starts_at', $request->month);
            })->orWhere(function ($q2) use ($request) {
                $q2->whereYear('due_at', $request->year)
                   ->whereMonth('due_at', $request->month);
            });
        })
        ->when(!in_array($user->role, ['admin','gestor']), function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })
        ->get(['id','starts_at','due_at','type','status']);

    // Marca itens atrasados pra que o calendário mostre o indicador vermelho.
    // Considera o prazo efetivo (due_at tem prioridade pra tasks; starts_at
    // pra visitas).
    $now = \Carbon\Carbon::now();
    $appointments->transform(function ($app) use ($now) {
        $effective = $app->due_at ?: $app->starts_at;
        $app->overdue = $app->status === 'pending'
            && $effective
            && $effective->lt($now);
        return $app;
    });

    return response()->json($appointments);
}
    /**
     * @group Agenda
     *
     * Lista os compromissos do corretor logado.
     * Pode ser usado tanto para lista quanto para calendário.
     *
     * @authenticated
     */
public function reschedule(Request $request, $id)
{
    $request->validate([
        'starts_at' => 'required|date'
    ]);

    $appointment = \App\Models\Appointment::findOrFail($id);
    $id_lead = $appointment->lead_id;

    $appointment->update([
        'starts_at' => $request->starts_at,
        'status' => 'scheduled'
    ]);

        $leadalteracao = \App\Models\lead::findOrFail($id_lead);
        $leadalteracao->update([
        'status_id' => 7
    ]);

    LeadHistory::create([
    'lead_id' => $id_lead,
    'user_id' => auth()->id(),
    'type' => 'update',
    'description' => 'Atividade: '.$id.' foi alterada pelo usuário.('.auth()->id().')'
]);

    return response()->json([
        'success' => true,
        'message' => 'Remarcado com sucesso'
    ]);
}
public function complete($id)
{
    $appointment = \App\Models\Appointment::findOrFail($id);
    $id_lead = $appointment->lead_id;
    $appointment->update([
        'status' => 'completed'
    ]);

    
     LeadHistory::create([
    'lead_id' => $id_lead,
    'user_id' => auth()->id(),
    'type' => 'update',
    'description' => 'Atividade: '.$id.' foi marcada como completada pelo usuário.('.auth()->id().')'
]);
$leadbuscar = \App\Models\Lead::findOrFail($id_lead);
    $leadbuscar->update([
        'status_id' => 2
    ]);    
LeadHistory::create([
    'lead_id' => $id_lead,
    'user_id' => auth()->id(),
    'type' => 'update',
    'description' => 'Status do Lead foi alterado para em atendimento'
]);
    return response()->json([
        'success' => true,
        'message' => 'Visita concluída'
    ]);
}
  public function show($id)
    {
        $appointment = Appointment::with([
            'user:id,name',
            'lead:id,name'
        ])->findOrFail($id);

        return response()->json([
            'id' => $appointment->id,
            'title' => $appointment->title,
            'type' => $appointment->type,
            'starts_at' => $appointment->starts_at,
            'status' => $appointment->status,

            'user' => $appointment->user,
            'lead' => $appointment->lead,
        ]);
    }




    public function index(Request $request)
    {
        $user = Auth::user();

        $query = Appointment::where('user_id', $user->id)
            ->with('lead:id,name');

        // 📋 Lista por dia
        if ($request->filled('date')) {
            $query->whereDate('starts_at', $request->date);
        }

        // 🗓️ Calendário por intervalo
        if ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('starts_at', [
                $request->from,
                $request->to
            ]);
        }

        // filtros opcionais
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        return response()->json(
            $query->orderBy('starts_at')->get()
        );
    }

    /**
     * @group Agenda
     *
     * Cria um novo compromisso na agenda do corretor.
     *
     * @authenticated
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'title'       => 'required|string',
            'type'        => 'required|string',
            'description' => 'nullable|string',
            'lead_id'     => 'nullable|exists:leads,id',
            'starts_at'   => 'required|date',
            'ends_at'     => 'nullable|date|after_or_equal:starts_at',
        ]);

        $appointment = Appointment::create([
            ...$data,
            'user_id' => Auth::id(),
            'status'  => 'pending',
        ]);

        LeadHistory::create([
    'lead_id' => $appointment->lead_id,
    'user_id' => Auth::id(),
    'type' => 'appointment_created',
    'description' => 'Tarefa criada: ' . $appointment->type,
]);

        return response()->json($appointment, 201);
    }

    /**
     * @group Agenda
     *
     * Atualiza um compromisso da agenda.
     *
     * @authenticated
     */
    public function update(Request $request, Appointment $appointment)
    {
        // 🔒 segurança: só o dono pode editar
        abort_if($appointment->user_id !== Auth::id(), 403);

        $data = $request->validate([
            'title'       => 'sometimes|string',
            'type'        => 'sometimes|string',
            'description' => 'nullable|string',
            'lead_id'     => 'nullable|exists:leads,id',
            'starts_at'   => 'sometimes|date',
            'ends_at'     => 'nullable|date|after_or_equal:starts_at',
            'status'      => 'sometimes|string',
        ]);

        $appointment->update($data);

        return response()->json($appointment);
    }

    /**
     * @group Agenda
     *
     * Remove um compromisso da agenda.
     *
     * @authenticated
     */
    public function destroy(Appointment $appointment)
    {
        // 🔒 segurança: só o dono pode excluir
        abort_if($appointment->user_id !== Auth::id(), 403);

        $appointment->delete();

        return response()->json(['success' => true]);
    }

    /**
     * @group Agenda
     *
     * Listagem unificada — usada pelo MODO LISTA da página /agenda.php.
     * Diferente de /tasks (que é específico de type=task), este endpoint
     * enxerga qualquer tipo de Appointment (task/visit/call/meeting/etc).
     *
     * Filtros suportados (todos opcionais):
     *   filter    = today | overdue | upcoming | done | open
     *   type      = task | visit | call | meeting | ... (sem valor = todos)
     *   lead_id   = id do lead
     *   user_id   = só admin/gestor pode filtrar por outro user
     *   priority  = low | medium | high
     *   q         = busca no título
     *   per_page  = default 50 (máx 200)
     *
     * Ordena por COALESCE(due_at, starts_at) — "data efetiva" do item.
     *
     * Aplica as MESMAS regras de escopo e privacidade do TaskController:
     *   - admin/gestor → tudo, MENOS tarefa pessoal de outro corretor
     *     (scope='private' + sem lead_id + não é dono/criador)
     *   - corretor    → próprias (user_id/created_by) + scope='company'
     *
     * @authenticated
     */
    public function listUnified(Request $request)
    {
        $user = Auth::user();

        $query = Appointment::with([
            'lead:id,name',
            'user:id,name',
        ]);

        $this->applyRoleScope($query, $user);

        // ---- filtro por ESTADO (usa COALESCE(due_at, starts_at) como data efetiva) ----
        switch ($request->query('filter')) {
            case 'today':
                $query->whereNull('completed_at')
                      ->whereRaw('DATE(COALESCE(due_at, starts_at)) = ?', [now()->toDateString()]);
                break;
            case 'overdue':
                $query->whereNull('completed_at')
                      ->whereRaw('COALESCE(due_at, starts_at) IS NOT NULL')
                      ->whereRaw('COALESCE(due_at, starts_at) < ?', [now()]);
                break;
            case 'upcoming':
                $query->whereNull('completed_at')
                      ->whereRaw('COALESCE(due_at, starts_at) IS NOT NULL')
                      ->whereRaw('DATE(COALESCE(due_at, starts_at)) > ?', [now()->toDateString()]);
                break;
            case 'done':
                $query->whereNotNull('completed_at');
                break;
            case 'open':
                $query->whereNull('completed_at');
                break;
            // sem filter → retorna tudo (respeitando o scope de role)
        }

        // ---- tipo (task | visit | call | ... ) ----
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // ---- lead / user (admin) / prioridade / busca ----
        if ($request->filled('lead_id')) {
            $query->where('lead_id', $request->lead_id);
        }

        if ($request->filled('user_id') && $this->isManagerUser($user)) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('q')) {
            $query->where('title', 'like', '%' . $request->q . '%');
        }

        // Abertas primeiro (COALESCE data asc); concluídas vão pro fim.
        $query->orderByRaw('completed_at IS NULL DESC')
              ->orderByRaw('COALESCE(due_at, starts_at) ASC')
              ->orderBy('id', 'desc');

        $perPage = min((int) $request->query('per_page', 50), 200);

        return response()->json($query->paginate($perPage));
    }

    /* ==================================================================
     * HELPERS de permissão — replicados do TaskController pra manter
     * consistência sem criar dependência cruzada.
     * ================================================================== */
    private function isManagerUser($user): bool
    {
        $role = strtolower(trim((string) ($user->role ?? '')));
        return in_array($role, ['admin', 'gestor'], true);
    }

    /**
     * Aplica o scope de papel no query builder. Replica EXATAMENTE a regra
     * do TaskController::scopeByRole — inclusive a privacidade de manager
     * sobre tarefa pessoal de corretor (scope=private + sem lead_id + não é
     * dono/criador).
     */
    private function applyRoleScope($query, $user): void
    {
        if ($this->isManagerUser($user)) {
            // Manager vê tudo exceto o "caderninho pessoal" do corretor.
            $query->where(function ($q) use ($user) {
                $q->where('scope', 'company')
                  ->orWhereNotNull('lead_id')
                  ->orWhere('user_id', $user->id)
                  ->orWhere('created_by', $user->id);
            });
            return;
        }

        $query->where(function ($q) use ($user) {
            $q->where('user_id', $user->id)
              ->orWhere('created_by', $user->id)
              ->orWhere('scope', 'company');
        });
    }
}
