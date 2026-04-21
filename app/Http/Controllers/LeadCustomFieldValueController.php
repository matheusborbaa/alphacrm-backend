<?php

namespace App\Http\Controllers;

use App\Models\CustomField;
use App\Models\Lead;
use App\Models\LeadCustomFieldValue;
use App\Models\LeadHistory;
use Illuminate\Http\Request;

/**
 * Salva/consulta os valores de campos customizados de um lead.
 *
 * Endpoints:
 *   GET  /leads/{lead}/custom-field-values        → lista atuais
 *   POST /leads/{lead}/custom-field-values        → salva em massa
 *     Body: { values: [ { slug: "motivo_descarte", value: "Preço" }, ... ] }
 */
class LeadCustomFieldValueController extends Controller
{
    public function index(Lead $lead)
    {
        return $lead->customFieldValues()
            ->with('customField')
            ->get()
            ->map(fn($v) => [
                'slug'  => $v->customField?->slug,
                'name'  => $v->customField?->name,
                'type'  => $v->customField?->type,
                'value' => $v->value,
            ]);
    }

    public function bulkStore(Request $request, Lead $lead)
    {
        $data = $request->validate([
            'values'         => 'required|array',
            'values.*.slug'  => 'required|string|exists:custom_fields,slug',
            'values.*.value' => 'nullable',
        ]);

        // Carrega os campos de uma vez (por slug) pra não dar N+1
        $slugs  = collect($data['values'])->pluck('slug')->unique();
        $fields = CustomField::whereIn('slug', $slugs)->get()->keyBy('slug');

        // Snapshot dos valores antigos (só dos campos afetados) pra gerar
        // histórico granular. Sem isso, quando o corretor preenche CPF pelo
        // wizard de campos obrigatórios no drag do kanban, a alteração
        // some — foi a queixa que motivou esse trecho.
        $oldByFieldId = LeadCustomFieldValue::where('lead_id', $lead->id)
            ->whereIn('custom_field_id', $fields->pluck('id'))
            ->get()
            ->keyBy('custom_field_id');

        $diffs = [];

        foreach ($data['values'] as $entry) {
            $field = $fields->get($entry['slug']);
            if (!$field) continue;

            $value = $this->normalizeValue($entry['value'], $field);
            $old   = $oldByFieldId->get($field->id)?->value;

            LeadCustomFieldValue::updateOrCreate(
                [
                    'lead_id'         => $lead->id,
                    'custom_field_id' => $field->id,
                ],
                ['value' => $value]
            );

            // null e '' são equivalentes pra fim de diff — evita poluir
            // o timeline com "preenchi e apaguei" no mesmo save.
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

        LeadHistory::logFieldChangeDiffs($lead, $diffs);

        return response()->json([
            'saved' => count($data['values']),
        ]);
    }

    /**
     * Normaliza o valor pra string (a coluna value é TEXT).
     * Arrays (checkbox múltiplo) viram JSON.
     */
    private function normalizeValue($value, CustomField $field): ?string
    {
        if ($value === null || $value === '') return null;

        if ($field->type === 'checkbox' && is_array($value)) {
            return json_encode(array_values($value), JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }
}
