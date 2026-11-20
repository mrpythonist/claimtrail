<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy
    |--------------------------------------------------------------------------
    |
    | The API serves JSON, not pages, so the policy is deliberately
    | strict: nothing may load, and the app may not be framed. The
    | Next.js frontend sets its own CSP for the pages it serves.
    |
    */

    'csp' => env(
        'SECURITY_CSP',
        "default-src 'none'; frame-ancestors 'none'; base-uri 'none'"
    ),

    /*
    |--------------------------------------------------------------------------
    | Strict-Transport-Security
    |--------------------------------------------------------------------------
    |
    | HSTS is sent when the request arrived over HTTPS, and whenever
    | it is enabled here. It defaults on in production and off
    | elsewhere, so local development over plain HTTP is unaffected.
    | Set SECURITY_HSTS=false to turn it off in production; do not
    | do that while the app is served over HTTPS.
    |
    */

    'hsts' => [
        // env() is used here, not app()->environment(): config
        // files load before the application can answer that.
        'enabled' => env('SECURITY_HSTS', env('APP_ENV') === 'production'),

        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),

        'include_subdomains' => true,
    ],

];
