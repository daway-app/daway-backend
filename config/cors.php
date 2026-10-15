<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Exact allowed origins — CORS_ALLOWED_ORIGINS, comma-separated
    |--------------------------------------------------------------------------
    |
    | 🔴 A SINGLE entry here is a trap, and it cost us a day of "login does
    | nothing". When exactly ONE origin is configured and there are no patterns,
    | fruitcake/php-cors takes the "single origin" branch in
    | CorsService::configureAllowedOrigin() and echoes that origin back on EVERY
    | response — it never looks at who actually asked. A browser then compares
    | the value it received against the origin the page is really served from,
    | sees a mismatch, and blocks the response.
    |
    | The result is maddening: the API answers 200 with a valid token in the
    | body, the network tab shows a successful request, and the UI silently does
    | nothing. It only works when the page happens to sit on that one origin —
    | so every Vercel preview / branch URL and every custom domain fails, while
    | the production URL works fine.
    |
    | `*` inside an entry IS supported (it is converted to a wildcard pattern),
    | e.g. `https://daway-frontend*.vercel.app` covers the production domain and
    | every preview alias. Prefer adding patterns below over relying on that.
    |
    */
    'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))),

    /*
    |--------------------------------------------------------------------------
    | Allowed origin patterns — CORS_ALLOWED_ORIGIN_PATTERNS + built-in default
    |--------------------------------------------------------------------------
    |
    | PCRE patterns with delimiters, comma-separated.
    |
    | The default below always allows this project's own Vercel deployments.
    | It is what makes a preview deployment usable at all: without at least one
    | pattern, only the single origin in CORS_ALLOWED_ORIGINS is accepted (see
    | the note above), and every preview URL is rejected by the browser.
    |
    | Two things this buys us:
    |   1. Vercel preview/branch aliases (daway-frontend-git-develop-*.vercel.app
    |      and daway-frontend-<hash>-*.vercel.app) are accepted automatically,
    |      so a preview deployment is testable without touching this file.
    |   2. A non-empty pattern list switches the CORS layer out of "single
    |      origin" mode, so the response echoes the caller's own origin instead
    |      of a fixed one. Production keeps working either way.
    |
    | Add more via the env var (do not edit the array below for deployment
    | specifics — the env var is what a host like Render can change).
    |
    */
    'allowed_origins_patterns' => array_values(array_filter(array_merge(
        ['~^https://daway-frontend[a-z0-9-]*\.vercel\.app$~'],
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', ''))),
    ))),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
