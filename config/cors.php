<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| Cookie-based Sanctum auth requires credentialed cross-origin requests, and
| the spec forbids pairing credentials with a wildcard origin -- a browser
| rejects `Access-Control-Allow-Origin: *` whenever credentials are sent. The
| allowed origins are therefore an explicit list built from FRONTEND_URL.
|
| In development the Vite dev server proxies /api to Laravel, so the browser
| sees a single origin and these headers are never exercised. They matter when
| the SPA is served from a different host than the API.
|
*/

$frontendUrl = rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/');

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_filter([
        $frontendUrl,
        env('APP_URL'),
    ]))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 60 * 60 * 12,

    // Required so the browser will send and store the session cookie.
    'supports_credentials' => true,

];
