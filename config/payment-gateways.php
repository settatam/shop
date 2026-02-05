<?php

return [
    'square' => [
        'application_id' => env('SQUARE_APPLICATION_ID'),
        'access_token' => env('SQUARE_ACCESS_TOKEN'),
        'environment' => env('SQUARE_ENVIRONMENT', 'sandbox'),
        'webhook_signature_key' => env('SQUARE_WEBHOOK_SIGNATURE_KEY'),
    ],
    'dejavoo' => [
        'base_url' => env('DEJAVOO_BASE_URL', 'https://api.spinpos.net'),
        'api_version' => env('DEJAVOO_API_VERSION', 'v2'),
    ],
    'terminal' => [
        'default_timeout' => env('TERMINAL_CHECKOUT_TIMEOUT', 300),
        'poll_interval' => env('TERMINAL_POLL_INTERVAL', 3),
    ],
];
