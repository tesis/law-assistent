<?php

return [
    'base_url' => env('PISRS_BASE_URL', 'https://pisrs.si/extapi'),
    'key' => env('PISRS_API_TOKEN'),
    'api_key' => env('PISRS_API_KEY'),
    'scheme' => env('PISRS_AUTH_SCHEME', 'x-api-key'), // 'x-api-key' | 'bearer'
    'timeout' => 10,
    'retries' => 2,
];
