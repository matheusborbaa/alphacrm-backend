<?php

namespace App\Http\Controllers;

use App\Models\CustomField;
use App\Models\Empreendimento;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\LeadSubstatus;
use App\Models\StatusRequiredField;
use App\Models\User;
use App\Services\LeadStatusRequirementValidator;
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
    public function forTarget(Request $request, LeadStatusRequirementValidator $validator)
    {
        $request->validate([
            'status_id'    => 'nullable|exists:lead_status,id',
            'substatus_id' => 'nullable|exists:lead_substatus,id',
            'lead_id'      => 'nullable|exists:leads,id',
        ]);

        $targetStatusId    = $request->input('status_id');
        $targetSubstatusId = $request->input('substatus_id');

        // Se veio só substatus, deriva o status pai
        if (!$targetStatusId && $targetSubstatusId) {
            $targetStatusId = LeadSubstatus::where('id', $targetSubstatusId)->value('lead_status_id');
        }

        if (!$targetStatusId && !$targetSubstatusId) {
            return response()->json([]);
        }

        // Lead atual (pra saber status de origem + quais campos já estão preenchidos)
        $lead = $request->filled('lead_id')
            ? Lead::with('customFieldValues.customField')->find($request->lead_id)
            : null;

        $currentStatusId = $lead?->status_id;

        // Delega pro service que já sabe percorrer as intermediárias
        $rules = $validator->collectRulesForTransition(
            $currentStatusId,
            $targetStatusId,
            $targetSubstatusId
        );

        // Dedup por (lead_column | custom_field_id), mantendo a primeira etapa
        $seen   = [];
        $result = [];

        foreach ($rules as $rule) {
            if (!$rule->required) continue;

            $dedupeKey = $rule->isLeadColumn()
                ? 'col:' . $rule->lead_column
                : 'cf:'  . $rule->custom_field_id;

            if (isset($seen[$dedupeKey])) continue;
            $seen[$dedupeKey] = true;

            $row = [
                'id'        => $rule->id,
                'required'  => $rule->required,
                'is_custom' => !$rule->isLeadColumn(),
                'stage'     => $rule->_stage_label ?? null,
            ];

            if ($rule->isLeadColumn()) {
                $row['field_key']  = $rule->lead_column;
                $row['field_name'] = $this->humanizeColumn($rule->lead_column);
                $row['field_type'] = $this->fieldTypeForColumn($rule->lead_column);
                $row['options']    = $this->optionsForColumn($rule->lead_column);
                $row['mask']       = $this->defaultMaskForColumn($rule->lead_column);
                $row['current_value'] = $lead?->getAttribute($rule->lead_column);
            } else {
                $cf = $rule->customField;
                $row['field_key']  = $cf?->slug;
                $row['field_name'] = $cf?->name;
                $row['field_type'] = $cf?->type;
                $row['options']    = $cf?->options;
                $row['mask']       = $cf?->mask;
                $row['custom_field_id'] = $cf?->id;
                $row['current_value'] = $lead && $cf ? $lead->customValue($cf->slug) : null;
            }

            $row['is_filled'] = !empty($row['current_value']);

            $result[] = $row;
        }

        return response()->json($result);
    }

    /**
     * Máscara sugerida por coluna padrão do lead.
     * Telefone já vem mascarado no cadastro, mas aqui cobrimos o caso
     * de campo obrigatório aparecer no modal de mudança de status.
     */
    private function defaultMaskForColumn(string $column): ?string
    {
        return match ($column) {
            'phone'    => 'celular',
            default    => null,
        };
    }

    /**
     * Tipo de input sugerido por coluna do lead. Pra FKs (empreendimento,
     * source, corretor) o modal deve renderizar como <select>, não texto.
     */
    private function fieldTypeForColumn(string $column): string
    {
        return match ($column) {
            'empreendimento_id', 'source_id', 'assigned_user_id' => 'select',
            default => 'text',
        };
    }

    /**
     * Options pra colunas que são FK. Devolve array de
     * [{value, label}] — o frontend aceita ambos (array de string e
     * array de objeto com value/label).
     *
     * Evita quebrar o modal quando a tabela está vazia: devolve array
     * vazio e o select fica com só "Selecione".
     */
    private function optionsForColumn(string $column): ?array
    {
        return match ($column) {
            'empreendimento_id' => Empreendimento::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($e) => ['value' => (string) $e->id, 'label' => $e->name])
                ->values()
                ->toArray(),

            'source_id' => LeadSource::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($s) => ['value' => (string) $s->id, 'label' => $s->name])
                ->values()
                ->toArray(),

            'assigned_user_id' => User::query()
                ->where('active', true)
                ->whereIn('role', ['corretor', 'gestor'])
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($u) => ['value' => (string) $u->id, 'label' => $u->name])
                ->values()
                ->toArray(),

            default => null,
        };
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
