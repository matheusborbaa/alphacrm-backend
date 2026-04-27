<?php

namespace App\Services;

use App\Models\CustomField;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\LeadSubstatus;
use App\Models\StatusRequiredField;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class LeadStatusRequirementValidator
{
    public function validate(
        Lead $lead,
        ?int $newStatusId,
        ?int $newSubstatusId,
        array $incomingData = [],
        array $incomingCustomValues = []
    ): void {

        $statusChanged    = $newStatusId !== null && $newStatusId !== $lead->status_id;
        $substatusChanged = $newSubstatusId !== null && $newSubstatusId !== $lead->lead_substatus_id;

        if (!$statusChanged && !$substatusChanged) {
            return;
        }

        $effectiveStatusId    = $newStatusId    ?? $lead->status_id;
        $effectiveSubstatusId = $newSubstatusId ?? $lead->lead_substatus_id;

        $rules = $this->collectRulesForTransition(
            $lead->status_id,
            $effectiveStatusId,
            $effectiveSubstatusId
        );

        if ($rules->isEmpty()) {
            return;
        }

        $incomingCustomBySlug = collect($incomingCustomValues)
            ->keyBy('slug')
            ->map(fn($e) => $e['value'] ?? null);

        $lead->loadMissing('customFieldValues.customField');
        $currentCustomBySlug = $lead->customFieldValues
            ->mapWithKeys(fn($v) => [$v->customField?->slug => $v->value])
            ->filter(fn($_, $slug) => $slug !== null);

        $missing = [];
        $seen    = [];

        foreach ($rules as $rule) {
            if (!$rule->required) continue;

            if ($rule->isTaskRequirement()) {
                $kind     = $rule->require_task_kind ?: 'any';
                $needDone = (bool) $rule->require_task_completed;
                $dedupeKey = 'task:' . ($rule->_stage_label ?? 'global')
                            . ':' . $kind . ':' . ($needDone ? '1' : '0');
                if (isset($seen[$dedupeKey])) continue;

                $q = $lead->appointments()->where('type', 'task');
                if ($rule->require_task_kind) {
                    $q->where('task_kind', $rule->require_task_kind);
                }
                if ($needDone) {
                    $q->whereNotNull('completed_at');
                }
                $hasTask = $q->exists();

                if (!$hasTask) {
                    $missing[] = [
                        'field_key'      => '__task__',
                        'field_name'     => $this->humanizeTaskRule($rule),
                        'is_custom'      => false,
                        'is_task'        => true,
                        'task_kind'      => $rule->require_task_kind,
                        'task_completed' => $needDone,
                        'stage'          => $rule->_stage_label ?? null,
                    ];
                    $seen[$dedupeKey] = true;
                }
                continue;
            }

            $dedupeKey = $rule->isLeadColumn()
                ? 'col:' . $rule->lead_column
                : 'cf:'  . $rule->custom_field_id;

            if (isset($seen[$dedupeKey])) continue;

            if ($rule->isLeadColumn()) {
                $col   = $rule->lead_column;
                $value = $incomingData[$col] ?? $lead->getAttribute($col);

                if ($this->isEmpty($value)) {
                    $missing[] = [
                        'field_key'  => $col,
                        'field_name' => $this->humanizeColumn($col),
                        'is_custom'  => false,
                        'stage'      => $rule->_stage_label ?? null,
                    ];
                    $seen[$dedupeKey] = true;
                }
            } else {
                $cf = $rule->customField;
                if (!$cf) continue;

                $value = $incomingCustomBySlug[$cf->slug]
                      ?? $currentCustomBySlug[$cf->slug]
                      ?? null;

                if ($this->isEmpty($value)) {
                    $missing[] = [
                        'field_key'  => $cf->slug,
                        'field_name' => $cf->name,
                        'is_custom'  => true,
                        'field_type' => $cf->type,
                        'options'    => $cf->options,
                        'stage'      => $rule->_stage_label ?? null,
                    ];
                    $seen[$dedupeKey] = true;
                }
            }
        }

        if (!empty($missing)) {
            throw ValidationException::withMessages([
                'missing_required_fields' => $missing,
            ])->status(422);
        }
    }

    public function collectRulesForTransition(
        ?int $currentStatusId,
        ?int $targetStatusId,
        ?int $targetSubstatusId = null
    ): Collection {

        $statusIdsToCheck = $this->intermediateAndTargetStatusIds($currentStatusId, $targetStatusId);

        $rules = collect();

        if (empty($statusIdsToCheck) && !$targetSubstatusId) {
            return $rules;
        }

        $statusesMap = LeadStatus::whereIn('id', $statusIdsToCheck)
            ->orderBy('order')
            ->get()
            ->keyBy('id');

        if (!empty($statusIdsToCheck)) {
            $statusRules = StatusRequiredField::with('customField')
                ->where('required', true)
                ->whereIn('lead_status_id', $statusIdsToCheck)
                ->get();

            foreach ($statusRules as $r) {
                $r->_stage_label = $statusesMap->get($r->lead_status_id)?->name;
                $rules->push($r);
            }




            $strictMode = false;
            try {
                $strictMode = (bool) \App\Models\Setting::get('pipeline_strict_mode', false);
            } catch (\Throwable $e) {  }

            $intermediateSubstatusRules = StatusRequiredField::with(['customField', 'substatus.status'])
                ->where('required', true)
                ->when(!$strictMode, function ($q) {
                    $q->where('enforce_on_skip', true);
                })
                ->whereNotNull('lead_substatus_id')

                ->where(function ($q) use ($targetSubstatusId) {
                    if ($targetSubstatusId) $q->where('lead_substatus_id', '!=', $targetSubstatusId);
                })
                ->whereHas('substatus', function ($q) use ($statusIdsToCheck) {
                    $q->whereIn('lead_status_id', $statusIdsToCheck);
                })
                ->get();

            foreach ($intermediateSubstatusRules as $r) {
                $sub = $r->substatus;
                $label = ($sub?->status?->name ? $sub->status->name . ' → ' : '') . ($sub?->name ?? '');
                $r->_stage_label = $label;
                $rules->push($r);
            }
        }

        if ($targetSubstatusId) {
            $sub = LeadSubstatus::with('status')->find($targetSubstatusId);
            if ($sub) {
                $label = ($sub->status?->name ? $sub->status->name . ' → ' : '') . $sub->name;

                $subRules = StatusRequiredField::with('customField')
                    ->where('required', true)
                    ->where('lead_substatus_id', $targetSubstatusId)
                    ->get();

                foreach ($subRules as $r) {
                    $r->_stage_label = $label;
                    $rules->push($r);
                }
            }
        }

        return $rules;
    }

    private function intermediateAndTargetStatusIds(?int $currentStatusId, ?int $targetStatusId): array
    {
        if (!$targetStatusId) {
            return [];
        }

        $target = LeadStatus::find($targetStatusId);
        if (!$target) return [];

        if (!empty($target->is_discard)) {
            return [(int) $target->id];
        }

        return LeadStatus::where('order', '<=', $target->order)
            ->orderBy('order')
            ->pluck('id')
            ->all();
    }

    private function isEmpty($value): bool
    {
        if ($value === null)  return true;
        if ($value === false) return true;
        if (is_string($value) && trim($value) === '') return true;
        if (is_array($value)  && empty($value))       return true;
        if (is_numeric($value) && (float) $value == 0.0) return true;
        return false;
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
}
