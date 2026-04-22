<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Configuração global do sistema (key/value JSON).
 *
 * Uso:
 *   Setting::get('watermark_enabled', true)   // com default
 *   Setting::set('watermark_enabled', false)  // grava (upsert)
 *
 * Cache: leituras ficam 5min em cache por chave — grava invalida.
 * Em produção evita N queries por request em páginas que leem várias
 * flags (watermark, sla_enabled, etc no futuro).
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value', 'description'];

    /**
     * value é JSON no banco — o cast 'json' já decodifica na leitura
     * e serializa na escrita, então pode-se guardar bool, int, array, etc.
     */
    protected $casts = [
        'value' => 'json',
    ];

    private const CACHE_PREFIX = 'settings:';
    private const CACHE_TTL    = 300; // 5min

    /**
     * Lê uma configuração. Se não existir, retorna $default.
     * Resultado é memoizado em cache por 5min.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(self::CACHE_PREFIX . $key, self::CACHE_TTL, function () use ($key, $default) {
            $row = static::where('key', $key)->first();
            return $row ? $row->value : $default;
        });
    }

    /**
     * Grava (upsert) uma configuração e invalida o cache daquela chave.
     * $description é opcional — só usado ao criar a row pela 1ª vez.
     */
    public static function set(string $key, mixed $value, ?string $description = null): self
    {
        $attrs = ['value' => $value];
        if ($description !== null) $attrs['description'] = $description;

        $row = static::updateOrCreate(['key' => $key], $attrs);

        Cache::forget(self::CACHE_PREFIX . $key);

        return $row;
    }

    /**
     * Remove a chave (e do cache). Retorna o nº de rows afetadas.
     */
    public static function forget(string $key): int
    {
        Cache::forget(self::CACHE_PREFIX . $key);
        return static::where('key', $key)->delete();
    }
}
