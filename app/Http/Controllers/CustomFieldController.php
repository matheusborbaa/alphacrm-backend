<?php

namespace App\Http\Controllers;

use App\Models\CustomField;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * CRUD do catálogo de campos customizados.
 *
 * Usado pela tela admin pra criar/editar/listar campos que poderão ser
 * vinculados a status/substatus.
 */
class CustomFieldController extends Controller
{
    public function index()
    {
        return CustomField::orderBy('order')->orderBy('name')->get();
    }

    public function show(CustomField $customField)
    {
        return $customField;
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        // Se o usuário não passar slug, gera a partir do nome
        if (empty($data['slug'])) {
            $data['slug'] = $this->makeUniqueSlug($data['name']);
        }

        $field = CustomField::create($data);

        return response()->json($field, 201);
    }

    public function update(Request $request, CustomField $customField)
    {
        $data = $this->validateData($request, $customField->id);

        $customField->update($data);

        return $customField;
    }

    public function destroy(CustomField $customField)
    {
        // cascade vai limpar status_required_fields e lead_custom_field_values
        $customField->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Valida os dados de criação/edição. Inclui validação de options pra select/checkbox.
     *
     * Pra type='file', `options` é tratada como dict de configuração
     * ({max_mb, accept}) em vez de array de string (select/checkbox).
     * `mask` não se aplica e é descartado.
     */
    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        $isFile = $request->input('type') === 'file';

        $rules = [
            'name'    => 'required|string|max:255',
            'slug'    => [
                'nullable',
                'string',
                'max:100',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('custom_fields', 'slug')->ignore($ignoreId),
            ],
            'type'    => ['required', Rule::in(CustomField::TYPES)],
            // Máscara: preset conhecido OU padrão livre com 0/A/* + literais.
            // Não se aplica a type=file.
            'mask'         => ['nullable', 'string', 'max:64'],
            // LGPD: marca o campo como dado pessoal sensível (CPF, RG, renda...).
            // Frontend mascara por padrão em listagens e histórico; valor
            // cleartext fica atrás do endpoint /leads/{id}/reveal (que loga).
            'is_sensitive' => 'boolean',
            'active'       => 'boolean',
            'order'        => 'integer|min:0',
        ];

        if ($isFile) {
            // Pra arquivo: options é um dict opcional com configs.
            //   max_mb: int — tamanho máximo em MB (default na const)
            //   accept: string — lista de extensões separadas por vírgula
            //                    (".pdf,.jpg,.png"); vazia = qualquer
            $rules['options']            = 'nullable|array';
            $rules['options.max_mb']     = 'nullable|integer|min:1|max:200';
            $rules['options.accept']     = 'nullable|string|max:255';
        } else {
            // Outros tipos: options é array sequencial de strings (select/checkbox).
            $rules['options']   = 'nullable|array';
            $rules['options.*'] = 'string|max:255';
        }

        $data = $request->validate($rules);

        // Sanitiza: type=file não usa mask
        if ($isFile) {
            $data['mask'] = null;
        }

        return $data;
    }

    /**
     * Gera um slug único baseado no nome (ex: "Motivo do Descarte" -> "motivo_do_descarte")
     */
    private function makeUniqueSlug(string $name): string
    {
        $base = Str::slug($name, '_');
        $slug = $base;
        $i = 1;

        while (CustomField::where('slug', $slug)->exists()) {
            $slug = $base . '_' . (++$i);
        }

        return $slug;
    }
}
