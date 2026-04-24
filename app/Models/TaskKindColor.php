<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sprint 3.6a — Cores configuráveis por tipo de tarefa.
 *
 * A PK é `kind` (string, igual ao enum do Appointment::KINDS).
 * Cada linha guarda um `color_hex` no formato `#RRGGBB`.
 *
 * Tabela pequena (9 linhas max) — cache em Laravel::remember() por 10min
 * é mais que suficiente. O controller invalida o cache em cada update.
 */
class TaskKindColor extends Model
{
    protected $table      = 'task_kind_colors';
    protected $primaryKey = 'kind';
    public $incrementing  = false;
    protected $keyType    = 'string';

    protected $fillable = [
        'kind',
        'color_hex',
    ];

    /**
     * Retorna um mapa [kind => color_hex] pronto pro frontend consumir.
     * Cacheado por 10min — invalidado quando o admin salva via PUT.
     */
    public static function asMap(): array
    {
        return \Cache::remember('task_kind_colors_map', 600, function () {
            return self::pluck('color_hex', 'kind')->toArray();
        });
    }

    /** Limpa o cache. Chamado após cada update. */
    public static function invalidateCache(): void
    {
        \Cache::forget('task_kind_colors_map');
    }
}
