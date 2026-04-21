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

    $statuses = LeadStatus::orderBy('order')
        ->with(['leads' => function ($q) use ($user) {

            if (!in_array($user->role, ['admin', 'gestor'])) {
                $q->where('assigned_user_id', $user->id);
            }

            $q->orderBy('position')
              ->select(
                  'id',
                  'name',
                  'phone',
                  'sla_status',
                  'status_id',
                  'assigned_user_id',
                  'position'
              );
        }])
        ->get(['id', 'name']);

    return response()->json($statuses->values());
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

    $lead->update([
        'status_id'         => $data['status_id'],
        'lead_substatus_id' => $data['lead_substatus_id'] ?? $lead->lead_substatus_id,
        'position'          => ($lastPosition ?? 0) + 1,
    ]);

    // Salva custom values se vieram
    if (!empty($customValues)) {
        $slugs  = collect($customValues)->pluck('slug')->unique();
        $fields = \App\Models\CustomField::whereIn('slug', $slugs)->get()->keyBy('slug');

        foreach ($customValues as $entry) {
            $field = $fields->get($entry['slug']);
            if (!$field) continue;

            $value = $entry['value'] ?? null;
            if ($field->type === 'checkbox' && is_array($value)) {
                $value = json_encode(array_values($value), JSON_UNESCAPED_UNICODE);
            } elseif ($value !== null) {
                $value = (string) $value;
            }

            \App\Models\LeadCustomFieldValue::updateOrCreate(
                ['lead_id' => $lead->id, 'custom_field_id' => $field->id],
                ['value'   => $value]
            );
        }
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
}
