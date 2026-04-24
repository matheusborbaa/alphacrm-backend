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
/**
 * @group Leads
 *
 * APIs para listagem e visualização de leads do CRM.
 * Usado pela Home, Lista de Leads e Card do Lead.
 */
class LeadController extends Controller
{

        use AuthorizesRequests;
public function update(Request $request, Lead $lead, LeadStatusRequirementValidator $validator)
{
    // Bloqueia corretor de editar lead alheio (LeadPolicy@update)
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

        // Valores de campos customizados que vêm junto (opcional).
        // Formato: [{ slug: "motivo_descarte", value: "Preço" }, ...]
        'custom_field_values'         => 'sometimes|array',
        'custom_field_values.*.slug'  => 'required_with:custom_field_values|string|exists:custom_fields,slug',
        'custom_field_values.*.value' => 'nullable',
    ]);

    /* ------------------------------------------------------------------
     * 🔒 GATE DE PERMISSÃO — campos gestor-only
     *
     * Alguns campos só gestor/admin pode alterar. O corretor NÃO passa
     * o lead pra outro corretor sozinho, nem "maquia" origem/campanha
     * pra poluir relatório de marketing. Se o corretor tentou mudar
     * explicitamente qualquer um desses campos, retornamos 403. Se
     * enviou o MESMO valor (noop), removemos silenciosamente do payload
     * pra evitar histórico falso.
     * ------------------------------------------------------------------ */
    $authUser  = auth()->user();
    $isManager = $authUser && in_array(
        strtolower(trim((string) ($authUser->role ?? ''))),
        ['admin', 'gestor'],
        true
    );
    if (!$isManager) {
        $managerOnly = ['assigned_user_id', 'source_id', 'channel', 'campaign'];
        foreach ($managerOnly as $f) {
            if (!array_key_exists($f, $data)) continue;
            $current   = $lead->{$f};
            $attempted = $data[$f];
            // Normaliza pra comparar (null, '', 0 são equivalentes aqui).
            $a = $current   === null ? '' : (string) $current;
            $b = $attempted === null ? '' : (string) $attempted;
            if ($a !== $b) {
                abort(403, 'Só gestor/admin pode alterar este campo.');
            }
            // Valor igual ao atual — noop, não deixa na água do update
            unset($data[$f]);
        }
    }

    // Separa custom_field_values do resto
    $customValues = $data['custom_field_values'] ?? [];
    unset($data['custom_field_values']);

    // Se está mudando status ou substatus, valida obrigatórios ANTES de salvar
    $validator->validate(
        $lead,
        array_key_exists('status_id', $data)         ? $data['status_id']         : null,
        array_key_exists('lead_substatus_id', $data) ? $data['lead_substatus_id'] : null,
        $data,
        $customValues
    );

    // Detecta reatribuição de corretor (antes de atualizar) pra notificar depois.
    $previousAssignedUserId = $lead->assigned_user_id;
    $newAssignedUserId      = array_key_exists('assigned_user_id', $data)
        ? $data['assigned_user_id']
        : null;
    $reassigned = array_key_exists('assigned_user_id', $data)
        && $newAssignedUserId
        && $newAssignedUserId != $previousAssignedUserId;

    /* 📸 Snapshot dos atributos ANTES do update — usado pra gerar o
     * histórico granular (1 LeadHistory por campo alterado). */
    $originalAttrs = $lead->getAttributes();

    // Atualiza o lead (campos fixos)
    if (!empty($data)) {
        $lead->update($data);
    }

    // 🔔 Notifica novo corretor em caso de reatribuição (database + e-mail)
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

    // Salva os custom values se vieram e coleta diffs (slug => old/new)
    $customDiffs = [];
    if (!empty($customValues)) {
        $customDiffs = $this->saveCustomValues($lead, $customValues);
    }

    /* 📝 HISTÓRICO GRANULAR — uma entrada por campo alterado.
     *
     * Substitui o bloco antigo que gravava uma única linha "atualizou
     * A, B, C". Agora temos rastreabilidade de valor antigo → novo,
     * e IDs são resolvidos pra nomes humanos (ex.: "Corretor
     * responsável: João → Maria").
     *
     * ATENÇÃO: status_id e lead_substatus_id já são gravados pelo
     * LeadObserver com type='status_change' / 'substatus_change' — não
     * duplicamos aqui. */
    $this->logFieldChanges($lead, $originalAttrs, $customDiffs);

    return response()->json(['success' => true]);
}

