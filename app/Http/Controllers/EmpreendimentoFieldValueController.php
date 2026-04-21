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
     * Listar campos personalizados do empreendimento
     *
     * Retorna todos os campos personalizados ativos,
     * junto com seus respectivos valores (se existirem)
     * para um empreendimento específico.
     *
     * Esse endpoint é utilizado para montar o formulário
     * dinâmico no CRM.
     *
     * @urlParam empreendimento integer required ID do empreendimento. Example: 1
     */
    public function index(Empreendimento $empreendimento)
    {
        $definitions = EmpreendimentoFieldDefinition::where('active', true)
            ->orderBy('group')
            ->orderBy('order')
            ->get();

        $values = $empreendimento->fieldValues
            ->keyBy('field_definition_id');

        return $definitions->map(function ($field) use ($values) {
            return [
                'id'    => $field->id,
                'name'  => $field->name,
                'slug'  => $field->slug,
                'type'  => $field->type,
                'unit'  => $field->unit,
                'group' => $field->group,
                'icon'  => $field->icon,
                'value' => $values[$field->id]->value ?? null,
            ];
        });
    }

    /**
     * Salvar campos personalizados do empreendimento
     *
     * Cria ou atualiza os valores dos campos personalizados
     * de um empreendimento. O processo é feito em lote,
     * utilizando UPSERT (updateOrCreate).
     *
     * @urlParam empreendimento integer required ID do empreendimento. Example: 1
     *
     * @bodyParam fields array required Lista de campos personalizados.
     * @bodyParam fields[].field_definition_id integer required ID da definição do campo. Example: 1
     * @bodyParam fields[].value string Valor do campo. Example: 64
     */

public function storeCadastro(Request $request, $id)
{
    $fields = $request->input('fields', []);

    foreach ($fields as $field) {
        \App\Models\EmpreendimentoFieldValue::create([
            'empreendimento_id' => $id,
            'field_definition_id' => $field['field_definition_id'],
            'value' => $field['value']
        ]);
    }

    return response()->json(['message'=>'ok']);
}




    public function store(Request $request, Empreendimento $empreendimento)
    {
        $data = $request->validate([
            'fields' => 'required|array',
            'fields.*.field_definition_id' => 'required|exists:empreendimento_field_definitions,id',
            'fields.*.value' => 'nullable'
        ]);

        foreach ($data['fields'] as $item) {
            EmpreendimentoFieldValue::updateOrCreate(
                [
                    'empreendimento_id' => $empreendimento->id,
                    'field_definition_id' => $item['field_definition_id'],
                ],
                [
                    'value' => $item['value'],
                ]
            );
        }

        return response()->json([
            'success' => true
        ]);
    }
}
