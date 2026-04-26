<?php

namespace App\Http\Controllers;

use App\Models\CustomField;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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

        $customField->delete();

        return response()->json(['deleted' => true]);
    }

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

            'mask'         => ['nullable', 'string', 'max:64'],

            'is_sensitive' => 'boolean',
            'active'       => 'boolean',
            'order'        => 'integer|min:0',
        ];

        if ($isFile) {

            $rules['options']            = 'nullable|array';
            $rules['options.max_mb']     = 'nullable|integer|min:1|max:200';
            $rules['options.accept']     = 'nullable|string|max:255';
        } else {

            $rules['options']   = 'nullable|array';
            $rules['options.*'] = 'string|max:255';
        }

        $data = $request->validate($rules);

        if ($isFile) {
            $data['mask'] = null;
        }

        return $data;
    }

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
