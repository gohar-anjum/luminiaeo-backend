<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
    | When supports_credentials is true, allowed_origins cannot be '*'.
    | Browsers require an explicit origin when credentials are sent.
    | Use CORS_ALLOWED_ORIGINS (comma-separated) or FRONTEND_URL.
    */
    'allowed_origins' => array_filter(array_map('trim', explode(
        ',',
        env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:5173'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
