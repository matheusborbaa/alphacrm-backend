<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * Configurações globais do sistema (chave/valor).
 *
 * Leitura: qualquer usuário autenticado. Páginas da UI leem flags como
 * `watermark_enabled` pra decidir se ligam/desligam recursos.
 *
 * Escrita: só admin (verificado aqui com role check — não via middleware
 * pra manter consistência com o padrão do LeadDocumentController).
 *
 * Whitelist: `ALLOWED_KEYS` limita o que pode ser gravado — evita que
 * alguém crie chaves arbitrárias pela API.
 */
class SettingController extends Controller
{
    /**
     * Chaves conhecidas pelo sistema. Não aceita nada fora disso no set().
     * Cada entrada diz o tipo esperado (pra validação simples) e o default.
     */
    private const ALLOWED_KEYS = [
        'watermark_enabled' => ['type' => 'bool', 'default' => true],
    ];

    /** Lista TODAS as configurações (chave => valor). Só chaves conhecidas. */
    public function index()
    {
        $out = [];
        foreach (self::ALLOWED_KEYS as $key => $meta) {
            $out[$key] = Setting::get($key, $meta['default']);
        }
        return response()->json($out);
    }

    /** Lê UMA configuração pelo nome. */
    public function show(string $key)
    {
        if (!isset(self::ALLOWED_KEYS[$key])) {
            abort(404, 'Configuração desconhecida.');
        }
        $meta  = self::ALLOWED_KEYS[$key];
        $value = Setting::get($key, $meta['default']);
        return response()->json(['key' => $key, 'value' => $value]);
    }

    /**
     * Grava UMA configuração. Body: { "value": <...> }.
     * Só admin. Valor é validado contra o tipo esperado.
     */
    public function update(Request $request, string $key)
    {
        $this->ensureAdmin();

        if (!isset(self::ALLOWED_KEYS[$key])) {
            abort(404, 'Configuração desconhecida.');
        }

        $meta = self::ALLOWED_KEYS[$key];
        $raw  = $request->input('value');

        $value = $this->coerce($raw, $meta['type']);
        if ($value === null && $raw !== null && $meta['type'] !== 'mixed') {
            return response()->json([
                'message' => "Valor inválido pra '{$key}' (esperado {$meta['type']}).",
            ], 422);
        }

        Setting::set($key, $value);

        return response()->json(['key' => $key, 'value' => $value]);
    }

    /* ==============================================================
     * HELPERS
     * ============================================================== */

    /** Converte entrada crua pro tipo da chave. Null = erro. */
    private function coerce(mixed $raw, string $type): mixed
    {
        return match ($type) {
            'bool'   => is_bool($raw) ? $raw
                        : (in_array($raw, [1, '1', 'true', 'on', 'yes'], true) ? true
                        : (in_array($raw, [0, '0', 'false', 'off', 'no', '', null], true) ? false
                        : null)),
            'int'    => is_numeric($raw) ? (int) $raw : null,
            'string' => is_string($raw) ? $raw : null,
            default  => $raw, // 'mixed' — aceita tudo
        };
    }

    private function ensureAdmin(): void
    {
        $u = auth()->user();
        $role = strtolower(trim((string) ($u->role ?? '')));
        if ($role !== 'admin') {
            abort(403, 'Ação restrita ao administrador.');
        }
    }
}
