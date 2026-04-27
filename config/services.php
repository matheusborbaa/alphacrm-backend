<?php

return [

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

    'hostinger' => [
        'api_base' => env('HOSTINGER_API_BASE', 'https://developers.hostinger.com'),
        'api_key'  => env('HOSTINGER_API_KEY'),
        'vps_id'   => env('HOSTINGER_VPS_ID'),
    ],

    'alphacrm_disk' => [
        'quota_gb' => (int) env('ALPHACRM_DISK_QUOTA_GB', 30),
    ],


    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),

        'redirect_uri'  => env('GOOGLE_REDIRECT_URI'),

        'frontend_callback' => env('GOOGLE_FRONTEND_CALLBACK', 'https://app.alphacrm.com.br/perfil.php'),
        'timezone'      => env('GOOGLE_CALENDAR_TIMEZONE', 'America/Sao_Paulo'),
    ],

];
