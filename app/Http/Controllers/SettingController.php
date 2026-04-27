<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{

    private const ALLOWED_KEYS = [

        'watermark_enabled'    => ['type' => 'bool', 'default' => true],
        'watermark_intensity'  => [
            'type'    => 'enum',
            'default' => 'sutil',
            'options' => ['sutil', 'medio', 'forte'],
        ],

        'doc_retention_days'   => [
            'type'    => 'int',
            'default' => 7,
            'min'     => 1,
            'max'     => 365,
        ],

        'doc_deletion_requires_approval' => [
            'type'    => 'bool',
            'default' => true,
        ],

        'lead_cooldown_enabled' => [
            'type'    => 'bool',
            'default' => false,
        ],
        'lead_cooldown_minutes' => [
            'type'    => 'int',
            'default' => 2,
            'min'     => 0,
            'max'     => 120,
        ],

        'lead_sla_enabled' => [
            'type'    => 'bool',
            'default' => true,
        ],
        'lead_sla_minutes' => [
            'type'    => 'int',
            'default' => 15,
            'min'     => 0,
            'max'     => 1440,
        ],

        'lead_sla_reassign_on_breach' => [
            'type'    => 'bool',
            'default' => true,
        ],

        'lead_first_status_id' => [
            'type'    => 'int_or_null',
            'default' => null,
        ],

        'lead_first_substatus_id' => [
            'type'    => 'int_or_null',
            'default' => null,
        ],

        'lead_after_first_contact_status_id' => [
            'type'    => 'int_or_null',
            'default' => null,
        ],
        'lead_after_first_contact_substatus_id' => [
            'type'    => 'int_or_null',
            'default' => null,
        ],

        'commission_trigger_status_ids' => [
            'type'    => 'int_array',
            'default' => [],
        ],
        'commission_trigger_substatus_ids' => [
            'type'    => 'int_array',
            'default' => [],
        ],

        'ui_rounded_corners' => [
            'type'    => 'bool',
            'default' => true,
        ],

        'chat_enabled' => [
            'type'    => 'bool',
            'default' => true,
        ],

        'corretor_area_enabled' => [
            'type'    => 'bool',
            'default' => true,
        ],

        'corretor_auto_offline_minutes' => [
            'type'    => 'int',
            'default' => 60,
            'min'     => 0,
            'max'     => 1440,
        ],

        'max_concurrent_sessions' => [
            'type'    => 'int',
            'default' => 2,
            'min'     => 1,
            'max'     => 10,
        ],

        'password_confirm_idle_minutes' => [
            'type'    => 'int',
            'default' => 30,
            'min'     => 0,
            'max'     => 1440,
        ],

        'leads_atencao_dias_sem_contato' => [
            'type'    => 'int',
            'default' => 5,
            'min'     => 1,
            'max'     => 30,
        ],

        'lead_orphan_reassign_after_minutes' => [
            'type'    => 'int',
            'default' => 30,
            'min'     => 0,
            'max'     => 1440,
        ],

        'lead_persistence_min_status_id' => [
            'type'    => 'int_or_null',
            'default' => null,
        ],


        'default_theme' => [
            'type'    => 'enum',
            'default' => 'system',
            'options' => ['system', 'light', 'dark'],
        ],


        'empreendimento_value_required' => [
            'type'    => 'bool',
            'default' => true,
        ],


        'pipeline_strict_mode' => [
            'type'    => 'bool',
            'default' => false,
        ],


        'image_watermark_enabled' => [
            'type'    => 'bool',
            'default' => false,
        ],
        'image_watermark_logo_path' => [
            'type'    => 'string',
            'default' => null,
        ],
        'image_watermark_position' => [
            'type'    => 'enum',
            'default' => 'bottom-right',
            'options' => ['bottom-right', 'bottom-left', 'top-right', 'top-left', 'center', 'tile'],
        ],
        'image_watermark_opacity' => [
            'type'    => 'int',
            'default' => 50,
            'min'     => 5,
            'max'     => 100,
        ],
        'image_watermark_size_pct' => [
            'type'    => 'int',
            'default' => 20,
            'min'     => 5,
            'max'     => 60,
        ],
    ];

    public function index()
    {
        $out = [];
        foreach (self::ALLOWED_KEYS as $key => $meta) {
            $out[$key] = Setting::get($key, $meta['default']);
        }
        return response()->json($out);
    }

    public function show(string $key)
    {
        if (!isset(self::ALLOWED_KEYS[$key])) {
            abort(404, 'Configuração desconhecida.');
        }
        $meta  = self::ALLOWED_KEYS[$key];
        $value = Setting::get($key, $meta['default']);
        return response()->json(['key' => $key, 'value' => $value]);
    }

    public function update(Request $request, string $key)
    {
        $this->ensureAdmin();

        if (!isset(self::ALLOWED_KEYS[$key])) {
            abort(404, 'Configuração desconhecida.');
        }

        $meta = self::ALLOWED_KEYS[$key];
        $raw  = $request->input('value');

        $value = $this->coerce($raw, $meta['type'], $meta);

        $allowNull = in_array($meta['type'], ['int_or_null', 'mixed'], true);
        if ($value === null && $raw !== null && !$allowNull) {
            return response()->json([
                'message' => "Valor inválido pra '{$key}' (esperado {$meta['type']}).",
            ], 422);
        }

        if (in_array($meta['type'], ['int', 'int_or_null'], true) && $value !== null) {
            if (isset($meta['min']) && $value < $meta['min']) {
                return response()->json([
                    'message' => "Valor mínimo pra '{$key}' é {$meta['min']}.",
                ], 422);
            }
            if (isset($meta['max']) && $value > $meta['max']) {
                return response()->json([
                    'message' => "Valor máximo pra '{$key}' é {$meta['max']}.",
                ], 422);
            }
        }

        if ($meta['type'] === 'enum') {
            if (!in_array($value, $meta['options'], true)) {
                return response()->json([
                    'message' => "Valor inválido. Use: " . implode(', ', $meta['options']) . '.',
                ], 422);
            }
        }

        Setting::set($key, $value);

        return response()->json(['key' => $key, 'value' => $value]);
    }

    private function coerce(mixed $raw, string $type, array $meta = []): mixed
    {
        return match ($type) {
            'bool'   => is_bool($raw) ? $raw
                        : (in_array($raw, [1, '1', 'true', 'on', 'yes'], true) ? true
                        : (in_array($raw, [0, '0', 'false', 'off', 'no', '', null], true) ? false
                        : null)),
            'int'    => is_numeric($raw) ? (int) $raw : null,

            'int_or_null' => ($raw === null || $raw === '' || $raw === 'null')
                ? null
                : (is_numeric($raw) ? (int) $raw : null),

            'int_array' => $this->coerceIntArray($raw),
            'string' => is_string($raw) ? $raw : null,
            'enum'   => is_string($raw) ? $raw : null,
            default  => $raw,
        };
    }

    private function coerceIntArray(mixed $raw): array
    {
        if ($raw === null || $raw === '') return [];

        if (is_string($raw)) {
            $raw = array_map('trim', explode(',', $raw));
        }

        if (!is_array($raw)) return [];

        $out = [];
        foreach ($raw as $v) {
            if (!is_numeric($v)) continue;
            $i = (int) $v;
            if ($i <= 0) continue;
            $out[$i] = true;
        }
        return array_values(array_map('intval', array_keys($out)));
    }

    private function ensureAdmin(): void
    {
        $u = auth()->user();

        $role = method_exists($u, 'effectiveRole')
            ? $u->effectiveRole()
            : strtolower(trim((string) ($u->role ?? '')));
        if ($role !== 'admin') {
            abort(403, 'Ação restrita ao administrador.');
        }
    }
}
