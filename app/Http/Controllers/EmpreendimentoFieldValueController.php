<?php

namespace App\Http\Controllers;

use App\Models\Empreendimento;
use App\Models\EmpreendimentoFieldDefinition;
use App\Models\EmpreendimentoFieldValue;
use Illuminate\Http\Request;

class EmpreendimentoFieldValueController extends Controller
{

    public function index(Empreendimento $empreendimento)
    {
        $definitions = EmpreendimentoFieldDefinition::where('active', true)
            ->orderBy('group')
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        $values = $empreendimento->fieldValues
            ->keyBy('field_definition_id');

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

    public function storeCadastro(Request $request, $id)
    {

        $empreendimento = Empreendimento::findOrFail($id);

        $data = $request->validate([
            'fields'                      => 'required|array',
            'fields.*.field_definition_id'=> 'required|integer|exists:empreendimento_field_definitions,id',
            'fields.*.value'              => 'nullable',
        ]);

        foreach ($data['fields'] as $item) {
            EmpreendimentoFieldValue::updateOrCreate(
                [
                    'empreendimento_id'   => $empreendimento->id,
                    'field_definition_id' => $item['field_definition_id'],
                ],
                [

                    'value' => $this->normalizeValue($item['value'] ?? null),
                ]
            );
        }

        return response()->json(['message' => 'ok']);
    }

    public function store(Request $request, Empreendimento $empreendimento)
    {
        $data = $request->validate([
            'fields'                       => 'required|array',
            'fields.*.field_definition_id' => 'required|exists:empreendimento_field_definitions,id',
            'fields.*.value'               => 'nullable',
        ]);

        foreach ($data['fields'] as $item) {
            EmpreendimentoFieldValue::updateOrCreate(
                [
                    'empreendimento_id'   => $empreendimento->id,
                    'field_definition_id' => $item['field_definition_id'],
                ],
                [
                    'value' => $this->normalizeValue($item['value'] ?? null),
                ]
            );
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
