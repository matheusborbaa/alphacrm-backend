<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\CustomField;
use App\Models\LeadCustomFieldValue;
use App\Services\LeadStatusRequirementValidator;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
    use App\Http\Resources\LeadResource;
use App\Models\LeadHistory;

class LeadController extends Controller
{

        use AuthorizesRequests;
public function update(Request $request, Lead $lead, LeadStatusRequirementValidator $validator)
{

    $this->authorize('update', $lead);

    $data = $request->validate([
        'name'               => 'sometimes|string|max:255',
        'email'              => 'sometimes|nullable|email|max:255',
        'phone'              => 'sometimes|nullable|string|max:20',
        'whatsapp'           => 'sometimes|nullable|string|max:20',
        'source_id'          => 'sometimes|nullable|exists:lead_sources,id',
        'status_id'          => 'sometimes|nullable|exists:lead_status,id',
        'lead_substatus_id'  => 'sometimes|nullable|exists:lead_substatus,id',
        'assigned_user_id'   => 'sometimes|nullable|exists:users,id',
        'empreendimento_id'  => 'sometimes|nullable|exists:empreendimentos,id',
        'channel'            => 'sometimes|nullable|string|max:100',
        'campaign'           => 'sometimes|nullable|string|max:255',
        'temperature'        => 'sometimes|nullable|in:quente,morno,frio',
        'value'              => 'sometimes|nullable|numeric',
        'city_of_interest'   => 'sometimes|nullable|string|max:120',
        'region_of_interest' => 'sometimes|nullable|string|max:120',

        'custom_field_values'         => 'sometimes|array',
        'custom_field_values.*.slug'  => 'required_with:custom_field_values|string|exists:custom_fields,slug',
        'custom_field_values.*.value' => 'nullable',
    ]);

    $authUser  = auth()->user();
    $isManager = $authUser && in_array(
        strtolower(trim((string) ($authUser->role ?? ''))),
        ['admin', 'gestor'],
        true
    );
    if (!$isManager) {

        $strictManagerOnly = ['assigned_user_id'];

        $fillableWhenEmpty = ['source_id', 'channel', 'campaign'];

        foreach ($strictManagerOnly as $f) {
            if (!array_key_exists($f, $data)) continue;
            $current   = $lead->{$f};
            $attempted = $data[$f];
            $a = $current   === null ? '' : (string) $current;
            $b = $attempted === null ? '' : (string) $attempted;
            if ($a !== $b) {
                abort(403, 'Só gestor/admin pode alterar este campo.');
            }
            unset($data[$f]);
        }

        foreach ($fillableWhenEmpty as $f) {
            if (!array_key_exists($f, $data)) continue;
            $current   = $lead->{$f};
            $attempted = $data[$f];
            $a = $current   === null ? '' : (string) $current;
            $b = $attempted === null ? '' : (string) $attempted;

            if ($a === $b) {
                unset($data[$f]);
                continue;
            }

            if ($a === '') {
                continue;
            }

            abort(403, 'Só gestor/admin pode alterar este campo.');
        }
    }

    $customValues = $data['custom_field_values'] ?? [];
    unset($data['custom_field_values']);

    $validator->validate(
        $lead,
        array_key_exists('status_id', $data)         ? $data['status_id']         : null,
        array_key_exists('lead_substatus_id', $data) ? $data['lead_substatus_id'] : null,
        $data,
        $customValues
    );

    $previousAssignedUserId = $lead->assigned_user_id;
    $newAssignedUserId      = array_key_exists('assigned_user_id', $data)
        ? $data['assigned_user_id']
        : null;
    $reassigned = array_key_exists('assigned_user_id', $data)
        && $newAssignedUserId
        && $newAssignedUserId != $previousAssignedUserId;

    $originalAttrs = $lead->getAttributes();

    if (!empty($data)) {
        $lead->update($data);
    }

    if ($reassigned) {
        $target = \App\Models\User::find($newAssignedUserId);
        if ($target && $target->id !== auth()->id()) {
            try {
                $target->notify(new \App\Notifications\LeadAssignedNotification($lead->fresh()));
            } catch (\Throwable $e) {
                \Log::warning('Falha ao notificar corretor de reatribuição', [
                    'lead_id' => $lead->id,
                    'user_id' => $target->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    $customDiffs = [];
    if (!empty($customValues)) {
        $customDiffs = $this->saveCustomValues($lead, $customValues);
    }

    $this->logFieldChanges($lead, $originalAttrs, $customDiffs);

    return response()->json(['success' => true]);
}

private function logFieldChanges(Lead $lead, array $originalAttrs, array $customDiffs): void
{
    if (!auth()->check()) return;

    $labels = [
        'name'               => 'Nome',
        'email'              => 'E-mail',
        'phone'              => 'Telefone',
        'whatsapp'           => 'WhatsApp',
        'source_id'          => 'Origem',
        'assigned_user_id'   => 'Corretor responsável',
        'empreendimento_id'  => 'Empreendimento',
        'channel'            => 'Canal',
        'campaign'           => 'Campanha',
        'temperature'        => 'Temperatura',
        'value'              => 'Valor estimado',
        'city_of_interest'   => 'Cidade de interesse',
        'region_of_interest' => 'Região de interesse',
    ];

    $resolvers = [
        'assigned_user_id' => fn($id) => $id ? optional(\App\Models\User::find($id))->name : null,
        'source_id'        => fn($id) => $id ? optional(\App\Models\LeadSource::find($id))->name : null,
        'empreendimento_id'=> fn($id) => $id ? optional(\App\Models\Empreendimento::find($id))->name : null,
    ];

    foreach ($labels as $field => $label) {
        if (!array_key_exists($field, $originalAttrs)) continue;

        $before = $originalAttrs[$field];
        $after  = $lead->{$field};

        $a = $before === null ? '' : (string) $before;
        $b = $after  === null ? '' : (string) $after;
        if ($a === $b) continue;

        $from = isset($resolvers[$field]) ? $resolvers[$field]($before) : $before;
        $to   = isset($resolvers[$field]) ? $resolvers[$field]($after)  : $after;

        LeadHistory::create([
            'lead_id'     => $lead->id,
            'user_id'     => auth()->id(),
            'type'        => 'field_change',
            'description' => $label,
            'from'        => $from !== null ? (string) $from : null,
            'to'          => $to   !== null ? (string) $to   : null,
        ]);
    }

    LeadHistory::logFieldChangeDiffs($lead, $customDiffs, auth()->id());
}

private function saveCustomValues(Lead $lead, array $values): array
{
    $diffs  = [];
    $slugs  = collect($values)->pluck('slug')->unique();
    $fields = CustomField::whereIn('slug', $slugs)->get()->keyBy('slug');

    $oldByFieldId = LeadCustomFieldValue::where('lead_id', $lead->id)
        ->whereIn('custom_field_id', $fields->pluck('id'))
        ->get()
        ->keyBy('custom_field_id');

    foreach ($values as $entry) {
        $field = $fields->get($entry['slug']);
        if (!$field) continue;

        $value = $entry['value'] ?? null;
        if ($field->type === 'checkbox' && is_array($value)) {
            $value = json_encode(array_values($value), JSON_UNESCAPED_UNICODE);
        } elseif ($value !== null) {
            $value = (string) $value;
        }

        $old = $oldByFieldId->get($field->id)?->value;

        LeadCustomFieldValue::updateOrCreate(
            ['lead_id' => $lead->id, 'custom_field_id' => $field->id],
            ['value'   => $value]
        );

        $a = $old   === null ? '' : (string) $old;
        $b = $value === null ? '' : (string) $value;
        if ($a !== $b) {
            $diffs[] = [
                'label' => $field->name ?: $field->slug,
                'from'  => $old,
                'to'    => $value,
            ];
        }
    }

    return $diffs;
}

    public function index(Request $request)
{

    $user = $request->user();

    $query = Lead::with([
        'corretor:id,name',
        'status:id,name,color_hex',
        'substatus:id,lead_status_id,name,color_hex',
        'source:id,name',
        'empreendimento:id,name',
        'interactions' => function ($q) {
            $q->with('user:id,name')
              ->latest()
              ->limit(1);
        },
    ]);

    $query->visibleTo($user);

    if ($request->filled('visible_to_user_id')) {
        $peer = \App\Models\User::find((int) $request->visible_to_user_id);
        if (!$peer) {

            $query->whereRaw('1 = 0');
        } else {
            $query->visibleTo($peer);
        }
    }

    $this->applyLeadFilters($request, $query);

   if ($request->filled('search')) {

    $search = $request->search;

    $query->where(function ($q) use ($search) {

        $q->where('name', 'like', "%{$search}%")
          ->orWhere('phone', 'like', "%{$search}%")
          ->orWhere('email', 'like', "%{$search}%")

          ->orWhereHas('empreendimentos', function ($q2) use ($search) {
              $q2->where('name', 'like', "%{$search}%");
          })

          ->orWhereHas('status', function ($q2) use ($search) {
              $q2->where('name', 'like', "%{$search}%");
          })

          ->orWhereHas('corretor', function ($q2) use ($search) {
              $q2->where('name', 'like', "%{$search}%");
          })

          ->orWhereDate('created_at', $search);
    });

}

    $perPage = (int) $request->input('per_page', 15);
    $perPage = max(5, min(100, $perPage));

    return LeadResource::collection(
        $query->orderByDesc('created_at')->paginate($perPage)
    );
}

    public function counts(Request $request)
    {
        $user = $request->user();

        $base = Lead::query()->visibleTo($user);

        $this->applyLeadFilters($request, $base, ['temperature']);

        if ($request->filled('search')) {
            $search = $request->search;
            $base->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhereHas('empreendimentos', fn($q2) => $q2->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('status',          fn($q2) => $q2->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('corretor',        fn($q2) => $q2->where('name', 'like', "%{$search}%"))
                  ->orWhereDate('created_at', $search);
            });
        }

        $cloneBase = fn() => (clone $base);

        return response()->json([
            'quente'            => $cloneBase()->where('temperature', 'quente')->count(),
            'morno'             => $cloneBase()->where('temperature', 'morno')->count(),
            'frio'              => $cloneBase()->where('temperature', 'frio')->count(),
            'sem_temperatura'   => $cloneBase()->whereNull('temperature')->count(),
            'total'             => $cloneBase()->count(),
            'em_atendimento'    => $cloneBase()->whereHas('status', fn($q) => $q->where('name', 'Em Atendimento'))->count(),
            'sem_interacao_10d' => $cloneBase()->where(function ($q) {
                                       $q->whereNull('last_interaction_at')
                                         ->orWhere('last_interaction_at', '<=', now()->subDays(10));
                                   })->count(),
        ]);
    }

    private function applyLeadFilters(Request $request, $query, array $skip = []): void
    {
        $should = fn(string $k) => $request->filled($k) && !in_array($k, $skip, true);

        $csv = function ($raw) {
            if ($raw === null || $raw === '') return [];
            $arr = is_array($raw) ? $raw : explode(',', (string) $raw);
            return array_values(array_filter(array_map('trim', $arr)));
        };

        if ($should('codigo')) {
            $query->where('id', (int) $request->codigo);
        }

        if ($should('empreendimento')) {
            $vals = $csv($request->empreendimento);
            if (!empty($vals)) $query->whereIn('empreendimento_id', $vals);
        }

        if ($should('funil')) {
            $vals = $csv($request->funil);
            if (!empty($vals)) $query->whereIn('status_id', $vals);
        }

        if ($should('substatus')) {
            $vals = $csv($request->substatus);
            if (!empty($vals)) $query->whereIn('lead_substatus_id', $vals);
        }

        if ($should('responsavel')) {
            $vals = $csv($request->responsavel);
            if (!empty($vals)) $query->whereIn('assigned_user_id', $vals);
        }

        if ($should('temperature')) {
            $vals = $csv($request->temperature);
            if (!empty($vals)) $query->whereIn('temperature', $vals);
        }

        if ($should('source_id')) {
            $vals = $csv($request->source_id);
            if (!empty($vals)) $query->whereIn('source_id', $vals);
        }

        if ($should('channel')) {
            $vals = $csv($request->channel);
            if (!empty($vals)) $query->whereIn('channel', $vals);
        }

        if ($should('campaign')) {
            $vals = $csv($request->campaign);
            if (!empty($vals)) $query->whereIn('campaign', $vals);
        }

        if ($should('sem_interacao_dias')) {
            $dias = (int) $request->sem_interacao_dias;
            if ($dias > 0) {
                $query->where(function ($q) use ($dias) {
                    $q->whereNull('last_interaction_at')
                      ->orWhere('last_interaction_at', '<=', now()->subDays($dias));
                });
            }
        }

        if ($should('created_from')) {
            $query->whereDate('created_at', '>=', $request->created_from);
        }
        if ($should('created_to')) {
            $query->whereDate('created_at', '<=', $request->created_to);
        }
        if ($should('updated_from')) {
            $query->whereDate('updated_at', '>=', $request->updated_from);
        }
        if ($should('updated_to')) {
            $query->whereDate('updated_at', '<=', $request->updated_to);
        }

        if ($should('tarefa')) {
            $val = trim((string) $request->tarefa);

            if ($val === 'ativas') {
                $query->whereHas('appointments', function ($q) {
                    $q->where('type', 'task')
                      ->whereNull('completed_at');
                });
            } elseif ($val === 'atrasadas') {
                $query->whereHas('appointments', function ($q) {
                    $q->where('type', 'task')
                      ->whereNull('completed_at')
                      ->whereNotNull('scheduled_at')
                      ->where('scheduled_at', '<', now());
                });
            } elseif ($val === 'sem') {
                $query->whereDoesntHave('appointments', function ($q) {
                    $q->where('type', 'task')
                      ->whereNull('completed_at');
                });
            }
        }
    }

public function store(Request $request)
{
    $user = auth()->user();

    $data = $request->validate([
        'name'               => 'required|string|max:255',
        'phone'              => 'required|string|max:30',
        'whatsapp'           => 'nullable|string|max:30',
        'email'              => 'nullable|email|max:255',
        'channel'            => 'nullable|string|max:80',
        'campaign'           => 'nullable|string|max:150',
        'empreendimento_id'  => 'nullable|integer|exists:empreendimentos,id',
        'city_of_interest'   => 'nullable|string|max:120',
        'region_of_interest' => 'nullable|string|max:120',
        'assigned_user_id'   => 'nullable|integer|exists:users,id',
        'force'              => 'nullable|boolean',
    ]);

    $forceBypass = !empty($data['force']);
    if ($forceBypass && !in_array($user->role, ['admin', 'gestor'], true)) {
        return response()->json([
            'message' => 'Você não tem permissão para cadastrar um lead duplicado. Procure um gerente ou administrador.',
        ], 403);
    }

    if (!$forceBypass) {
        $duplicates = $this->findDuplicateLeads(
            $data['phone']    ?? null,
            $data['whatsapp'] ?? null,
            $data['email']    ?? null,
        );

        if ($duplicates->isNotEmpty()) {

            $isManager = in_array($user->role, ['admin', 'gestor'], true);
            $msg = $isManager
                ? 'Lead possivelmente duplicado. Revise os resultados antes de confirmar o cadastro.'
                : 'Lead já cadastrado. Procure o gerente ou administrador.';

            return response()->json([
                'message'    => $msg,
                'duplicates' => $duplicates,
            ], 409);
        }
    }

    $assignedUserId = $user->role === 'admin'
        ? ($data['assigned_user_id'] ?? null)
        : $user->id;

    $lead = \App\Models\Lead::create(array_merge(
        collect($data)->except(['force', 'assigned_user_id'])->toArray(),
        [
            'assigned_user_id' => $assignedUserId,
            'status_id'        => 1,
        ]
    ));

    if (!$assignedUserId) {

        app(\App\Services\LeadAssignmentService::class)->assign($lead);
    } else {

        $target = \App\Models\User::find($assignedUserId);
        if ($target && $target->id !== auth()->id()) {
            try {
                $target->notify(new \App\Notifications\LeadAssignedNotification($lead->fresh()));
            } catch (\Throwable $e) {
                \Log::warning('Falha ao notificar corretor de novo lead (admin)', [
                    'lead_id' => $lead->id,
                    'user_id' => $target->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    LeadHistory::create([
        'lead_id'     => $lead->id,
        'user_id'     => auth()->id(),
        'type'        => 'created',
        'description' => 'Lead criado'
    ]);

    return response()->json($lead);
}

public function checkDuplicates(Request $request)
{
    $duplicates = $this->findDuplicateLeads(
        $request->query('phone'),
        $request->query('whatsapp'),
        $request->query('email'),
    );

    return response()->json([
        'count'      => $duplicates->count(),
        'duplicates' => $duplicates,
    ]);
}

public function queue(Request $request)
{
    $this->ensureManager();

    $leads = Lead::query()
        ->whereNull('assigned_user_id')
        ->select([
            'id', 'name', 'phone', 'whatsapp', 'email',
            'channel', 'campaign',
            'empreendimento_id',
            'city_of_interest',
            'status_id',
            'created_at',
        ])
        ->with([
            'status:id,name,color_hex',
            'empreendimento:id,name',
        ])
        ->orderBy('created_at', 'asc')
        ->get();

    $now = now();

    $items = $leads->map(function ($lead) use ($now) {

        $minutes = (int) abs($lead->created_at->diffInMinutes($now));
        return [
            'id'               => $lead->id,
            'name'             => $lead->name,
            'phone'            => $lead->phone,
            'whatsapp'         => $lead->whatsapp,
            'email'            => $lead->email,
            'channel'          => $lead->channel,
            'campaign'         => $lead->campaign,
            'city_of_interest' => $lead->city_of_interest,
            'status'           => $lead->status ? [
                'id'        => $lead->status->id,
                'name'      => $lead->status->name,
                'color_hex' => $lead->status->color_hex ?? null,
            ] : null,
            'empreendimento'   => $lead->empreendimento ? [
                'id'   => $lead->empreendimento->id,
                'name' => $lead->empreendimento->name,
            ] : null,
            'created_at'       => $lead->created_at?->toIso8601String(),
            'minutes_waiting'  => $minutes,
        ];
    });

    $availableBrokers = \App\Models\User::where('role', 'corretor')
        ->where('active', true)
        ->where('status_corretor', 'disponivel')
        ->count();

    return response()->json([
        'total'              => $items->count(),
        'available_brokers'  => $availableBrokers,
        'oldest_minutes'     => $items->first()['minutes_waiting'] ?? 0,
        'items'              => $items,
    ]);
}

public function queueCount(Request $request)
{
    $this->ensureManager();

    $total = Lead::whereNull('assigned_user_id')->count();

    $oldestMinutes = 0;
    if ($total > 0) {
        $oldest = Lead::whereNull('assigned_user_id')
            ->orderBy('created_at', 'asc')
            ->value('created_at');
        if ($oldest) {

            $oldestMinutes = (int) abs(\Carbon\Carbon::parse($oldest)->diffInMinutes(now()));
        }
    }

    return response()->json([
        'total'          => $total,
        'oldest_minutes' => $oldestMinutes,
    ]);
}

private function ensureManager(): void
{
    $user = auth()->user();
    if (!$user) abort(401);

    $role = strtolower(trim((string) ($user->role ?? '')));
    if (!in_array($role, ['admin', 'gestor'], true)) {
        abort(403, 'Apenas admin e gestor podem visualizar a fila.');
    }
}

protected function findDuplicateLeads(?string $phone, ?string $whatsapp, ?string $email)
{

    $phoneDigits    = \App\Models\Lead::normalizePhone($phone);
    $whatsappDigits = \App\Models\Lead::normalizePhone($whatsapp);

    if (!$phoneDigits && !$whatsappDigits && !$email) {
        return collect();
    }

    $query = \App\Models\Lead::query()
        ->select(['id', 'name', 'phone', 'whatsapp', 'email', 'status_id', 'assigned_user_id', 'created_at'])
        ->with(['status:id,name', 'corretor:id,name']);

    $query->where(function ($q) use ($phoneDigits, $whatsappDigits, $email) {

        if ($phoneDigits) {
            $q->orWhere('phone_normalized', $phoneDigits);
            $q->orWhere('whatsapp_normalized', $phoneDigits);
        }
        if ($whatsappDigits && $whatsappDigits !== $phoneDigits) {
            $q->orWhere('phone_normalized', $whatsappDigits);
            $q->orWhere('whatsapp_normalized', $whatsappDigits);
        }
        if ($email) {
            $q->orWhere('email', $email);
        }
    });

    return $query->limit(10)->get();
}

public function destroy(Request $request, Lead $lead)
{

    $this->authorize('delete', $lead);

    $reason = (string) $request->input('reason', '');
    if (mb_strlen($reason) > 500) {
        $reason = mb_substr($reason, 0, 500);
    }

    $lead->update([
        'deleted_by_user_id' => auth()->id(),
        'deletion_reason'    => $reason !== '' ? $reason : null,
    ]);

    LeadHistory::create([
        'lead_id'     => $lead->id,
        'user_id'     => auth()->id(),
        'type'        => 'soft_deleted',
        'description' => 'Lead enviado para lixeira' . ($reason !== '' ? ' — motivo: ' . $reason : ''),
    ]);


    $lead->delete();

    return response()->json([
        'success'           => true,
        'soft_deleted'      => true,
        'recoverable_until' => 'manualmente — não há expurgo automático',
    ]);
}

/**
 * LIXEIRA — listagem dos leads soft-deleted. Apenas admin.
 */
public function trash(Request $request)
{
    $this->ensureAdmin();

    $perPage = max(10, min(200, (int) $request->input('per_page', 50)));
    $search  = trim((string) $request->input('q', ''));

    $query = Lead::onlyTrashed()
        ->with([
            'status:id,name,color_hex',
            'corretor:id,name',
            'source:id,name',
        ])
        ->orderByDesc('deleted_at');

    if ($search !== '') {
        $like = '%' . $search . '%';
        $query->where(function ($q) use ($like) {
            $q->where('name',     'like', $like)
              ->orWhere('email',  'like', $like)
              ->orWhere('phone',  'like', $like)
              ->orWhere('whatsapp','like', $like);
        });
    }

    $page = $query->paginate($perPage);

    $rows = collect($page->items())->map(function ($l) {
        $deleter = $l->deleted_by_user_id
            ? \App\Models\User::query()->whereKey($l->deleted_by_user_id)->value('name')
            : null;
        return [
            'id'             => $l->id,
            'name'           => $l->name,
            'phone'          => $l->phone,
            'whatsapp'       => $l->whatsapp,
            'email'          => $l->email,
            'status'         => $l->status ? ['id' => $l->status->id, 'name' => $l->status->name, 'color_hex' => $l->status->color_hex] : null,
            'corretor'       => $l->corretor ? ['id' => $l->corretor->id, 'name' => $l->corretor->name] : null,
            'source'         => $l->source ? ['id' => $l->source->id, 'name' => $l->source->name] : null,
            'deleted_at'     => $l->deleted_at?->toIso8601String(),
            'deleted_by'     => $deleter ? ['id' => $l->deleted_by_user_id, 'name' => $deleter] : null,
            'deletion_reason'=> $l->deletion_reason,
            'created_at'     => $l->created_at?->toIso8601String(),
        ];
    });

    return response()->json([
        'data'         => $rows,
        'current_page' => $page->currentPage(),
        'per_page'     => $page->perPage(),
        'total'        => $page->total(),
        'last_page'    => $page->lastPage(),
    ]);
}

/**
 * Restaura um lead da lixeira (admin only).
 */
public function restore(int $id)
{
    $this->ensureAdmin();

    $lead = Lead::onlyTrashed()->findOrFail($id);

    $previousDeletedBy   = $lead->deleted_by_user_id;
    $previousReason      = $lead->deletion_reason;

    $lead->restore();
    $lead->update([
        'deleted_by_user_id' => null,
        'deletion_reason'    => null,
    ]);

    LeadHistory::create([
        'lead_id'     => $lead->id,
        'user_id'     => auth()->id(),
        'type'        => 'restored',
        'description' => 'Lead restaurado da lixeira'
            . ($previousReason ? ' — motivo original: ' . $previousReason : ''),
    ]);

    return response()->json([
        'success' => true,
        'lead'    => [
            'id'   => $lead->id,
            'name' => $lead->name,
        ],
    ]);
}

/**
 * Expurgo DEFINITIVO. Remove permanentemente do banco. Apenas admin.
 */
public function forceDestroy(int $id)
{
    $this->ensureAdmin();

    $lead = Lead::onlyTrashed()->findOrFail($id);
    $name = $lead->name;
    $idCopy = $lead->id;

    $lead->forceDelete();

    \Log::warning('Lead expurgado permanentemente', [
        'lead_id'    => $idCopy,
        'name'       => $name,
        'by_user_id' => auth()->id(),
    ]);

    return response()->json(['success' => true, 'purged_id' => $idCopy]);
}

private function ensureAdmin(): void
{
    $u = auth()->user();
    $role = method_exists($u, 'effectiveRole')
        ? $u->effectiveRole()
        : strtolower(trim((string) ($u->role ?? '')));
    if ($role !== 'admin') {
        abort(403, 'Ação restrita ao administrador.');
    }
}

public function reveal(Request $request, Lead $lead)
{
    $user = auth()->user();
    if (!$user) abort(401);

    $role = strtolower(trim((string) ($user->role ?? '')));
    $isManager = in_array($role, ['admin', 'gestor'], true);
    $isOwner   = (int) $lead->assigned_user_id === (int) $user->id;

    if (!$isManager && !$isOwner) {
        abort(403, 'Você não é o corretor responsável por este lead.');
    }

    $data = $request->validate([
        'field'       => 'nullable|string|in:phone,whatsapp,email',
        'custom_slug' => 'nullable|string|max:100',
    ]);

    if (empty($data['field']) && empty($data['custom_slug'])) {
        abort(422, 'Informe field ou custom_slug.');
    }

    $label = null;
    $value = null;

    if (!empty($data['field'])) {

        $field = $data['field'];
        $labels = ['phone' => 'Telefone', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail'];
        $label = $labels[$field];
        $value = $lead->{$field};
    } else {
        $slug = $data['custom_slug'];
        $cf = CustomField::where('slug', $slug)->first();
        if (!$cf) abort(404, 'Campo customizado não encontrado.');
        if (!$cf->is_sensitive) {

            $val = LeadCustomFieldValue::where('lead_id', $lead->id)
                ->where('custom_field_id', $cf->id)->first();
            return response()->json([
                'value' => $val?->value,
                'label' => $cf->name ?: $cf->slug,
            ]);
        }
        $label = $cf->name ?: $cf->slug;
        $val = LeadCustomFieldValue::where('lead_id', $lead->id)
            ->where('custom_field_id', $cf->id)->first();
        $value = $val?->value;
    }

    LeadHistory::create([
        'lead_id'     => $lead->id,
        'user_id'     => $user->id,
        'type'        => 'pii_revealed',
        'description' => $label,
        'from'        => null,
        'to'          => null,
    ]);

    return response()->json([
        'value' => $value,
        'label' => $label,
    ]);
}

public function show(Lead $lead)
{
    $this->authorize('view', $lead);

$lead->load([
    'status:id,name,color_hex',
    'substatus:id,lead_status_id,name,color_hex',
    'source:id,name',
    'empreendimento:id,name',
    'corretor:id,name',

    'interactions' => function ($q) {
        $q->with([
            'user:id,name',
            'appointment'
        ])->orderByDesc('created_at');
    },
    'histories' => function ($q) {
        $q->with('user:id,name')->orderByDesc('created_at');
    },
    'customFieldValues.customField:id,slug,name,type',
]);

    return new LeadResource($lead);
}

    public function firstContact(Request $request, $id)
    {
        $lead = Lead::findOrFail($id);
        $user = $request->user();

        $role = strtolower(trim((string) ($user->role ?? '')));
        $isOwner = (int) $lead->assigned_user_id === (int) $user->id;
        if (!$isOwner && !in_array($role, ['admin', 'gestor'], true)) {
            return response()->json([
                'message' => 'Você não é o corretor responsável por esse lead.'
            ], 403);
        }

        if (strtolower((string) $lead->sla_status) !== 'pending') {
            return response()->json([
                'message'    => 'Primeiro contato já foi registrado (ou SLA já encerrou).',
                'sla_status' => $lead->sla_status,
            ], 409);
        }

        $toStatusId = \App\Models\Setting::get('lead_after_first_contact_status_id', null);
        $toSubId    = \App\Models\Setting::get('lead_after_first_contact_substatus_id', null);
        $toStatusId = is_numeric($toStatusId) ? (int) $toStatusId : null;
        $toSubId    = is_numeric($toSubId)    ? (int) $toSubId    : null;

        $oldStatusId = $lead->status_id;
        $oldSubId    = $lead->lead_substatus_id;

        if ($toSubId) {
            $targetStatus = $toStatusId ?: $oldStatusId;
            $sub = \App\Models\LeadSubstatus::find($toSubId);
            if (!$sub || (int) $sub->lead_status_id !== (int) $targetStatus) {
                $toSubId = null;
            }
        }

        $update = ['sla_status' => 'met'];
        $statusChanged = false;
        $subChanged    = false;

        if ($toStatusId && $toStatusId !== $oldStatusId) {
            $update['status_id']         = $toStatusId;
            $update['status_changed_at'] = now();
            $statusChanged = true;
        }
        if ($toSubId !== null && $toSubId !== $oldSubId) {
            $update['lead_substatus_id'] = $toSubId;
            $subChanged = true;
        }

        $update['last_interaction_at'] = now();

        $lead->update($update);

        try {
            LeadHistory::create([
                'lead_id'     => $lead->id,
                'user_id'     => $user->id,
                'type'        => 'first_contact',
                'from'        => null,
                'to'          => null,
                'description' => 'Primeiro contato registrado — SLA cumprido',
            ]);

            if ($statusChanged) {
                $fromName = $oldStatusId
                    ? optional(\App\Models\LeadStatus::find($oldStatusId))->name
                    : null;
                $toName = optional(\App\Models\LeadStatus::find($toStatusId))->name;
                LeadHistory::create([
                    'lead_id'     => $lead->id,
                    'user_id'     => $user->id,
                    'type'        => 'status_change',
                    'from'        => $fromName,
                    'to'          => $toName,
                    'description' => 'Etapa alterada após primeiro contato',
                ]);
            }

            if ($subChanged) {
                $fromSub = $oldSubId
                    ? optional(\App\Models\LeadSubstatus::find($oldSubId))->name
                    : null;
                $toSub = $toSubId
                    ? optional(\App\Models\LeadSubstatus::find($toSubId))->name
                    : null;
                LeadHistory::create([
                    'lead_id'     => $lead->id,
                    'user_id'     => $user->id,
                    'type'        => 'substatus_change',
                    'from'        => $fromSub,
                    'to'          => $toSub,
                    'description' => 'Subetapa alterada após primeiro contato',
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Falha ao gravar histórico de first_contact', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success'    => true,
            'sla_status' => 'met',
            'lead'       => $lead->fresh(['status', 'substatus', 'corretor']),
        ]);
    }

}
