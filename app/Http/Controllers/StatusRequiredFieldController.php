<?php

namespace App\Http\Controllers;

use App\Models\CustomField;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\LeadSubstatus;
use App\Models\StatusRequiredField;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRUD das regras "quando status X, campo Y é obrigatório".
 *
 * Também expõe o endpoint que o frontend usa pra perguntar:
 *   "dado este status/substatus, quais campos preciso pedir pro usuário?"
 */
class StatusRequiredFieldController extends Controller
{
    public function index(Request $request)
    {
        $query = StatusRequiredField::with(['status', 'substatus', 'customField']);

        if ($request->filled('status_id')) {
            $query->where('lead_status_id', $request->status_id);
        }

        if ($request->filled('substatus_id')) {
            $query->where('lead_substatus_id', $request->substatus_id);
        }

        return $query->get();
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        $rule = StatusRequiredField::create($data);
        $rule->load(['status', 'substatus', 'customField']);

        return response()->json($rule, 201);
    }

    public function update(Request $request, StatusRequiredField $statusRequiredField)
    {
        $data = $this->validateData($request);

        $statusRequiredField->update($data);
        $statusRequiredField->load(['status', 'substatus', 'customField']);

        return $statusRequiredField;
    }

    public function destroy(StatusRequiredField $statusRequiredField)
    {
        $statusRequiredField->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Endpoint chamado pelo frontend pra saber o que pedir ao usuário
     * quando ele tenta mudar o status de um lead.
     *
     * Retorna todas as regras que se aplicam a um status ou substatus,
     * incluindo regras do pai (status) quando filtrado por substatus.
     *
     * Query params:
     *   - status_id (opcional)
     *   - substatus_id (opcional)
     *   - lead_id (opcional) — se passar, já devolve quais campos estão
     *     faltando, pra UI só mostrar o que falta.
     */
    public function forTarget(Request $request)
    {
        $request->validate([
            'status_id'    => 'nullable|exists:lead_status,id',
            'substatus_id' => 'nullable|exists:lead_substatus,id',
            'lead_id'      => 'nullable|exists:leads,id',
        ]);

        $query = StatusRequiredField::with('customField');

        // Quando filtrar por substatus, queremos tanto regras do substatus
        // QUANTO regras do status pai (porque regra do status vale pra todos
        // os substatus dele).
        if ($request->filled('substatus_id')) {
            $sub = LeadSubstatus::find($request->substatus_id);
            $parentStatusId = $sub?->lead_status_id;

            $query->where(function ($q) use ($request, $parentStatusId) {
                $q->where('lead_substatus_id', $request->substatus_id);
                if ($parentStatusId) {
                    $q->orWhere('lead_status_id', $parentStatusId);
                }
            });
        } elseif ($request->filled('status_id')) {
            $query->where('lead_status_id', $request->status_id);
        } else {
            return response()->json([]);
        }

        $rules = $query->get();

        // Se passou lead_id, filtra só o que tá vazio
        $lead = $request->filled('lead_id') ? Lead::with('customFieldValues')->find($request->lead_id) : null;

        $result = $rules->map(function (StatusRequiredField $rule) use ($lead) {
            $row = [
                'id'        => $rule->id,
                'required'  => $rule->required,
                'is_custom' => !$rule->isLeadColumn(),
            ];

            if ($rule->isLeadColumn()) {
                $row['field_key']  = $rule->lead_column;
                $row['field_name'] = $this->humanizeColumn($rule->lead_column);
                $row['field_type'] = 'text'; // lead columns são tratadas como text no form
                $row['options']    = null;
                $row['current_value'] = $lead?->getAttribute($rule->lead_column);
            } else {
                $cf = $rule->customField;
                $row['field_key']  = $cf?->slug;
                $row['field_name'] = $cf?->name;
                $row['field_type'] = $cf?->type;
                $row['options']    = $cf?->options;
                $row['current_value'] = $lead ? $lead->customValue($cf->slug) : null;
            }

            $row['is_filled'] = !empty($row['current_value']);

            return $row;
        });

        return response()->json($result->values());
    }

    /**
     * Transforma 'source_id' em 'Source' pra exibir na UI.
     * Reescreva aqui se quiser nomes mais amigáveis.
     */
    private function humanizeColumn(string $column): string
    {
        $labels = [
            'name'              => 'Nome',
            'phone'             => 'Telefone',
            'email'             => 'E-mail',
            'source_id'         => 'Origem',
            'assigned_user_id'  => 'Corretor responsável',
            'empreendimento_id' => 'Empreendimento',
            'channel'           => 'Canal',
            'campaign'          => 'Campanha',
        ];

        return $labels[$column] ?? ucfirst(str_replace('_', ' ', $column));
    }

    /**
     * Validação compartilhada entre store e update.
     *
     * Regras de integridade:
     *   - Deve informar EXATAMENTE UM de (lead_status_id, lead_substatus_id)
     *   - Deve informar EXATAMENTE UM de (lead_column, custom_field_id)
     *   - Se lead_column, tem que estar na whitelist
     */
    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'lead_status_id'    => 'nullable|exists:lead_status,id',
            'lead_substatus_id' => 'nullable|exists:lead_substatus,id',
            'lead_column'       => ['nullable', 'string', Rule::in(StatusRequiredField::ALLOWED_LEAD_COLUMNS)],
            'custom_field_id'   => 'nullable|exists:custom_fields,id',
            'required'          => 'boolean',
        ]);

        $hasStatus    = !empty($data['lead_status_id']);
        $hasSubstatus = !empty($data['lead_substatus_id']);
        $hasColumn    = !empty($data['lead_column']);
        $hasCustom    = !empty($data['custom_field_id']);

        if ($hasStatus === $hasSubstatus) {
            throw ValidationException::withMessages([
                'target' => 'Informe exatamente um entre lead_status_id e lead_substatus_id.',
            ]);
        }

        if ($hasColumn === $hasCustom) {
            throw ValidationException::withMessages([
                'field' => 'Informe exatamente um entre lead_column e custom_field_id.',
            ]);
        }

        return $data;
    }
}
