<?php

return [
    'server_url' => env('LICENSING_SERVER_URL'),

    'api_key' => env('LICENSING_API_KEY'),

    'api_secret' => env('LICENSING_API_SECRET'),

    'license_key' => env('LICENSING_KEY'),

    'app_name' => env('LICENSING_APP_NAME', env('APP_NAME')),

    'environment' => env('LICENSING_ENV', env('APP_ENV')),

    'cache' => [
        'store' => env('LICENSING_CACHE_STORE', env('CACHE_STORE', 'file')),
        'ttl_seconds' => env('LICENSING_CACHE_TTL', 3600),
    ],

    'grace_days' => env('LICENSING_GRACE_DAYS', 7),

    'timeout' => env('LICENSING_TIMEOUT', 10),

    'route_prefix' => 'licensing',

    'excluded_routes' => [
        'login',
        'register',
        'password/*',
        'licensing/*',
        'email/*',
        'two-factor*',
        'user/*',
        'up',
        '_ignition/*',
        'api/*',
        'sanctum/*',
        'broadcasting/*',
        'livewire/*',
        'telescope/*',
        'horizon/*',
    ],

    'admin_contact' => env('LICENSING_ADMIN_CONTACT', 'admin@company.com'),

    'dev_bypass' => env('LICENSING_DEV_BYPASS', false),
];
