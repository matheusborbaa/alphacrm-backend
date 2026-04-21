<?php

namespace App\Services;

use App\Models\CustomField;
use App\Models\Lead;
use App\Models\LeadSubstatus;
use App\Models\StatusRequiredField;
use Illuminate\Validation\ValidationException;

/**
 * Valida se um lead pode mudar para um novo status/substatus baseado nas regras
 * de campos obrigatórios configuradas em `status_required_fields`.
 *
 * A validação considera campos que JÁ estão preenchidos no lead + campos que
 * estão chegando no request (pra permitir preencher tudo de uma vez).
 *
 * Usado em:
 *  - LeadController@update (mudança de status na página do lead)
 *  - KanbanController@move (arrastar entre colunas)
 *  - LeadController@store (se quiser validar na criação)
 */
class LeadStatusRequirementValidator
{
    /**
     * Valida a transição. Lança ValidationException se faltar obrigatórios.
     *
     * @param Lead $lead Estado atual do lead (antes do update)
     * @param int|null $newStatusId Novo status pretendido. Null = não mudou.
     * @param int|null $newSubstatusId Novo substatus pretendido. Null = não mudou.
     * @param array $incomingData Outros campos chegando no mesmo request
     *                            (ex: ['phone' => '...', 'email' => '...']).
     *                            Valores aqui "contam" como preenchidos.
     * @param array $incomingCustomValues Valores de custom fields chegando junto,
     *                                    no formato [['slug' => '...', 'value' => '...']].
     *
     * @throws ValidationException Se algum campo obrigatório estiver vazio.
     */
    public function validate(
        Lead $lead,
        ?int $newStatusId,
        ?int $newSubstatusId,
        array $incomingData = [],
        array $incomingCustomValues = []
    ): void {

        // Se não está mudando nem status nem substatus, não há o que validar
        $statusChanged    = $newStatusId !== null && $newStatusId !== $lead->status_id;
        $substatusChanged = $newSubstatusId !== null && $newSubstatusId !== $lead->lead_substatus_id;

        if (!$statusChanged && !$substatusChanged) {
            return;
        }

        // Resolve o status/substatus efetivo após o update
        $effectiveStatusId    = $newStatusId    ?? $lead->status_id;
        $effectiveSubstatusId = $newSubstatusId ?? $lead->lead_substatus_id;

        // Busca regras aplicáveis: do substatus E do status pai
        $rules = $this->fetchRules($effectiveStatusId, $effectiveSubstatusId);

        if ($rules->isEmpty()) {
            return;
        }

        // Índice dos custom values chegando por slug
        $incomingCustomBySlug = collect($incomingCustomValues)
            ->keyBy('slug')
            ->map(fn($e) => $e['value'] ?? null);

        // Carrega os custom values atuais do lead (pra não perder tempo no loop)
        $lead->loadMissing('customFieldValues.customField');
        $currentCustomBySlug = $lead->customFieldValues
            ->mapWithKeys(fn($v) => [$v->customField?->slug => $v->value])
            ->filter(fn($_, $slug) => $slug !== null);

        $missing = [];

        foreach ($rules as $rule) {
            if (!$rule->required) continue;

            if ($rule->isLeadColumn()) {
                $col   = $rule->lead_column;
                $value = $incomingData[$col] ?? $lead->getAttribute($col);

                if ($this->isEmpty($value)) {
                    $missing[] = [
                        'field_key'  => $col,
                        'field_name' => $this->humanizeColumn($col),
                        'is_custom'  => false,
                    ];
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
                    ];
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
     * Busca as regras que se aplicam ao par (status, substatus).
     * Regras do status pai também contam quando há substatus.
     */
    private function fetchRules(?int $statusId, ?int $substatusId)
    {
        $query = StatusRequiredField::with('customField')->where('required', true);

        if ($substatusId) {
            $parentStatusId = LeadSubstatus::where('id', $substatusId)->value('lead_status_id');

            $query->where(function ($q) use ($substatusId, $parentStatusId) {
                $q->where('lead_substatus_id', $substatusId);
                if ($parentStatusId) {
                    $q->orWhere('lead_status_id', $parentStatusId);
                }
            });
        } elseif ($statusId) {
            $query->where('lead_status_id', $statusId);
        } else {
            return collect();
        }

        return $query->get();
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
