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

    public function forTarget(Request $request, LeadStatusRequirementValidator $validator)
    {
        $request->validate([
            'status_id'    => 'nullable|exists:lead_status,id',
            'substatus_id' => 'nullable|exists:lead_substatus,id',
            'lead_id'      => 'nullable|exists:leads,id',
        ]);

        $targetStatusId    = $request->input('status_id');
        $targetSubstatusId = $request->input('substatus_id');

        if (!$targetStatusId && $targetSubstatusId) {
            $targetStatusId = LeadSubstatus::where('id', $targetSubstatusId)->value('lead_status_id');
        }

        if (!$targetStatusId && !$targetSubstatusId) {
            return response()->json([]);
        }

        $lead = $request->filled('lead_id')
            ? Lead::with('customFieldValues.customField')->find($request->lead_id)
            : null;

        $currentStatusId = $lead?->status_id;

        $rules = $validator->collectRulesForTransition(
            $currentStatusId,
            $targetStatusId,
            $targetSubstatusId
        );

        $seen   = [];
        $result = [];

        foreach ($rules as $rule) {
            if (!$rule->required) continue;

            if ($rule->isTaskRequirement()) {
                $kind     = $rule->require_task_kind ?: 'any';
                $needDone = (bool) $rule->require_task_completed;
                $dedupeKey = 'task:' . ($rule->_stage_label ?? 'global')
                            . ':' . $kind . ':' . ($needDone ? '1' : '0');
                if (isset($seen[$dedupeKey])) continue;
                $seen[$dedupeKey] = true;

                $hasTask = false;
                if ($lead) {
                    $q = $lead->appointments()->where('type', 'task');
                    if ($rule->require_task_kind) {
                        $q->where('task_kind', $rule->require_task_kind);
                    }
                    if ($needDone) {
                        $q->whereNotNull('completed_at');
                    }
                    $hasTask = $q->exists();
                }

                $result[] = [
                    'id'               => $rule->id,
                    'required'         => true,
                    'is_custom'        => false,
                    'is_task'          => true,
                    'stage'            => $rule->_stage_label ?? null,
                    'field_key'        => '__task__',
                    'field_name'       => $this->humanizeTaskRule($rule),
                    'field_type'       => 'task',
                    'task_kind'        => $rule->require_task_kind,
                    'task_completed'   => $needDone,
                    'current_value'    => null,
                    'is_filled'        => $hasTask,
                ];
                continue;
            }

            $dedupeKey = $rule->isLeadColumn()
                ? 'col:' . $rule->lead_column
                : 'cf:'  . $rule->custom_field_id;

            if (isset($seen[$dedupeKey])) continue;
            $seen[$dedupeKey] = true;

            $row = [
                'id'        => $rule->id,
                'required'  => $rule->required,
                'is_custom' => !$rule->isLeadColumn(),
                'is_task'   => false,
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

    private function defaultMaskForColumn(string $column): ?string
    {
        return match ($column) {
            'phone'    => 'celular',
            'value'    => 'moeda',
            default    => null,
        };
    }

    private function fieldTypeForColumn(string $column): string
    {
        return match ($column) {
            'empreendimento_id', 'source_id', 'assigned_user_id' => 'select',
            default => 'text',
        };
    }

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

    private function humanizeTaskRule(StatusRequiredField $rule): string
    {
        $kinds = [
            'ligacao'     => 'ligação',
            'whatsapp'    => 'WhatsApp',
            'email'       => 'e-mail',
            'followup'    => 'follow-up',
            'agendamento' => 'agendamento',
            'visita'      => 'visita presencial',
            'reuniao'     => 'reunião on-line',
            'anotacao'    => 'anotação',
            'generica'    => 'tarefa',
        ];
        $noun   = $rule->require_task_kind
            ? ($kinds[$rule->require_task_kind] ?? 'tarefa')
            : 'tarefa';
        $suffix = $rule->require_task_completed ? ' concluída' : '';
        return 'Registrar ' . $noun . $suffix;
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'lead_status_id'         => 'nullable|exists:lead_status,id',
            'lead_substatus_id'      => 'nullable|exists:lead_substatus,id',
            'lead_column'            => ['nullable', 'string', Rule::in(StatusRequiredField::ALLOWED_LEAD_COLUMNS)],
            'custom_field_id'        => 'nullable|exists:custom_fields,id',
            'required'               => 'boolean',
            'require_task'           => 'boolean',
            'require_task_kind'      => ['nullable', 'string', Rule::in(\App\Models\Appointment::validKindSlugs())],
            'require_task_completed' => 'boolean',
        ]);

        $hasStatus    = !empty($data['lead_status_id']);
        $hasSubstatus = !empty($data['lead_substatus_id']);
        $hasColumn    = !empty($data['lead_column']);
        $hasCustom    = !empty($data['custom_field_id']);
        $hasTaskRule  = !empty($data['require_task']);

        if ($hasStatus === $hasSubstatus) {
            throw ValidationException::withMessages([
                'target' => 'Informe exatamente um entre lead_status_id e lead_substatus_id.',
            ]);
        }

        if ($hasTaskRule) {
            if ($hasColumn || $hasCustom) {
                throw ValidationException::withMessages([
                    'field' => 'Regra de tarefa obrigatória não aceita lead_column nem custom_field_id.',
                ]);
            }
        } else {
            if ($hasColumn === $hasCustom) {
                throw ValidationException::withMessages([
                    'field' => 'Informe exatamente um entre lead_column e custom_field_id, ou marque require_task=true.',
                ]);
            }
        }

        return $data;
    }
}
