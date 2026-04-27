<?php

namespace App\Http\Controllers;

use App\Models\TipologiaFieldDefinition;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

// CRUD das definições de campos da tipologia. Mesmo padrão do EmpreendimentoFieldDefinitionController.
class TipologiaFieldDefinitionController extends Controller
{
    private const ALLOWED_TYPES = ['counter', 'boolean', 'text', 'number', 'number_range', 'select'];

    public function index()
    {
        return TipologiaFieldDefinition::orderBy('group')
            ->orderBy('order')
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        $data = $this->normalize($data);

        if (empty($data['slug'])) {
            $data['slug'] = $this->generateUniqueSlug($data['name']);
        }
        $data['slug'] = $this->ensureUniqueSlug($data['slug']);

        return TipologiaFieldDefinition::create($data);
    }

    public function show(TipologiaFieldDefinition $tipologiaFieldDefinition)
    {
        return $tipologiaFieldDefinition;
    }

    public function update(
        Request $request,
        TipologiaFieldDefinition $tipologiaFieldDefinition
    ) {
        $data = $this->validatePayload($request, $tipologiaFieldDefinition->id);
        $data = $this->normalize($data);

        unset($data['slug']);

        $tipologiaFieldDefinition->update($data);

        return $tipologiaFieldDefinition;
    }

    public function destroy(TipologiaFieldDefinition $tipologiaFieldDefinition)
    {
        $tipologiaFieldDefinition->delete();
        return response()->json(['success' => true]);
    }

    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        $slugRule = [
            'nullable', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/',
            Rule::unique('tipologia_field_definitions', 'slug')->ignore($ignoreId),
        ];

        return $request->validate([
            'name'      => 'required|string|max:120',
            'slug'      => $slugRule,
            'type'      => ['required', 'string', Rule::in(self::ALLOWED_TYPES)],
            'unit'      => 'nullable|string|max:20',
            'group'     => 'nullable|string|max:80',
            'icon'      => 'nullable|string|max:64',
            'options'   => 'nullable|array',
            'options.*' => 'nullable|string|max:120',
            'active'    => 'nullable|boolean',
            'required'  => 'nullable|boolean',
            'order'     => 'nullable|integer|min:0',
        ]);
    }

    private function normalize(array $data): array
    {
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
        while (TipologiaFieldDefinition::where('slug', $candidate)->exists()) {
            $candidate = $slug . '_' . $i;
            $i++;
        }
        return $candidate;
    }
}
