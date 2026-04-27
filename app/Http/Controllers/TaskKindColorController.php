<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\TaskKindColor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskKindColorController extends Controller
{

    public function index()
    {
        return response()->json(TaskKindColor::asMap());
    }

    public function update(Request $request, string $kind)
    {
        if (($request->user()?->role) !== 'admin') {
            return response()->json(['message' => 'Apenas administradores podem editar cores.'], 403);
        }

        if (!in_array($kind, Appointment::validKindSlugs(), true)) {
            return response()->json(['message' => 'Tipo de tarefa inválido.'], 404);
        }

        $data = $request->validate([

            'color_hex' => ['required', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
        ], [
            'color_hex.regex' => 'Cor deve estar no formato #RRGGBB (ex.: #ef4444).',
        ]);

        $hex = $this->normalizeHex($data['color_hex']);

        TaskKindColor::updateOrCreate(
            ['kind' => $kind],
            ['color_hex' => $hex],
        );

        TaskKindColor::invalidateCache();

        return response()->json([
            'kind'      => $kind,
            'color_hex' => $hex,
        ]);
    }

    private function normalizeHex(string $hex): string
    {
        $hex = strtolower($hex);
        if (strlen($hex) === 4) {

            $hex = '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
        }
        return $hex;
    }
}
