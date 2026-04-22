<?php

namespace App\Http\Controllers;

use App\Models\EmpreendimentoFieldDefinition;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * @group Admin - Campos Personalizados (Empreendimento)
 *
 * Endpoints para gerenciar os campos personalizados dos empreendimentos.
 * Apenas usuários administradores podem acessar.
 *
 * Tipos suportados:
 *   - counter  (contador ±, inteiro não-negativo — ex: quartos, banheiros, vagas)
 *   - boolean  (toggle sim/não — ex: piscina, academia, pet friendly)
 *   - text     (texto livre — ex: observações, endereço)
 *   - number   (número livre — ex: área em m², andar, condomínio)
 *   - select   (dropdown com options fixas — ex: orientação solar)
 *
 * @authenticated
 */
class EmpreendimentoFieldDefinitionController extends Controller
{
    /**
     * Lista fechada de tipos permitidos. Mantida em um só lugar pra evitar
     * desvio entre controller/model/frontend. Se precisar adicionar novo tipo,
     * atualiza aqui + no CFG do configuracoes.js + em empreendimentoCadastro.js.
     */
    private const ALLOWED_TYPES = ['counter', 'boolean', 'text', 'number', 'select'];

    public function index()
    {
        return EmpreendimentoFieldDefinition::orderBy('group')
            ->orderBy('order')
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        $data = $this->normalize($data);

        // Gera slug automaticamente se não foi enviado.
        if (empty($data['slug'])) {
            $data['slug'] = $this->generateUniqueSlug($data['name']);
        }

        // Garante unicidade (redundância com o rule, mas útil pro autogerado).
        $data['slug'] = $this->ensureUniqueSlug($data['slug']);

        return EmpreendimentoFieldDefinition::create($data);
    }

    public function show(EmpreendimentoFieldDefinition $empreendimentoFieldDefinition)
    {
        return $empreendimentoFieldDefinition;
    }

    public function update(
        Request $request,
        EmpreendimentoFieldDefinition $empreendimentoFieldDefinition
    ) {
        $data = $this->validatePayload($request, $empreendimentoFieldDefinition->id);
        $data = $this->normalize($data);

        // Não permitimos mudar o slug depois de criado — valores já podem estar
        // vinculados e frontends podem referenciar pelo slug. Se precisar
        // renomear, deleta e cria de novo.
        unset($data['slug']);

        $empreendimentoFieldDefinition->update($data);

        return $empreendimentoFieldDefinition;
    }

    public function destroy(EmpreendimentoFieldDefinition $empreendimentoFieldDefinition)
    {
        $empreendimentoFieldDefinition->delete();

        return response()->json(['success' => true]);
    }

    /* ==============================================================
     * HELPERS
     * ============================================================== */

    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        $slugRule = [
            'nullable', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/',
            Rule::unique('empreendimento_field_definitions', 'slug')->ignore($ignoreId),
        ];

        return $request->validate([
            'name'     => 'required|string|max:120',
            'slug'     => $slugRule,
            'type'     => ['required', 'string', Rule::in(self::ALLOWED_TYPES)],
            'unit'     => 'nullable|string|max:20',
            'group'    => 'nullable|string|max:80',
            'icon'     => 'nullable|string|max:64',
            'options'  => 'nullable|array',
            'options.*'=> 'nullable|string|max:120',
            'active'   => 'nullable|boolean',
            'required' => 'nullable|boolean',
            'order'    => 'nullable|integer|min:0',
        ]);
    }

    /**
     * Normaliza o payload de entrada:
     *  - `options` só faz sentido pra type=select; em outros tipos, força null.
     *  - Remove opções vazias do array.
     *  - Defaults explícitos pra booleans/order.
     */
    private function normalize(array $data): array
    {
        // options só pra select; limpa opções vazias.
        if (($data['type'] ?? null) === 'select') {
            $opts = array_values(array_filter(
                array_map(fn($o) => trim((string) $o), $data['options'] ?? []),
                fn($o) => $o !== ''
            ));
            $data['options'] = $opts ?: null;
        } else {
            $data['options'] = null;
        }

        $data['active']   = array_key_exists('active', $data)   ? (bool) $data['active']   : true;
        $data['required'] = array_key_exists('required', $data) ? (bool) $data['required'] : false;
        $data['order']    = $data['order'] ?? 0;

        return $data;
    }

    private function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name, '_');
        if ($base === '') $base = 'campo';
        return $this->ensureUniqueSlug($base);
    }

    private function ensureUniqueSlug(string $slug): string
    {
        $candidate = $slug;
        $i = 2;
        while (EmpreendimentoFieldDefinition::where('slug', $candidate)->exists()) {
            $candidate = $slug . '_' . $i;
            $i++;
        }
        return $candidate;
    }
}
