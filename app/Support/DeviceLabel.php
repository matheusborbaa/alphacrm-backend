<?php

namespace App\Support;

/**
 * Sprint 3.0a — helper pra extrair um label amigável do User-Agent
 * (ex.: "Chrome / Windows 11", "Safari / iPhone"). Usado nos tokens
 * do Sanctum pra mostrar dispositivo na tela "Meus dispositivos".
 *
 * A lógica é um parser simples por regex — não queremos importar uma
 * lib pesada de UA parsing só pra isso. Se o UA for desconhecido,
 * devolve "Navegador" ou "Desconhecido" como fallback.
 */
class DeviceLabel
{
    /**
     * Retorna algo como "Chrome / Windows 11" a partir do User-Agent.
     */
    public static function fromUserAgent(?string $ua): string
    {
        $ua = trim((string) $ua);
        if ($ua === '') {
            return 'Desconhecido';
        }

        $browser = self::detectBrowser($ua);
        $os      = self::detectOs($ua);

        if ($browser && $os) return "{$browser} / {$os}";
        if ($browser)        return $browser;
        if ($os)             return $os;

        return 'Navegador';
    }

    private static function detectBrowser(string $ua): ?string
    {
        // Ordem importa: Edge e Opera contêm "Chrome" no UA, então checam antes.
        if (preg_match('/Edg\//i', $ua))      return 'Edge';
        if (preg_match('/OPR\//i', $ua))      return 'Opera';
        if (preg_match('/Firefox/i', $ua))    return 'Firefox';
        if (preg_match('/Chrome/i', $ua))     return 'Chrome';
        if (preg_match('/Safari/i', $ua))     return 'Safari';
        if (preg_match('/MSIE|Trident/i', $ua)) return 'Internet Explorer';

        return null;
    }

    private static function detectOs(string $ua): ?string
    {
        // Mobile primeiro pra não confundir "iPhone" com "Mac".
        if (preg_match('/iPhone/i', $ua))        return 'iPhone';
        if (preg_match('/iPad/i', $ua))          return 'iPad';
        if (preg_match('/Android/i', $ua))       return 'Android';
        if (preg_match('/Windows NT 10\.0/i', $ua)) return 'Windows 10/11';
        if (preg_match('/Windows NT 6\.[123]/i', $ua)) return 'Windows';
        if (preg_match('/Windows/i', $ua))       return 'Windows';
        if (preg_match('/Mac OS X/i', $ua))      return 'macOS';
        if (preg_match('/Linux/i', $ua))         return 'Linux';

        return null;
    }
}
