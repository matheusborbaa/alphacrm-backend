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
        'name'              => 'sometimes|string|max:255',
        'email'             => 'sometimes|nullable|email|max:255',
        'phone'             => 'sometimes|string|max:20',
        'source_id'         => 'sometimes|nullable|exists:lead_sources,id',
        'status_id'         => 'sometimes|nullable|exists:lead_status,id',
        'lead_substatus_id' => 'sometimes|nullable|exists:lead_substatus,id',
        'assigned_user_id'  => 'sometimes|nullable|exists:users,id',
        'empreendimento_id' => 'sometimes|nullable|exists:empreendimentos,id',
        'channel'           => 'sometimes|nullable|string|max:100',
        'campaign'          => 'sometimes|nullable|string|max:255',

        // Valores de campos customizados que vêm junto (opcional).
        // Formato: [{ slug: "motivo_descarte", value: "Preço" }, ...]
        'custom_field_values'         => 'sometimes|array',
        'custom_field_values.*.slug'  => 'required_with:custom_field_values|string|exists:custom_fields,slug',
        'custom_field_values.*.value' => 'nullable',
    ]);

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

    // Salva os custom values se vieram
    if (!empty($customValues)) {
        $this->saveCustomValues($lead, $customValues);
    }

    // Histórico
    $usuario = Auth()->user()->name;
    $user_id = Auth()->user()->id;
    LeadHistory::create([
    'lead_id' => $lead->id,
    'user_id' => auth()->id(),
    'type' => 'update',
    'description' => '('.$user_id.')'.$usuario.' fez alteração de dados do lead'
]);
    
    
    



    return response()->json(['success' => true]);
}

/**
 * Upsert em bulk dos valores de custom fields de um lead.
 * Usado pelo update() quando o request traz custom_field_values.
 */
private function saveCustomValues(Lead $lead, array $values): void
{
    $slugs  = collect($values)->pluck('slug')->unique();
    $fields = CustomField::whereIn('slug', $slugs)->get()->keyBy('slug');

    foreach ($values as $entry) {
        $field = $fields->get($entry['slug']);
        if (!$field) continue;

        $value = $entry['value'] ?? null;
        if ($field->type === 'checkbox' && is_array($value)) {
            $value = json_encode(array_values($value), JSON_UNESCAPED_UNICODE);
        } elseif ($value !== null) {
            $value = (string) $value;
        }

        LeadCustomFieldValue::updateOrCreate(
            ['lead_id' => $lead->id, 'custom_field_id' => $field->id],
            ['value'   => $value]
        );
    }
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
        'status:id,name',
        'substatus:id,name',
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
    */
    if ($user->role === 'corretor') {
        $query->where('assigned_user_id', $user->id);
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

    // 🔎 Verificação de duplicidade (telefone / WhatsApp / email).
    // Se encontrar e o cliente não tiver confirmado via `force`, devolve 409.
    if (empty($data['force'])) {
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

public function show(Lead $lead)
{
    $this->authorize('view', $lead);

$lead->load([
    'status:id,name',
    'corretor:id,name',

    'interactions' => function ($q) {
        $q->with([
            'user:id,name',
            'appointment' // 👈 AGORA SIM
        ])->orderByDesc('created_at');
    }
]);

    return new LeadResource($lead);
}

   
}
