<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | La API pública (`v1/{tenant_slug}/...`) sirve contenido de
    | lectura ya publicado para el frontend headless del Cliente 0 (y de
    | futuros tenants), sin cookies ni sesión — permisivo por origen es
    | seguro acá. `supports_credentials` se mantiene en `false` a
    | propósito: si en el futuro se agrega auth por cookie, este archivo
    | debe revisarse (ver ADR-016).
    |
    | `paths` matchea por PATH únicamente (Illuminate\Http\Middleware\
    | HandleCors ignora el Host) — desde ADR-025 las rutas de la API ya
    | no llevan el prefijo `/api` (viven en `api.stamless.host/v1/...`,
    | ver routes/api.php), así que el patrón tiene que ser `v1/*`, no
    | `api/*`. Con `api/*` este archivo dejaba de aplicar CORS a la API
    | en silencio (sin error visible) desde que se adoptó ADR-025.
    |
    */

    'paths' => ['v1/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter([
        config('app.url') ? 'http://'.parse_url(config('app.url'), PHP_URL_HOST) : null,
        config('app.url') ? 'https://'.parse_url(config('app.url'), PHP_URL_HOST) : null,
    ]),

    'allowed_origins_patterns' => array_filter([
        // Permite cualquier subdominio del dominio principal (APP_URL)
        parse_url(config('app.url'), PHP_URL_HOST)
            ? '#^https?://([^/]+\.)?'.preg_quote(parse_url(config('app.url'), PHP_URL_HOST), '#').'$#'
            : null,
    ]),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
