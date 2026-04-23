<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'whm' => [
    'url' => env('WHM_URL'),
    'token' => env('WHM_TOKEN'),
    'user' => env('WHM_USER'),
],

    // Hostinger API — usado pela aba Configurações → Sistema pra exibir
    // status/RAM/disco/CPU do VPS onde o CRM está hospedado. Docs:
    // https://developers.hostinger.com. O token é gerado no painel
    // Hostinger (Conta → API), é um Bearer com escopo de VPS ou global.
    // HOSTINGER_VPS_ID é o numérico de cada VM, visível na URL do painel
    // ao abrir o VPS.
    'hostinger' => [
        'api_base' => env('HOSTINGER_API_BASE', 'https://developers.hostinger.com'),
        'api_key'  => env('HOSTINGER_API_KEY'),
        'vps_id'   => env('HOSTINGER_VPS_ID'),
    ],

    // Monitoramento de disco da APLICAÇÃO (aba Sistema + alertas).
    // O VPS é compartilhado com outros sistemas; em vez de expor o disco
    // inteiro, reservamos uma quota fictícia pro AlphaCRM e mostramos o
    // consumo relativo a ela. `monitor_path` vazio = usa base_path() do
    // Laravel (= raiz da pasta alphacrm/). O cálculo usa `du -sb` com
    // fallback PHP e cache pra não repetir a soma toda request.
    'alphacrm_disk' => [
        'monitor_path' => env('ALPHACRM_DISK_MONITOR_PATH'),
        'quota_gb'     => (int) env('ALPHACRM_DISK_QUOTA_GB', 30),
        'cache_ttl'    => (int) env('ALPHACRM_DISK_CACHE_TTL', 600),
    ],

];
