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

            // Regra de tarefa obrigatória: bloqueia avanço se o lead não
            // tem ao menos 1 appointment do tipo 'task' que bate com o
            // kind/completed exigidos. Dedup por (stage, kind, completed).
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

        // -------- 1) Regras de STATUS (inicio -> target, inclusive) --------
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

        // NOTA: regras de substatus INTERMEDIÁRIOS não são cobradas.
        // O lead pode ter passado por qualquer substatus (ou nenhum) dentro
        // de uma etapa — não temos como saber retroativamente. Pedir a
        // união de todos os obrigatórios de substatus da etapa vira uma
        // enxurrada pro usuário. Se alguma regra precisa ser realmente
        // transversal à etapa, o admin deve configurá-la no STATUS e não
        // no substatus.

        // -------- 2) Regras do SUBSTATUS destino (específico) --------
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
     * Estratégia "desde o início": sempre cobra TODAS as etapas com
     * order <= target.order. Se o campo já estiver preenchido (seja no
     * próprio lead ou no incomingData), o filtro de `isEmpty` no validate()
     * ou de `is_filled` no controller garante que ele não será pedido
     * novamente. Isso cobre os cenários em que:
     *   - o lead foi importado direto numa etapa avançada;
     *   - o admin criou regras novas DEPOIS do lead ter passado pela etapa;
     *   - o lead está voltando de uma etapa adiante e alguma regra antiga
     *     nunca foi preenchida.
     *
     * Quando target.order <= current.order (voltando), ainda assim pegamos
     * tudo até o destino: os campos ja preenchidos simplesmente nao viram
     * "missing" e não aparecem no modal.
     */
    private function intermediateAndTargetStatusIds(?int $currentStatusId, ?int $targetStatusId): array
    {
        if (!$targetStatusId) {
            return [];
        }

        $target = LeadStatus::find($targetStatusId);
        if (!$target) return [];

        // Todas as etapas do início até o destino (inclusive)
        return LeadStatus::where('order', '<=', $target->order)
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

    /** Espelha o método do controller pra manter a mensagem consistente. */
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
