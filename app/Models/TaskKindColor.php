<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public static function asMap(): array
    {
        return \Cache::remember('task_kind_colors_map', 600, function () {
            return self::pluck('color_hex', 'kind')->toArray();
        });
    }

    public static function invalidateCache(): void
    {
        \Cache::forget('task_kind_colors_map');
    }
}
