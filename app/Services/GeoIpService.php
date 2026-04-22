<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolve geolocalização aproximada a partir de um endereço IP.
 *
 * Usa o ip-api.com — gratuito, sem API key, com rate-limit de ~45 req/min
 * por IP de origem. Pra não estourar isso (nem onerar downloads), cacheamos
 * cada resposta por 24h por IP. Segundo o próprio serviço, a localização
 * não muda significativamente nesse intervalo pra usuários típicos.
 *
 * Retorna array com chaves `country, country_code, region, city, isp, lat, lon`
 * ou null se a API falhou / IP é privado / lookup deu status != success.
 *
 * "Best effort": erros de rede nunca devem atrapalhar o fluxo do chamador.
 * Todos os métodos são try/catch-isolados e retornam null no pior caso.
 */
class GeoIpService
{
    /** Timeout curto pra nunca engasgar um HTTP response interno. */
    private const TIMEOUT_SECONDS = 2;

    /** TTL do cache por IP. 24h é suficiente pra um CRM padrão. */
    private const CACHE_TTL_SECONDS = 86400;

    /**
     * Resolve um IP. Retorna null se:
     *  - IP é vazio / inválido / privado (não adianta consultar)
     *  - API externa falhou / retornou status != success
     *
     * A assinatura é static pra caller não precisar instanciar — mas internamente
     * usa Http/Cache, que já são facades globais.
     */
    public static function lookup(?string $ip): ?array
    {
        if (!$ip) return null;
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return null;

        // IPs privados / loopback não são resolvíveis externamente.
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
                // Não propaga; só loga pra debug. Geolocalização falhando não
                // pode quebrar o download do doc.
                Log::debug('GeoIpService lookup failed: ' . $e->getMessage(), ['ip' => $ip]);
                return null;
            }
        });
    }
}
