<?php

namespace App\Http\Controllers;

use App\Models\Empreendimento;
use App\Models\EmpreendimentoFieldDefinition;
use App\Models\EmpreendimentoFieldValue;
use Illuminate\Http\Request;

/**
 * @group Admin - Empreendimentos | Campos Dinâmicos
 *
 * Endpoints responsáveis por listar e salvar
 * os valores dos campos personalizados de um empreendimento.
 *
 * Esses endpoints são usados para montar formulários dinâmicos
 * no CRM, totalmente baseados em configuração.
 *
 * @authenticated
 */
class EmpreendimentoFieldValueController extends Controller
{
    /**
     * Lista todos os campos ativos (definitions) + os valores que esse
     * empreendimento tem pra cada um. É o endpoint-base do formulário
     * dinâmico de cadastro/edição.
     */
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
                'options'  => $field->options,   // array|null (só preenchido em type=select)
                'required' => (bool) $field->required,
                'value'    => $values[$field->id]->value ?? null,
            ];
        });
    }

    /**
     * UPSERT em massa. Usado tanto pelo fluxo de cadastro (POST
     * /empreendimentos/{id}/fields) quanto pelo admin
     * (POST /admin/empreendimentos/{id}/fields).
     *
     * Bug histórico corrigido: antes usava `create()` em loop, o que
     * duplicava valores a cada save/edit. Agora usa `updateOrCreate`
     * (chave composta = empreendimento_id + field_definition_id).
     */
    public function storeCadastro(Request $request, $id)
    {
        // Garante que o empreendimento existe (404 se não).
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
                    // Normaliza pra string — a coluna é TEXT. Bool vira "1"/"0".
                    'value' => $this->normalizeValue($item['value'] ?? null),
                ]
            );
        }

        return response()->json(['message' => 'ok']);
    }

    /**
     * Rota alternativa (admin). Mesma lógica; mantida por compat com
     * clients externos que já chamam /admin/empreendimentos/{id}/fields.
     */
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

    /**
     * Converte o payload recebido em string pra persistir em `value` (TEXT).
     *  - null/''/array vazio → null (apaga o valor).
     *  - bool → "1"/"0".
     *  - array (improvável mas defensivo) → JSON.
     *  - demais → string.
     */
    private function normalizeValue($v): ?string
    {
        if ($v === null) return null;
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_array($v)) return $v ? json_encode($v) : null;

        $s = (string) $v;
        return $s === '' ? null : $s;
    }
}
