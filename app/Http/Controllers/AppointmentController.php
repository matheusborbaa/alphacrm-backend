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

    $appointments = Appointment::where(function ($q) use ($start, $end) {
            $q->where(function ($q2) use ($start, $end) {
                $q2->whereBetween('starts_at', [$start, $end]);
            })->orWhere(function ($q2) use ($start, $end) {
                $q2->whereBetween('due_at', [$start, $end]);
            });
        })
       ->when(!in_array($user->role, ['admin','gestor']), function ($q) use ($user) {

    $q->where(function ($query) use ($user) {

        $query->where('user_id', $user->id)
              ->orWhere('scope', 'company');

    });

})
        ->orderByRaw('COALESCE(due_at, starts_at) ASC')
        ->get([
            'id',
            'title',
            'type',
            'task_kind',
            'starts_at',
            'due_at',
            'status',
            'completed_at',
            'lead_id',
            'user_id',
        ]);

    $now = \Carbon\Carbon::now();
    $appointments->transform(function ($app) use ($now) {
        $effective = $app->due_at ?: $app->starts_at;
        $app->effective_at = $effective ? $effective->toIso8601String() : null;
        $app->overdue = $app->status === 'pending'
            && $effective
            && $effective->lt($now);
        return $app;
    });

    return response()->json($appointments);
}

public function summary(Request $request)
{
    $request->validate([
        'date' => 'required|date'
    ]);

    $user = auth()->user();

    $start = \Carbon\Carbon::parse($request->date)->startOfDay();
    $end   = \Carbon\Carbon::parse($request->date)->endOfDay();

    $query = Appointment::where(function ($q) use ($start, $end) {
            $q->where(function ($q2) use ($start, $end) {
                $q2->whereBetween('starts_at', [$start, $end]);
            })->orWhere(function ($q2) use ($start, $end) {
                $q2->whereBetween('due_at', [$start, $end]);
            });
        })
        ->when(!in_array($user->role, ['admin','gestor']), function ($q) use ($user) {
            $q->where(function ($sub) use ($user) {
                $sub->where('user_id', $user->id)
                    ->orWhere('scope', 'company');
            });
        });

    $list = (clone $query)->get(['id','type','status','starts_at','due_at']);
    $now  = \Carbon\Carbon::now();

    $byType = [];
    foreach ($list as $a) {
        $t = $a->type ?: 'outro';
        $byType[$t] = ($byType[$t] ?? 0) + 1;
    }

    $overdue = $list->filter(function ($a) use ($now) {
        $eff = $a->due_at ?: $a->starts_at;
        return $a->status === 'pending' && $eff && $eff->lt($now);
    })->count();

    $completed = $list->where('status', 'completed')->count();
    $pending   = $list->where('status', 'pending')->count();

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

        if ($request->filled('date')) {
            $query->whereDate('starts_at', $request->date);
        }

        if ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('starts_at', [
                $request->from,
                $request->to
            ]);
        }

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

    public function update(Request $request, Appointment $appointment)
    {

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

    public function destroy(Appointment $appointment)
    {

        abort_if($appointment->user_id !== Auth::id(), 403);

        $appointment->delete();

        return response()->json(['success' => true]);
    }

    public function listUnified(Request $request)
    {
        $user = Auth::user();

        $query = Appointment::with([
            'lead:id,name',
            'user:id,name',
        ]);

        $this->applyRoleScope($query, $user);

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

        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

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

        $query->orderByRaw('completed_at IS NULL DESC')
              ->orderByRaw('COALESCE(due_at, starts_at) ASC')
              ->orderBy('id', 'desc');

        $perPage = min((int) $request->query('per_page', 50), 200);

        return response()->json($query->paginate($perPage));
    }

    private function isManagerUser($user): bool
    {
        $role = strtolower(trim((string) ($user->role ?? '')));
        return in_array($role, ['admin', 'gestor'], true);
    }

    private function applyRoleScope($query, $user): void
    {
        if ($this->isManagerUser($user)) {

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
