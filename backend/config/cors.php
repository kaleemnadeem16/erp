<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Sanctum's cookie mode needs credentialed requests, and browsers reject
    // Access-Control-Allow-Origin: * combined with credentials — so this can't be a
    // wildcard. See docs/decisions/0005-sanctum-authentication.md.
    'allowed_origins' => array_filter(explode(',', env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:5176,http://127.0.0.1:5176'
    ))),

    // Exact-match `allowed_origins` above can't express "any device on my LAN",
    // so a phone testing this dev stack at http://<lan-ip>:5176 needs a regex
    // pattern instead. Unset in a real deployment — the fixed origin list above
    // is enough there, and this pattern is scoped to the dev SPA port anyway.
    'allowed_origins_patterns' => array_filter([env('CORS_ALLOWED_ORIGIN_PATTERNS')]),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
