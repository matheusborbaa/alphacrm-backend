<?php

namespace App\Http\Controllers;

use App\Models\TaskKind;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

// CRUD dos tipos de tarefa. Lista é pública (autenticada) pra alimentar selects; mutações exigem admin.
class TaskKindController extends Controller
{

    public function index()
    {
        return response()->json(TaskKind::activeList());
    }


    public function indexAll()
    {
        $this->ensureAdmin();
        return response()->json(
            TaskKind::orderBy('order')->orderBy('label')->get()
        );
    }

    public function store(Request $request)
    {
        $this->ensureAdmin();

        $data = $request->validate([
            'label'     => 'required|string|max:80',
            'color_hex' => ['required','string','regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'icon'      => 'nullable|string|max:40',
            'order'     => 'nullable|integer|min:0|max:9999',
            'kind'      => 'nullable|string|max:32|regex:/^[a-z0-9_]+$/',
        ], [
            'color_hex.regex' => 'Cor deve estar no formato #RRGGBB.',
            'kind.regex'      => 'Slug pode ter só letras minúsculas, números e _.',
        ]);


        $slug = $data['kind'] ?? Str::slug($data['label'], '_');
        $slug = preg_replace('/[^a-z0-9_]/', '', strtolower($slug));
        if (!$slug) {
            return response()->json(['message' => 'Não foi possível gerar um identificador a partir do nome.'], 422);
        }


        if (TaskKind::where('kind', $slug)->exists()) {
            return response()->json(['message' => 'Já existe um tipo com esse identificador.'], 409);
        }

        $kind = TaskKind::create([
            'kind'      => $slug,
            'label'     => trim($data['label']),
            'color_hex' => $this->normalizeHex($data['color_hex']),
            'icon'      => $data['icon'] ?? null,
            'order'     => $data['order'] ?? 100,
            'active'    => true,
        ]);

        TaskKind::invalidateCache();

        return response()->json($kind, 201);
    }

    public function update(Request $request, string $kind)
    {
        $this->ensureAdmin();

        $row = TaskKind::where('kind', $kind)->firstOrFail();

        $data = $request->validate([
            'label'     => 'sometimes|required|string|max:80',
            'color_hex' => ['sometimes','required','string','regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'icon'      => 'sometimes|nullable|string|max:40',
            'order'     => 'sometimes|integer|min:0|max:9999',
            'active'    => 'sometimes|boolean',
        ], [
            'color_hex.regex' => 'Cor deve estar no formato #RRGGBB.',
        ]);

        if (array_key_exists('color_hex', $data)) {
            $data['color_hex'] = $this->normalizeHex($data['color_hex']);
        }

        $row->update($data);
        TaskKind::invalidateCache();

        return response()->json($row->fresh());
    }


    public function destroy(string $kind)
    {
        $this->ensureAdmin();

        $row = TaskKind::where('kind', $kind)->firstOrFail();
        $row->update(['active' => false]);
        TaskKind::invalidateCache();

        return response()->json(['deactivated' => true, 'kind' => $kind]);
    }

    private function normalizeHex(string $hex): string
    {
        $hex = strtolower($hex);
        if (strlen($hex) === 4) {
            $hex = '#' . $hex[1].$hex[1] . $hex[2].$hex[2] . $hex[3].$hex[3];
        }
        return $hex;
    }

    private function ensureAdmin(): void
    {
        $u = auth()->user();
        $role = method_exists($u, 'effectiveRole') ? $u->effectiveRole() : strtolower((string) ($u->role ?? ''));
        if ($role !== 'admin') {
            abort(403, 'Apenas administradores podem editar tipos de tarefa.');
        }
    }
}
