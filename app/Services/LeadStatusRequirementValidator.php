<?php

namespace App\Services;

use App\Models\CustomField;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\LeadSubstatus;
use App\Models\StatusRequiredField;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Valida se um lead pode mudar para um novo status/substatus baseado nas regras
 * de campos obrigatórios configuradas em `status_required_fields`.
 *
 * Regra principal:
 *   - Se o usuário PULAR etapas (target.order > current.order + 1), os campos
 *     obrigatórios de TODAS as etapas intermediárias também precisam estar
 *     preenchidos. Isso garante histórico comercial completo.
 *   - Para o substatus, só valida o substatus destino (os intermediários de
 *     substatus não fazem sentido cobrar em salto).
 *
 * A validação considera campos que JÁ estão preenchidos no lead + campos que
 * estão chegando no request (pra permitir preencher tudo de uma vez).
 *
 * Usado em:
 *  - LeadController@update (mudança de status na página do lead)
 *  - KanbanController@move (arrastar entre colunas)
 */
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

        // Busca regras aplicáveis: de TODAS as etapas do percurso
        $rules = $this->collectRulesForTransition(
            $lead->status_id,
            $effectiveStatusId,
            $effectiveSubstatusId
        );

        if ($rules->isEmpty()) {
            return;
        }

        // Valores chegando no request
        $incomingCustomBySlug = collect($incomingCustomValues)
            ->keyBy('slug')
            ->map(fn($e) => $e['value'] ?? null);

        // Valores atuais do lead
        $lead->loadMissing('customFieldValues.customField');
        $currentCustomBySlug = $lead->customFieldValues
            ->mapWithKeys(fn($v) => [$v->customField?->slug => $v->value])
            ->filter(fn($_, $slug) => $slug !== null);

        $missing = [];
        $seen    = []; // dedupe: evita pedir o mesmo campo duas vezes

        foreach ($rules as $rule) {
            if (!$rule->required) continue;

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

    /**
     * Coleta TODAS as regras que se aplicam à transição, inclusive etapas
     * intermediárias caso haja salto.
     *
     *  - currentStatusId → targetStatusId : considera todos os status com
     *      currentStatus.order < s.order <= targetStatus.order
     *      (quando estiver indo pra frente; se o target.order <= current.order,
     *       só olha o target).
     *  - targetSubstatusId : adiciona regras do substatus destino.
     *
     * Retorna coleção de StatusRequiredField com uma propriedade extra
     * _stage_label (nome da etapa de origem da regra) pra UI exibir.
     */
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

        // Mapa id→nome de todos os status envolvidos (pra rotular as regras)
        $statusesMap = LeadStatus::whereIn('id', $statusIdsToCheck)
            ->orderBy('order')
            ->get()
            ->keyBy('id');

        // Separa TARGET das intermediárias.
        // As intermediárias são todas menos a última (que é o target).
        $targetStatusIdFromPath = end($statusIdsToCheck) ?: null;
        $intermediateStatusIds  = array_slice($statusIdsToCheck, 0, -1);

        // -------- 1) Regras de STATUS (intermediárias + target) --------
        if (!empty($statusIdsToCheck)) {
            $statusRules = StatusRequiredField::with('customField')
                ->where('required', true)
                ->whereIn('lead_status_id', $statusIdsToCheck)
                ->get();

            foreach ($statusRules as $r) {
                $r->_stage_label = $statusesMap->get($r->lead_status_id)?->name;
                $rules->push($r);
            }
        }

        // -------- 2) Regras de SUBSTATUS das etapas intermediárias --------
        // Quando o lead PULA uma etapa, não sabemos por qual substatus ele
        // "teria passado", então cobramos a UNIÃO dos obrigatórios de todos
        // os substatuses dessa etapa intermediária.
        if (!empty($intermediateStatusIds)) {
            $intermediateSubs = LeadSubstatus::whereIn('lead_status_id', $intermediateStatusIds)
                ->orderBy('lead_status_id')
                ->orderBy('order')
                ->get();

            if ($intermediateSubs->isNotEmpty()) {
                $intermediateSubIds = $intermediateSubs->pluck('id')->all();

                $intermediateSubRules = StatusRequiredField::with('customField')
                    ->where('required', true)
                    ->whereIn('lead_substatus_id', $intermediateSubIds)
                    ->get();

                $subsById = $intermediateSubs->keyBy('id');

                foreach ($intermediateSubRules as $r) {
                    $sub = $subsById->get($r->lead_substatus_id);
                    if (!$sub) continue;
                    $statusName = $statusesMap->get($sub->lead_status_id)?->name;
                    $r->_stage_label = ($statusName ? $statusName . ' → ' : '') . $sub->name;
                    $rules->push($r);
                }
            }
        }

        // -------- 3) Regras do SUBSTATUS destino (específico) --------
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

    /**
     * Resolve quais IDs de status devem ser validados.
     *
     * - Se o lead não tem status atual: valida só o destino.
     * - Se target.order > current.order: valida todas as etapas no intervalo
     *   (current.order, target.order].
     * - Caso contrário (voltando ou ficando na mesma): só o destino.
     */
    private function intermediateAndTargetStatusIds(?int $currentStatusId, ?int $targetStatusId): array
    {
        if (!$targetStatusId) {
            return [];
        }

        $target = LeadStatus::find($targetStatusId);
        if (!$target) return [];

        if (!$currentStatusId) {
            return [$target->id];
        }

        $current = LeadStatus::find($currentStatusId);
        if (!$current) return [$target->id];

        // Indo pra trás ou parando no mesmo → só valida destino
        if ($target->order <= $current->order) {
            return [$target->id];
        }

        // Indo pra frente com (ou sem) salto: pega todas do intervalo (current, target]
        return LeadStatus::where('order', '>', $current->order)
            ->where('order', '<=', $target->order)
            ->orderBy('order')
            ->pluck('id')
            ->all();
    }

    private function isEmpty($value): bool
    {
        if ($value === null) return true;
        if (is_string($value) && trim($value) === '') return true;
        if (is_array($value) && empty($value)) return true;
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
}
