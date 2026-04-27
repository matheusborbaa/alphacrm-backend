<?php

namespace App\Http\Controllers;

use App\Models\Empreendimento;
use App\Models\EmpreendimentoTipologia;
use App\Models\TipologiaFieldDefinition;
use App\Models\TipologiaFieldValue;
use Illuminate\Http\Request;

/**
 * E5.4 — Leitura/escrita dos valores de campos customizados das tipologias.
 *
 * Endpoints:
 *   GET  /admin/tipologias/{tipologia}/fields           — defs ativas + valores atuais
 *   POST /admin/tipologias/{tipologia}/fields           — upsert em lote
 */
class TipologiaFieldValueController extends Controller
{
    public function index(EmpreendimentoTipologia $tipologia)
    {
        $definitions = TipologiaFieldDefinition::where('active', true)
            ->orderBy('group')
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        $values = $tipologia->fieldValues->keyBy('field_definition_id');

        return $definitions->map(function ($field) use ($values) {
            return [
                'id'       => $field->id,
                'name'     => $field->name,
                'slug'     => $field->slug,
                'type'     => $field->type,
                'unit'     => $field->unit,
                'group'    => $field->group,
                'icon'     => $field->icon,
                'options'  => $field->options,
                'required' => (bool) $field->required,
                'value'    => $values[$field->id]->value ?? null,
            ];
        });
    }

    public function store(Request $request, EmpreendimentoTipologia $tipologia)
    {
        $data = $request->validate([
            'fields'                       => 'required|array',
            'fields.*.field_definition_id' => 'required|integer|exists:tipologia_field_definitions,id',
            'fields.*.value'               => 'nullable',
        ]);


        $defIds = collect($data['fields'])->pluck('field_definition_id')->unique()->all();
        $defs   = TipologiaFieldDefinition::whereIn('id', $defIds)->get()->keyBy('id');


        $legacyMap = [
            'bedrooms'    => 'bedrooms',
            'suites'      => 'suites',
            'area_min_m2' => 'area_min_m2',
            'area_max_m2' => 'area_max_m2',
            'price_from'  => 'price_from',
        ];
        $touchedLegacy = false;

        foreach ($data['fields'] as $item) {
            $val = $this->normalizeValue($item['value'] ?? null);
            $defId = $item['field_definition_id'];

            if ($val === null) {
                TipologiaFieldValue::where('tipologia_id', $tipologia->id)
                    ->where('field_definition_id', $defId)
                    ->delete();
            } else {
                TipologiaFieldValue::updateOrCreate(
                    [
                        'tipologia_id'        => $tipologia->id,
                        'field_definition_id' => $defId,
                    ],
                    [
                        'value' => $val,
                    ]
                );
            }


            $def = $defs[$defId] ?? null;
            if ($def && isset($legacyMap[$def->slug])) {
                $col = $legacyMap[$def->slug];
                $tipologia->{$col} = $val;
                $touchedLegacy = true;
            }
        }

        if ($touchedLegacy) {
            $tipologia->save();
        }

        return response()->json(['success' => true]);
    }

    private function normalizeValue($v): ?string
    {
        if ($v === null) return null;
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_array($v)) return $v ? json_encode($v) : null;

        $s = (string) $v;
        return $s === '' ? null : $s;
    }
}
