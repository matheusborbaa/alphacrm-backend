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
            'status'
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

    $appointments = Appointment::whereYear('starts_at', $request->year)
        ->whereMonth('starts_at', $request->month)
        ->when(!in_array($user->role, ['admin','gestor']), function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })
        ->get(['id','starts_at','type','status']);

    // Marca itens atrasados pra que o calendário mostre o indicador vermelho.
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
}
