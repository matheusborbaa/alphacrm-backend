<?php

namespace App\Http\Controllers;

use App\Models\CustomField;
use App\Models\Lead;
use App\Models\LeadCustomFieldValue;
use App\Models\LeadHistory;
use Illuminate\Http\Request;

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

        $slugs  = collect($data['values'])->pluck('slug')->unique();
        $fields = CustomField::whereIn('slug', $slugs)->get()->keyBy('slug');

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

    private function normalizeValue($value, CustomField $field): ?string
    {
        if ($value === null || $value === '') return null;

        if ($field->type === 'checkbox' && is_array($value)) {
            return json_encode(array_values($value), JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }
}
