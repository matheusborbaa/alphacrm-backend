<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de tarefa configurável pelo admin.
 * Aponta pra mesma tabela `task_kind_colors` (mantida pra preservar dados
 * de cores já cadastradas), mas com colunas estendidas pra label/order/active/icon.
 */
class TaskKind extends Model
{
    protected $table      = 'task_kind_colors';
    protected $primaryKey = 'kind';
    public    $incrementing = false;
    protected $keyType    = 'string';

    protected $fillable = [
        'kind',
        'label',
        'color_hex',
        'icon',
        'order',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'order'  => 'integer',
    ];

    /**
     * Lista todos os kinds ATIVOS, ordenados (cache 10 min).
     * Usado no frontend pra montar selects e pra validação backend.
     */
    public static function activeList(): array
    {
        return \Cache::remember('task_kinds_active_list', 600, function () {
            return self::query()
                ->where('active', true)
                ->orderBy('order')
                ->orderBy('label')
                ->get(['kind', 'label', 'color_hex', 'icon', 'order'])
                ->toArray();
        });
    }

    /**
     * Slugs ativos — usado pra validação (Rule::in).
     */
    public static function activeSlugs(): array
    {
        return \Cache::remember('task_kinds_active_slugs', 600, function () {
            return self::query()
                ->where('active', true)
                ->pluck('kind')
                ->toArray();
        });
    }

    public static function invalidateCache(): void
    {
        \Cache::forget('task_kinds_active_list');
        \Cache::forget('task_kinds_active_slugs');
        \Cache::forget('task_kind_colors_map');
    }
}
