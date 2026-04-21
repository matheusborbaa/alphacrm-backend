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
     */
    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name'    => 'required|string|max:255',
            'slug'    => [
                'nullable',
                'string',
                'max:100',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('custom_fields', 'slug')->ignore($ignoreId),
            ],
            'type'    => ['required', Rule::in(CustomField::TYPES)],
            'options' => 'nullable|array',
            'options.*' => 'string|max:255',
            // Máscara: preset conhecido OU padrão livre com 0/A/* + literais
            'mask'    => ['nullable', 'string', 'max:64'],
            'active'  => 'boolean',
            'order'   => 'integer|min:0',
        ]);
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
