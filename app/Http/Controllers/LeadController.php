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

    // Atualiza o lead (campos fixos)
    if (!empty($data)) {
        $lead->update($data);
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
'empreendimento:id,name',        'interactions' => function ($q) {
    $q->with('user:id,name')
      ->latest()
      ->limit(1);
}
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
if ($request->filled('responsavel')) {
    $query->where('assigned_user_id', $request->responsavel);
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

    return LeadResource::collection(
    $query->orderByDesc('created_at')->paginate(10)
);
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

    $assignedUserId = $user->role === 'admin'
        ? $request->assigned_user_id
        : $user->id;

    $lead = \App\Models\Lead::create([
        'name' => $request->name,
        'phone' => $request->phone,
        'email' => $request->email,
        'empreendimento_id' => $request->empreendimento_id,
        'assigned_user_id' => $assignedUserId,
        'status_id' => 1
    ]);

    // 🔥 fallback: se admin não escolheu
    if (!$assignedUserId) {
        app(\App\Services\LeadAssignmentService::class)->assign($lead);
    }
    LeadHistory::create([
    'lead_id' => $lead->id,
    'user_id' => auth()->id(),
    'type' => 'created',
    'description' => 'Lead criado'
]);

    return response()->json($lead);
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
