<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dominios base del monolito Stamless
    |--------------------------------------------------------------------------
    |
    | Stamless es un único monolito Laravel que responde en varios
    | dominios/subdominios según el rol de cada uno — no son apps separadas,
    | es el mismo codebase sirviendo distintos hosts:
    |
    |   - APP_URL           → landing / sitio de onboarding (ya es nativo de
    |                         Laravel, `config('app.url')`; no se duplica acá).
    |   - APP_URL_API        → API pública v1 (`api.stamless.host` local /
    |                         `api.stamless.com` producción).
    |   - APP_URL_STUDIO     → panel Filament de administración de tenants
    |                         (`studio.stamless.host` local — mismo dominio que
    |                         `PanelCmsProvider::panel()->domain(...)`).
    |   - APP_URL_PLATFORM   → panel de plataforma/super-admin
    |                         (`platform.stamless.host` local /
    |                         `platform.stamless.com` producción).
    |   - APP_URL_GRAPHQL    → host reservado para GraphQL (futuro). En el MVP
    |                         devuelve 404 vacío — NO instalar Lighthouse ni
    |                         exponer playground público.
    |
    | Centralizar esto acá evita hardcodear dominios en providers o páginas
    | custom. Ningún dominio concreto puede aparecer en código ejecutable
    | fuera del .env (regla vinculante — ver AGENTS.md).
    |
    | Fallback: si una variable no está seteada, caen a `APP_URL` para no
    | romper un `.env` local que todavía no las tenga.
    |
    | Nota histórica: este archivo reemplaza `config/genesis.php` (ADR-026).
    | El schema PostgreSQL y el DB name siguen siendo `genesis`/`genesis_cms`
    | — codename técnico interno, no marca pública.
    |
    */

    'urls' => [
        'api' => env('APP_URL_API', env('APP_URL', 'http://localhost')),
        'studio' => env('APP_URL_STUDIO', env('APP_URL', 'http://localhost')),
        'platform' => env('APP_URL_PLATFORM', env('APP_URL', 'http://localhost')),
        'graphql' => env('APP_URL_GRAPHQL', env('APP_URL', 'http://localhost')),
    ],

];
