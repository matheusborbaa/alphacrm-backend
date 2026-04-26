<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeoIpService
{

    private const TIMEOUT_SECONDS = 2;

    private const CACHE_TTL_SECONDS = 86400;

    public static function lookup(?string $ip): ?array
    {
        if (!$ip) return null;
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return null;

        if (!filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        )) {
            return null;
        }

        $cacheKey = 'geoip:' . $ip;

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($ip) {
            try {
                $response = Http::timeout(self::TIMEOUT_SECONDS)
                    ->get("http://ip-api.com/json/{$ip}", [
                        'fields' => 'status,country,countryCode,regionName,city,isp,lat,lon',
                        'lang'   => 'pt-BR',
                    ]);

                if (!$response->successful()) return null;

                $data = $response->json();
                if (!is_array($data) || ($data['status'] ?? '') !== 'success') return null;

                return [
                    'country'      => $data['country']     ?? null,
                    'country_code' => $data['countryCode'] ?? null,
                    'region'       => $data['regionName']  ?? null,
                    'city'         => $data['city']        ?? null,
                    'isp'          => $data['isp']         ?? null,
                    'lat'          => isset($data['lat']) ? (float) $data['lat'] : null,
                    'lon'          => isset($data['lon']) ? (float) $data['lon'] : null,
                ];
            } catch (\Throwable $e) {

                Log::debug('GeoIpService lookup failed: ' . $e->getMessage(), ['ip' => $ip]);
                return null;
            }
        });
    }
}