/**
 * Compara snapshot antigo vs valores atuais do lead e grava uma
 * entrada em lead_histories por campo alterado.
 *
 * Campos com FK (assigned_user_id, source_id, empreendimento_id) são
 * resolvidos pro nome humano antes de virar string. Custom fields
 * chegam já calculados via $customDiffs (saveCustomValues devolve).
 */
private function logFieldChanges(Lead $lead, array $originalAttrs, array $customDiffs): void
{
    if (!auth()->check()) return;

    // Map de campo → label humano (em pt-BR)
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

    // Resolvers de ID → nome (só pros FKs). Nulos passam como null.
    $resolvers = [
        'assigned_user_id' => fn($id) => $id ? optional(\App\Models\User::find($id))->name : null,
        'source_id'        => fn($id) => $id ? optional(\App\Models\LeadSource::find($id))->name : null,
        'empreendimento_id'=> fn($id) => $id ? optional(\App\Models\Empreendimento::find($id))->name : null,
    ];

    foreach ($labels as $field => $label) {
        if (!array_key_exists($field, $originalAttrs)) continue;

        $before = $originalAttrs[$field];
        $after  = $lead->{$field};

        // Normaliza (null e '' são equivalentes pra fim de diff)
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

    // Custom fields — já vêm com label resolvido.
    // Delega pro helper central (mesmo usado pelo KanbanController@move
    // e LeadCustomFieldValueController@bulkStore).
    LeadHistory::logFieldChangeDiffs($lead, $customDiffs, auth()->id());
}

/**
 * Upsert em bulk dos valores de custom fields de um lead.
 * Usado pelo update() quando o request traz custom_field_values.
 *
 * Retorna um array de diffs dos campos que mudaram, no formato:
 *   [ ['label' => 'CPF', 'from' => '...', 'to' => '...'], ... ]
 * O controller usa pra gravar histórico granular.
 */
private function saveCustomValues(Lead $lead, array $values): array
{
    $diffs  = [];
    $slugs  = collect($values)->pluck('slug')->unique();
    $fields = CustomField::whereIn('slug', $slugs)->get()->keyBy('slug');

    // Carrega valores ANTIGOS (só dos custom_field_id afetados) pra diff
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

        // Diff (null e '' são equivalentes pra fins de histórico)
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

    /**
     * Listar leads
     *
     * Retorna uma lista paginada de leads com filtros opcionais.
     * Inclui corretor responsável, status, empreendimentos,
     * último contato e status do SLA.
     *
     * @queryParam status_id int Filtrar pelo status do funil. Example: 1
     * @queryParam assigned_user_id int Filtrar por corretor responsável. Example: 5
     * @queryParam sla_status string Filtrar pelo status do SLA (pending, met, expired). Example: pending
     * @queryParam search string Buscar por nome ou telefone do lead. Example: João
     *
     * @response 200 {
     *   "current_page": 1,
     *   "data": [
     *     {
     *       "id": 1,
     *       "name": "João Silva",
     *       "phone": "11999999999",
     *       "sla_status": "pending",
     *       "corretor": {
     *         "id": 2,
     *         "name": "Corretor A"
     *       },
     *       "status": {
     *         "id": 1,
     *         "name": "Novo"
     *       },
     *       "empreendimentos": [
     *         {
     *           "id": 1,
     *           "name": "Residencial Alpha"
     *         }
     *       ],
     *       "interactions": [
     *         {
     *           "id": 10,
     *           "type": "whatsapp",
     *           "created_at": "2026-01-27T12:00:00Z"
     *         }
     *       ]
     *     }
     *   ]
     * }
     */
    public function index(Request $request)
{


    $user = $request->user(); // usuário logado via Sanctum

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

    /*
    |--------------------------------------------------------------------------
    | REGRA DE VISUALIZAÇÃO
    |--------------------------------------------------------------------------
    | admin / gestor -> veem todos
    | corretor -> vê apenas os próprios leads
    |
    | Delega pro scope centralizado (Lead::visibleTo) — mesma regra
    | que o chat usa pra decidir se o anexo de lead é permitido.
    */
    $query->visibleTo($user);

    /*
    |--------------------------------------------------------------------------
    | INTERSECÇÃO DE ACL (usado pelo chat)
    |--------------------------------------------------------------------------
    | Quando o frontend está montando a lista de leads pra anexar numa
    | conversa, ele passa `visible_to_user_id=<peer>`. Isso restringe a
    | lista a leads que TANTO o usuário logado QUANTO o peer enxergam,
    | evitando que o sender anexe um lead que o outro não tem acesso.
    |
    | Se o peer for admin/gestor, o scope não adiciona restrição.
    | Se o peer for corretor, força assigned_user_id = peer.id.
    | Dois corretores distintos → intersecção vazia (só um pode ser dono).
    */
    if ($request->filled('visible_to_user_id')) {
        $peer = \App\Models\User::find((int) $request->visible_to_user_id);
        if (!$peer) {
            // peer inválido: fail-safe, não vaza nada.
            $query->whereRaw('1 = 0');
        } else {
            $query->visibleTo($peer);
        }
    }

    // 🔍 Filtros

// 🔎 FILTRO POR CÓDIGO
if ($request->filled('codigo')) {
    $query->where('id', (int) $request->codigo);
}
if ($request->filled('empreendimento')) {
    $query->where('empreendimento_id', $request->empreendimento);
}
if ($request->filled('funil')) {
    $query->where('status_id', $request->funil);
}
if ($request->filled('substatus')) {
    $query->where('lead_substatus_id', $request->substatus);
}
if ($request->filled('responsavel')) {
    $query->where('assigned_user_id', $request->responsavel);
}
if ($request->filled('temperature')) {
    // Aceita string única ou lista separada por vírgula: 'quente,morno'
    $temps = is_array($request->temperature)
        ? $request->temperature
        : explode(',', (string) $request->temperature);
    $temps = array_filter(array_map('trim', $temps));
    if (!empty($temps)) {
        $query->whereIn('temperature', $temps);
    }
}
if ($request->filled('source_id')) {
    $query->where('source_id', $request->source_id);
}
if ($request->filled('channel')) {
    $query->where('channel', $request->channel);
}
if ($request->filled('sem_interacao_dias')) {
    // Leads sem interação há N dias
    $dias = (int) $request->sem_interacao_dias;
    $query->where(function ($q) use ($dias) {
        $q->whereNull('last_interaction_at')
          ->orWhere('last_interaction_at', '<=', now()->subDays($dias));
    });
}
if ($request->filled('created_from')) {
    $query->whereDate('created_at', '>=', $request->created_from);
}
if ($request->filled('created_to')) {
    $query->whereDate('created_at', '<=', $request->created_to);
}
    
   if ($request->filled('search')) {

    $search = $request->search;

    $query->where(function ($q) use ($search) {

        $q->where('name', 'like', "%{$search}%")
          ->orWhere('phone', 'like', "%{$search}%")
          ->orWhere('email', 'like', "%{$search}%")

          // 🔥 EMPREENDIMENTO
          ->orWhereHas('empreendimentos', function ($q2) use ($search) {
              $q2->where('name', 'like', "%{$search}%");
          })

          // 🔥 STATUS
          ->orWhereHas('status', function ($q2) use ($search) {
              $q2->where('name', 'like', "%{$search}%");
          })

          // 🔥 CORRETOR
          ->orWhereHas('corretor', function ($q2) use ($search) {
              $q2->where('name', 'like', "%{$search}%");
          })

          // 🔥 DATA (formato YYYY-MM-DD)
          ->orWhereDate('created_at', $search);
    });
    
}

    $perPage = (int) $request->input('per_page', 15);
    $perPage = max(5, min(100, $perPage));

    return LeadResource::collection(
        $query->orderByDesc('created_at')->paginate($perPage)
    );
}

    /**
     * Contadores pros cards-resumo da listagem de leads (doc funcional).
     * Respeita as mesmas regras de visibilidade (corretor só vê os seus).
     *
     * GET /leads/counts
     *
     * @response 200 {
     *   "quente": 103,
     *   "morno": 59,
     *   "frio": 28,
     *   "total": 400,
     *   "em_atendimento": 180,
     *   "sem_interacao_10d": 30
     * }
     */
    public function counts(Request $request)
    {
        $user = $request->user();

        $base = Lead::query();
        if ($user->role === 'corretor') {
            $base->where('assigned_user_id', $user->id);
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

    /**
     * Visualizar lead
     *
     * Retorna os dados completos de um lead específico,
     * incluindo histórico de contatos, corretor,
     * origem e empreendimentos vinculados.
     *
     * @urlParam lead int ID do lead. Example: 1
     *
     * @response 200 {
     *   "id": 1,
     *   "name": "João Silva",
     *   "phone": "11999999999",
     *   "sla_status": "met",
     *   "corretor": {
     *     "id": 2,
     *     "name": "Corretor A"
     *   },
     *   "source": {
     *     "id": 1,
     *     "name": "ManyChat"
     *   },
     *   "empreendimentos": [
     *     {
     *       "id": 1,
     *       "name": "Residencial Alpha"
     *     }
     *   ],
     *   "interactions": [
     *     {
     *       "id": 10,
     *       "type": "whatsapp",
     *       "note": "Primeiro contato",
     *       "user": {
     *         "id": 2,
     *         "name": "Corretor A"
     *       }
     *     }
     *   ]
     * }
     */

public function store(Request $request)
{
    $user = auth()->user();

    $data = $request->validate([
        'name'               => 'required|string|max:255',
        'phone'              => 'required|string|max:30',
        'whatsapp'           => 'nullable|string|max:30',
        'email'              => 'nullable|email|max:255',
        'channel'            => 'nullable|string|max:80',        // origem
        'campaign'           => 'nullable|string|max:150',
        'empreendimento_id'  => 'nullable|integer|exists:empreendimentos,id',
        'city_of_interest'   => 'nullable|string|max:120',
        'region_of_interest' => 'nullable|string|max:120',
        'assigned_user_id'   => 'nullable|integer|exists:users,id',
        'force'              => 'nullable|boolean',              // bypass do duplicate check
    ]);

    // force=true só é aceito se o usuário for admin ou gestor. Corretor não
    // pode ignorar a regra de duplicidade — precisa pedir liberação pro
    // gerente/admin (defesa em camada além do bloqueio visual no frontend).
    $forceBypass = !empty($data['force']);
    if ($forceBypass && !in_array($user->role, ['admin', 'gestor'], true)) {
        return response()->json([
            'message' => 'Você não tem permissão para cadastrar um lead duplicado. Procure um gerente ou administrador.',
        ], 403);
    }

    // 🔎 Verificação de duplicidade (telefone / WhatsApp / email).
    // Se encontrar e o cliente não tiver confirmado via `force`, devolve 409.
    if (!$forceBypass) {
        $duplicates = $this->findDuplicateLeads(
            $data['phone']    ?? null,
            $data['whatsapp'] ?? null,
            $data['email']    ?? null,
        );

        if ($duplicates->isNotEmpty()) {
            return response()->json([
                'message'    => 'Já existem leads com esses contatos.',
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

    // 🔥 fallback: se admin não escolheu
    if (!$assignedUserId) {
        // O service já notifica o corretor sorteado pelo rodízio.
        app(\App\Services\LeadAssignmentService::class)->assign($lead);
    } else {
        // Admin escolheu o responsável direto — notifica imediatamente.
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


/**
 * Endpoint leve pro frontend consultar duplicidade enquanto o corretor
 * preenche o modal. Aceita qualquer combinação de phone/whatsapp/email.
 *
 * GET /leads/check-duplicates?phone=...&whatsapp=...&email=...
 */
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


/**
 * ===========================================================================
 *                   FILA DE LEADS ÓRFÃOS (READ-ONLY)
 * ===========================================================================
 * Leads com assigned_user_id = null formam uma fila lógica que o rodízio
 * consome em ordem (FIFO por created_at) sempre que um corretor vira
 * 'disponivel'. Essa view serve pros gestores e admins acompanharem o
 * tamanho da fila e identificarem gargalos — NÃO é uma ferramenta de
 * atribuição. A distribuição segue 100% automática via LeadAssignmentService.
 *
 * Autorização: só admin e gestor. Corretor não tem acesso.
 * ===========================================================================
 */

/**
 * GET /leads/queue
 * Lista completa da fila de órfãos, ordenada do mais antigo pro mais novo
 * (que é a mesma ordem em que o rodízio vai consumir).
 */
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
        // Carbon 3 (Laravel 12) retorna signed por padrão: como created_at
        // está no passado, $now->diffInMinutes($created_at) vem NEGATIVO e
        // cai pra 0 no cast (int). Invertendo a ordem ($created_at->diffInMinutes($now))
        // + abs() garante positivo independente da versão do Carbon.
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

    // Contagem de corretores disponíveis pro gestor entender por que a
    // fila está (ou não) avançando. Não bloqueia nada — é só contexto.
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

/**
 * GET /leads/queue/count
 * Endpoint leve pra alimentar o badge do sidebar sem ter que transferir
 * a lista inteira a cada polling.
 */
public function queueCount(Request $request)
{
    $this->ensureManager();

    $total = Lead::whereNull('assigned_user_id')->count();

    // Pega só o mais antigo pra calcular a cor do badge sem carregar tudo.
    $oldestMinutes = 0;
    if ($total > 0) {
        $oldest = Lead::whereNull('assigned_user_id')
            ->orderBy('created_at', 'asc')
            ->value('created_at');
        if ($oldest) {
            // Mesma armadilha do queue(): Carbon 3 devolve signed — inverter
            // ordem e aplicar abs() pra garantir sempre positivo.
            $oldestMinutes = (int) abs(\Carbon\Carbon::parse($oldest)->diffInMinutes(now()));
        }
    }

    return response()->json([
        'total'          => $total,
        'oldest_minutes' => $oldestMinutes,
    ]);
}

/**
 * Guarda compartilhada pelos endpoints da fila. Mantém a lógica num
 * só lugar caso a gente adicione mais views read-only no futuro.
 */
private function ensureManager(): void
{
    $user = auth()->user();
    if (!$user) abort(401);

    $role = strtolower(trim((string) ($user->role ?? '')));
    if (!in_array($role, ['admin', 'gestor'], true)) {
        abort(403, 'Apenas admin e gestor podem visualizar a fila.');
    }
}


/**
 * Busca leads que batam em qualquer um dos três contatos informados.
 * Normaliza telefones (só dígitos) pra não falhar por causa de máscara.
 *
 * @return \Illuminate\Support\Collection
 */
protected function findDuplicateLeads(?string $phone, ?string $whatsapp, ?string $email)
{
    $phoneDigits    = $phone    ? preg_replace('/\D/', '', $phone)    : null;
    $whatsappDigits = $whatsapp ? preg_replace('/\D/', '', $whatsapp) : null;

    $query = \App\Models\Lead::query()
        ->select(['id', 'name', 'phone', 'whatsapp', 'email', 'status_id', 'assigned_user_id', 'created_at'])
        ->with(['status:id,name', 'corretor:id,name']);

    $query->where(function ($q) use ($phoneDigits, $whatsappDigits, $email) {
        if ($phoneDigits) {
            // Compara só dígitos pra tolerar máscaras diferentes
            $q->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')','') = ?", [$phoneDigits]);
            $q->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(whatsapp,' ',''),'-',''),'(',''),')','') = ?", [$phoneDigits]);
        }
        if ($whatsappDigits && $whatsappDigits !== $phoneDigits) {
            $q->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')','') = ?", [$whatsappDigits]);
            $q->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(whatsapp,' ',''),'-',''),'(',''),')','') = ?", [$whatsappDigits]);
        }
        if ($email) {
            $q->orWhere('email', $email);
        }
    });

    return $query->limit(10)->get();
}

public function destroy(Lead $lead)
{
    // Bloqueia quem não tem leads.delete (só admin e gestor, por config)
    $this->authorize('delete', $lead);

    $lead->delete();

    return response()->json(['success' => true]);
}

/**
 * LGPD — devolve o valor cleartext de um campo sensível e registra o
 * acesso em lead_histories (type='pii_revealed'). Usado pelo botão
 * "Revelar" na aba Dados do lead.
 *
 * Query params (um dos dois):
 *   - field=phone|whatsapp|email       (campo fixo do lead)
 *   - custom_slug=cpf|rg|...           (custom_field.slug)
 *
 * Resposta: { value: string|null, label: string }
 *
 * Permissão: admin, gestor ou corretor responsável pelo lead. Corretor
 * que NÃO é dono recebe 403 — se for pra ele ver, o gestor reatribui.
 */
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
        // Campos fixos — lista branca conferida pelo validator acima
        $field = $data['field'];
        $labels = ['phone' => 'Telefone', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail'];
        $label = $labels[$field];
        $value = $lead->{$field};
    } else {
        $slug = $data['custom_slug'];
        $cf = CustomField::where('slug', $slug)->first();
        if (!$cf) abort(404, 'Campo customizado não encontrado.');
        if (!$cf->is_sensitive) {
            // Não-sensível não precisa revelar — devolve direto sem log,
            // pra não poluir o histórico.
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

    // Loga o acesso. Esta é a trilha que a LGPD espera (Art. 37):
    // "quem leu o CPF do lead X, quando, e em que contexto".
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
            'appointment' // 👈 AGORA SIM
        ])->orderByDesc('created_at');
    },
    'histories' => function ($q) {
        $q->with('user:id,name')->orderByDesc('created_at');
    },
    'customFieldValues.customField:id,slug,name,type',
]);

    return new LeadResource($lead);
}

    /**
     * POST /leads/{id}/first-contact
     *
     * O corretor dono do lead marca que fez o primeiro contato. Isso:
     *   - Cumpre o SLA (sla_status: pending → met). Com 'met', o
     *     CheckLeadSlaJob ignora o lead e não reatribui por SLA vencido.
     *   - Opcionalmente move o lead pra etapa+subetapa configuradas em
     *     lead_after_first_contact_status_id / ..._substatus_id.
     *   - Grava LeadHistory com o evento + mudanças de etapa/subetapa.
     *
     * Regras de acesso:
     *   - Só o corretor dono do lead OU admin/gestor pode chamar.
     *   - Só funciona se sla_status === 'pending'. Idempotente: se já foi
     *     marcado, retorna 409 com mensagem amigável.
     *
     * Validação de campos obrigatórios (RequiredFields da etapa destino)
     * fica no FRONTEND — o modal RequiredFields wizard chama esse endpoint
     * DEPOIS de já ter gravado os custom fields. Backend aqui só move.
     */
    public function firstContact(Request $request, $id)
    {
        $lead = Lead::findOrFail($id);
        $user = $request->user();

        // ---- AUTORIZAÇÃO -----------------------------------------------
        $role = strtolower(trim((string) ($user->role ?? '')));
        $isOwner = (int) $lead->assigned_user_id === (int) $user->id;
        if (!$isOwner && !in_array($role, ['admin', 'gestor'], true)) {
            return response()->json([
                'message' => 'Você não é o corretor responsável por esse lead.'
            ], 403);
        }

        // ---- IDEMPOTÊNCIA ----------------------------------------------
        if (strtolower((string) $lead->sla_status) !== 'pending') {
            return response()->json([
                'message'    => 'Primeiro contato já foi registrado (ou SLA já encerrou).',
                'sla_status' => $lead->sla_status,
            ], 409);
        }

        // ---- LÊ CONFIG DE DESTINO --------------------------------------
        $toStatusId = \App\Models\Setting::get('lead_after_first_contact_status_id', null);
        $toSubId    = \App\Models\Setting::get('lead_after_first_contact_substatus_id', null);
        $toStatusId = is_numeric($toStatusId) ? (int) $toStatusId : null;
        $toSubId    = is_numeric($toSubId)    ? (int) $toSubId    : null;

        $oldStatusId = $lead->status_id;
        $oldSubId    = $lead->lead_substatus_id;

        // Se a subetapa configurada não pertence à etapa de destino (ou à
        // atual, se não vamos mover), ignora — evita inconsistência.
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

        // Guarda dados de interação (usado p/ relatório "tempo até 1ª resposta")
        $update['last_interaction_at'] = now();

        $lead->update($update);

        // ---- HISTÓRICOS ------------------------------------------------
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
