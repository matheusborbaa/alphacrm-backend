<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'description'];

    protected $casts = [
        'value' => 'json',
    ];

    private const CACHE_PREFIX = 'settings:';
    private const CACHE_TTL    = 300;

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(self::CACHE_PREFIX . $key, self::CACHE_TTL, function () use ($key, $default) {
            $row = static::where('key', $key)->first();
            return $row ? $row->value : $default;
        });
    }

    public static function set(string $key, mixed $value, ?string $description = null): self
    {
        $attrs = ['value' => $value];
        if ($description !== null) $attrs['description'] = $description;

        $row = static::updateOrCreate(['key' => $key], $attrs);

        Cache::forget(self::CACHE_PREFIX . $key);

        return $row;
    }

    public static function forget(string $key): int
    {
        Cache::forget(self::CACHE_PREFIX . $key);
        return static::where('key', $key)->delete();
    }
}
