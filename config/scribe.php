<?php

use Knuckles\Scribe\Extracting\Strategies;
use Knuckles\Scribe\Config\Defaults;
use Knuckles\Scribe\Config\AuthIn;
use function Knuckles\Scribe\Config\{removeStrategies, configureStrategy};

return [

    'title' => 'AlphaCRM - Documentação',

    'description' => '',

    'intro_text' => <<<INTRO
        Esta documentação tem como principal função auxiliar aos desenvolvedores a sua instalação e usabilidade.

    INTRO,

    'base_url' => config("app.url"),

    'routes' => [
        [
            'match' => [

                'prefixes' => ['api/*'],

                'domains' => ['*'],
            ],

            'include' => [

            ],

            'exclude' => [

            ],
        ],
    ],

    'type' => 'laravel',

    'theme' => 'elements',

    'static' => [

        'output_path' => 'public/docs',
    ],

    'laravel' => [

        'add_routes' => true,

        'docs_url' => '/docs',

        'assets_directory' => null,

    'middleware' => ['docs.auth'],

    ],

    'external' => [
        'html_attributes' => []
    ],

    'try_it_out' => [

        'enabled' => true,

        'base_url' => null,

        'use_csrf' => false,

        'csrf_url' => '/sanctum/csrf-cookie',
    ],

    'auth' => [

        'enabled' => true,

        'default' => true,

        'in' => AuthIn::BEARER->value,

        'name' => 'Authorization',

        'use_value' => env('SCRIBE_AUTH_KEY'),

        'placeholder' => '{YOUR_AUTH_KEY}',

        'extra_info' => 'You can retrieve your token by visiting your dashboard and clicking <b>Generate API token</b>.',
    ],

    'example_languages' => [
        'bash',
        'javascript',
    ],

    'postman' => [
        'enabled' => true,

        'overrides' => [

        ],
    ],

    'openapi' => [
        'enabled' => true,

        'version' => '3.0.3',

        'overrides' => [

        ],

        'generators' => [],
    ],

    'groups' => [

        'default' => 'Endpoints',

        'order' => [
            'Rotas Publicas (SEM autenticacao)',
            'Rotas Autenticadas (exigem token)',
        ],
    ],

    'logo' => false,

    'last_updated' => 'Last updated: {date:F j, Y}',

    'examples' => [

        'faker_seed' => 1234,

        'models_source' => ['factoryCreate', 'factoryMake', 'databaseFirst'],
    ],

    'strategies' => [
        'metadata' => [
            ...Defaults::METADATA_STRATEGIES,

            \App\Docs\Strategies\GroupByAuth::class,
        ],
        'headers' => [
            ...Defaults::HEADERS_STRATEGIES,
            Strategies\StaticData::withSettings(data: [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
        ],
        'urlParameters' => [
            ...Defaults::URL_PARAMETERS_STRATEGIES,
        ],
        'queryParameters' => [
            ...Defaults::QUERY_PARAMETERS_STRATEGIES,
        ],
        'bodyParameters' => [
            ...Defaults::BODY_PARAMETERS_STRATEGIES,
        ],
        'responses' => configureStrategy(
            Defaults::RESPONSES_STRATEGIES,
            Strategies\Responses\ResponseCalls::withSettings(
                only: ['GET *'],

                config: [
                    'app.debug' => false,
                ]
            )
        ),
        'responseFields' => [
            ...Defaults::RESPONSE_FIELDS_STRATEGIES,
        ]
    ],

    'database_connections_to_transact' => [config('database.default')],

    'fractal' => [

        'serializer' => null,
    ],
];
