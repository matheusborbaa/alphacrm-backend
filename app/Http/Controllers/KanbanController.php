<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadStatus;
use Illuminate\Http\Request;
use App\Services\AuditService;
use App\Services\LeadStatusRequirementValidator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * @group Kanban
 *
 * Funil visual de leads (drag & drop).
 * Usado na tela Kanban do CRM.
 */
class KanbanController extends Controller
{
    use AuthorizesRequests;
    /**
     * Listar colunas do Kanban
     *
     * Retorna os status do funil com seus respectivos leads.
     * Usado para montar o Kanban visual.
     *
     * @response 200 [
     *   {
     *     "id": 1,
     *     "name": "Novo",
     *     "leads": [
     *       {
     *         "id": 10,
     *         "name": "João Silva",
     *         "phone": "11999999999",
     *         "sla_status": "pending"
     *       }
     *     ]
     *   }
     * ]
     */
   public function index()
{
    $user = auth()->user();

    /*
    |--------------------------------------------------------------------------
    | Resposta esperada:
    |
    |   [
    |     {
    |       "id": 1, "name": "Lead Cadastrado", "order": 1,
    |       "substatuses": [
    |          { "id": 1, "name": "IA", "order": 1, "leads": [...] },
    |          ...
    |       ],
    |       "leads_without_substatus": [...]
    |     }
    |   ]
    |
    | O frontend renderiza uma coluna por SUBSTATUS, agrupadas visualmente
    | pelo STATUS pai. Leads sem substatus aparecem numa coluna "sem etapa"
    | dentro do grupo do status.
    |--------------------------------------------------------------------------
    */

    $leadSelect = [
        'id',
        'name',
        'phone',
        'email',
        'sla_status',
        'sla_deadline_at',
        'status_id',
        'lead_substatus_id',
        'assigned_user_id',
        'empreendimento_id',
        'channel',
        'campaign',
        'source_id',
        'temperature',
        'value',
        'last_interaction_at',
        'status_changed_at',
        'position',
        'updated_at',
        'created_at',
    ];

    $statuses = LeadStatus::with(['substatus' => function ($q) {
        $q->orderBy('order');
    }])
    ->orderBy('order')
    ->get(['id', 'name', 'order', 'color_hex']);

    // Carrega todos os leads visíveis pro user de uma vez e distribui em memória
    $leadsQuery = Lead::with([
            'corretor:id,name',
            'empreendimento:id,name',
            'source:id,name',
        ])
        ->orderBy('position')
        ->select($leadSelect);

    if (!in_array($user->role, ['admin', 'gestor'])) {
        $leadsQuery->where('assigned_user_id', $user->id);
    }

    $leadsByStatus    = $leadsQuery->get()->groupBy('status_id');

    $result = $statuses->map(function ($status) use ($leadsByStatus) {

        $statusLeads = $leadsByStatus->get($status->id, collect());

        // Agrupa por substatus_id; leads sem substatus vão num bucket separado
        $leadsBySub = $statusLeads->groupBy('lead_substatus_id');

        $substatuses = $status->substatus->map(function ($sub) use ($leadsBySub) {
            return [
                'id'        => $sub->id,
                'name'      => $sub->name,
                'order'     => $sub->order,
                'color_hex' => $sub->color_hex,
                'leads'     => $leadsBySub->get($sub->id, collect())->values(),
            ];
        })->values();

        return [
            'id'                       => $status->id,
            'name'                     => $status->name,
            'order'                    => $status->order,
            'color_hex'                => $status->color_hex,
            'substatuses'              => $substatuses,
            'leads_without_substatus'  => $leadsBySub->get(null, collect())->values(),
        ];
    });

    return response()->json($result->values());
}




