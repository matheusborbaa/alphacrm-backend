<?php

namespace App\Http\Controllers;

use App\Models\EmpreendimentoFieldDefinition;
use Illuminate\Http\Request;

/**
 * @group Admin - Campos Personalizados
 *
 * Endpoints para gerenciar os campos personalizados dos empreendimentos.
 * Apenas usuários administradores podem acessar.
 *
 * @authenticated
 */
class EmpreendimentoFieldDefinitionController extends Controller
{
    /**
     * Listar campos personalizados
     *
     * Retorna todos os campos personalizados cadastrados,
     * ordenados por grupo e ordem.
     */
    public function index()
    {
        return EmpreendimentoFieldDefinition::orderBy('group')
            ->orderBy('order')
            ->get();
    }

    /**
     * Criar campo personalizado
     *
     * Cria um novo campo personalizado para empreendimentos.
     *
     * @bodyParam name string required Nome do campo. Example: Dormitórios
     * @bodyParam slug string required Identificador único. Example: dormitorios
     * @bodyParam type string required Tipo do campo. Example: number
     * @bodyParam unit string Unidade do campo. Example: m²
     * @bodyParam group string Grupo do campo. Example: Características do Imóvel
     * @bodyParam icon string Ícone do campo (FontAwesome). Example: fa-bed
     * @bodyParam order integer Ordem de exibição. Example: 1
     * @bodyParam active boolean Campo ativo ou não. Example: true
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'   => 'required|string',
            'slug'   => 'required|string|unique:empreendimento_field_definitions,slug',
            'type'   => 'required|string',
            'unit'   => 'nullable|string',
            'group'  => 'nullable|string',
            'icon'   => 'nullable|string',
            'order'  => 'nullable|integer',
            'active' => 'boolean',
        ]);

        return EmpreendimentoFieldDefinition::create($data);
    }

    /**
     * Exibir campo personalizado
     *
     * Retorna os dados de um campo específico.
     */
    public function show(EmpreendimentoFieldDefinition $empreendimentoFieldDefinition)
    {
        return $empreendimentoFieldDefinition;
    }

    /**
     * Atualizar campo personalizado
     *
     * Atualiza os dados de um campo personalizado existente.
     *
     * @bodyParam name string Nome do campo.
     * @bodyParam type string Tipo do campo.
     * @bodyParam unit string Unidade do campo.
     * @bodyParam group string Grupo do campo.
     * @bodyParam icon string Ícone do campo.
     * @bodyParam order integer Ordem de exibição.
     * @bodyParam active boolean Campo ativo ou não.
     */
    public function update(
        Request $request,
        EmpreendimentoFieldDefinition $empreendimentoFieldDefinition
    ) {
        $data = $request->validate([
            'name'   => 'required|string',
            'type'   => 'required|string',
            'unit'   => 'nullable|string',
            'group'  => 'nullable|string',
            'icon'   => 'nullable|string',
            'order'  => 'nullable|integer',
            'active' => 'boolean',
        ]);

        $empreendimentoFieldDefinition->update($data);

        return $empreendimentoFieldDefinition;
    }

    /**
     * Remover campo personalizado
     *
     * Remove um campo personalizado do sistema.
     */
    public function destroy(EmpreendimentoFieldDefinition $empreendimentoFieldDefinition)
    {
        $empreendimentoFieldDefinition->delete();

        return response()->json([
            'success' => true
        ]);
    }
}
