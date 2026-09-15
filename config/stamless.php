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

    /*
    |--------------------------------------------------------------------------
    | Seguridad
    |--------------------------------------------------------------------------
    |
    | `strict_origin_check` — 2026-09-12, usado por
    | `App\Http\Middleware\ValidateTokenOrigin` (ADR-059; primera versión de
    | este flag fue para un diseño previo a nivel `Tenant::domains()`, ya
    | reemplazado — el flag en sí se reutilizó tal cual). Cuando es `false`
    | (default fuera de producción), un token `platform=web` con
    | `allowed_origin` seteado igual permite `localhost`/`127.0.0.1`/`[::1]`
    | y la ausencia total de header de origen SIN necesidad de relajar nada
    | más — "permitir al desarrollador que use el api desde cualquier lado"
    | (pedido explícito del Tech Lead). En producción SIEMPRE es `true`, sin
    | excepción.
    |
    | Deliberadamente un config propio (no `app()->environment('production')`
    | leído directo en el controller): así un test puede togglear el
    | comportamiento "estricto" con `config(['stamless.security.
    | strict_origin_check' => true])` sin depender de cómo Laravel detectó
    | el entorno al bootear el proceso de test.
    |
    */
    'security' => [
        'strict_origin_check' => env('APP_ENV') === 'production',
    ],

    /*
    |--------------------------------------------------------------------------
    | Contacto comercial
    |--------------------------------------------------------------------------
    |
    | 2026-09-13: el botón "Mejorar plan" del Escritorio (`PlanStatusWidget`)
    | no dispara ningún flujo de pago real — billing/pasarelas está
    | explícitamente fuera de alcance del MVP (ver `docs/context/TASK.md`).
    | Por ahora es un `mailto:` a este correo, con asunto/cuerpo
    | precompletados (tenant + plan actual) para que el equipo comercial
    | gestione el upgrade a mano. Cuando exista self-serve billing, este
    | botón se reemplaza por un link a un checkout real — no antes.
    |
    */
    'contact' => [
        'sales_email' => env('STAMLESS_SALES_EMAIL', 'hola@stamless.host'),
    ],

];