    /**
     * Mover lead no Kanban
     *
     * Atualiza o status de um lead quando ele é movido no Kanban.
     *
     * @urlParam lead int ID do lead. Example: 10
     *
     * @bodyParam status_id int required ID do novo status do lead. Example: 3
     *
     * @response 200 {
     *   "success": true
     * }
     *
     * @response 404 {
     *   "message": "Lead not found."
     * }
     */
    public function move(Request $request, Lead $lead, LeadStatusRequirementValidator $validator)
{
    // Bloqueia corretor de mover lead alheio (LeadPolicy@move)
    $this->authorize('move', $lead);

    $data = $request->validate([
        'status_id'         => 'required|exists:lead_status,id',
        'lead_substatus_id' => 'sometimes|nullable|exists:lead_substatus,id',

        // Opcional: valores de custom fields preenchidos junto (pelo modal do frontend)
        'custom_field_values'         => 'sometimes|array',
        'custom_field_values.*.slug'  => 'required_with:custom_field_values|string|exists:custom_fields,slug',
        'custom_field_values.*.value' => 'nullable',
    ]);

    $customValues = $data['custom_field_values'] ?? [];
    unset($data['custom_field_values']);

    // Valida campos obrigatórios ANTES de mover
    $validator->validate(
        $lead,
        $data['status_id'] ?? null,
        $data['lead_substatus_id'] ?? null,
        $data,
        $customValues
    );

    // pega última posição da nova coluna
    $lastPosition = Lead::where('status_id', $data['status_id'])
        ->max('position');

    $newStatusId    = $data['status_id'];
    $newSubstatusId = $data['lead_substatus_id'] ?? $lead->lead_substatus_id;

    $updatePayload = [
        'status_id'         => $newStatusId,
        'lead_substatus_id' => $newSubstatusId,
        'position'          => ($lastPosition ?? 0) + 1,
    ];

    // Marca quando entrou na etapa (usado pra "ociosidade" no card)
    if ($newStatusId !== $lead->status_id) {
        $updatePayload['status_changed_at'] = now();
    }

    // Deriva temperatura automaticamente baseado no nome do substatus.
    // Convenção combinada com o cliente (doc):
    //   Em Atendimento > "Sem Avanço" = frio
    //                  > "Conversando" = morno
    //                  > "Qualificado" = quente
    if ($newSubstatusId) {
        $subName = \App\Models\LeadSubstatus::where('id', $newSubstatusId)->value('name');
        $derived = $this->derivedTemperature($subName);
        if ($derived !== null) {
            $updatePayload['temperature'] = $derived;
        }
    }

    $lead->update($updatePayload);

    // Salva custom values se vieram
    if (!empty($customValues)) {
        $slugs  = collect($customValues)->pluck('slug')->unique();
        $fields = \App\Models\CustomField::whereIn('slug', $slugs)->get()->keyBy('slug');

        // Snapshot antes do upsert pra conseguir logar diff (ex: CPF
        // preenchido pelo wizard de campos obrigatórios no drag).
        $oldByFieldId = \App\Models\LeadCustomFieldValue::where('lead_id', $lead->id)
            ->whereIn('custom_field_id', $fields->pluck('id'))
            ->get()
            ->keyBy('custom_field_id');

        $diffs = [];

        foreach ($customValues as $entry) {
            $field = $fields->get($entry['slug']);
            if (!$field) continue;

            $value = $entry['value'] ?? null;
            if ($field->type === 'checkbox' && is_array($value)) {
                $value = json_encode(array_values($value), JSON_UNESCAPED_UNICODE);
            } elseif ($value !== null) {
                $value = (string) $value;
            }

            $old = $oldByFieldId->get($field->id)?->value;

            \App\Models\LeadCustomFieldValue::updateOrCreate(
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

        \App\Models\LeadHistory::logFieldChangeDiffs($lead, $diffs);
    }

    return response()->json(['success' => true]);
}

public function reorder(Request $request)
{
    foreach ($request->leads as $leadData) {

        Lead::where('id', $leadData['id'])
            ->update([
                'position' => $leadData['position']
            ]);
    }

    return response()->json(['success' => true]);
}

/**
 * Deriva a temperatura baseado no nome do substatus.
 * Retorna 'frio' | 'morno' | 'quente' ou null quando não aplicável.
 */
private function derivedTemperature(?string $substatusName): ?string
{
    if (!$substatusName) return null;

    $normalized = mb_strtolower(trim($substatusName));

    if (str_contains($normalized, 'sem avanço') || str_contains($normalized, 'sem avanco')) {
        return 'frio';
    }
    if (str_contains($normalized, 'conversando')) {
        return 'morno';
    }
    if (str_contains($normalized, 'qualificado')) {
        return 'quente';
    }

    return null;
}
}
