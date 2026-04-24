<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\TaskKindColor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sprint 3.6a — Cores customizáveis por tipo de tarefa.
 *
 * GET  /task-kind-colors          — qualquer autenticado (pra renderizar
 *                                   os badges coloridos no feed de tarefas)
 * PUT  /task-kind-colors/{kind}   — só admin, valida kind na whitelist
 *                                   Appointment::KINDS e color_hex no
 *                                   formato #RRGGBB.
 */
class TaskKindColorController extends Controller
{
    /** Retorna o mapa completo {kind: color_hex}. */
    public function index()
    {
        return response()->json(TaskKindColor::asMap());
    }

    /** Atualiza a cor de um kind. Admin-only. */
    public function update(Request $request, string $kind)
    {
        if (($request->user()?->role) !== 'admin') {
            return response()->json(['message' => 'Apenas administradores podem editar cores.'], 403);
        }

        if (!in_array($kind, Appointment::KINDS, true)) {
            return response()->json(['message' => 'Tipo de tarefa inválido.'], 404);
        }

        $data = $request->validate([
            // Aceita tanto #abc quanto #aabbcc; backend normaliza pro formato longo.
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

    /**
     * Normaliza "#abc" → "#aabbcc" e força lowercase pro storage ser
     * comparável sem case-sensitivity boba ("#FF0000" == "#ff0000").
     */
    private function normalizeHex(string $hex): string
    {
        $hex = strtolower($hex);
        if (strlen($hex) === 4) {
            // #abc → #aabbcc
            $hex = '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
        }
        return $hex;
    }
}
