# Gemini — Genesis CMS

Instrucciones para **Gemini CLI**, Google AI Studio agents y otros agentes Gemini en este repositorio.

## Fuente de verdad

El estado y las decisiones viven en `docs/context/`. Léelos **antes** de proponer o escribir código:

| Prioridad | Archivo | Uso |
|-----------|---------|-----|
| 1 | [`docs/context/CURRENT_STATE.md`](docs/context/CURRENT_STATE.md) | Estado real |
| 2 | [`docs/context/TASK.md`](docs/context/TASK.md) | Tarea y aceptación |
| 3 | [`docs/context/ARCHITECTURE.md`](docs/context/ARCHITECTURE.md) | Arquitectura |
| 4 | [`docs/context/DECISIONS.md`](docs/context/DECISIONS.md) | ADRs |
| 5 | [`docs/context/PROGRESS.md`](docs/context/PROGRESS.md) | Avances |
| — | [`AGENTS.md`](AGENTS.md) | Protocolo multi-agente |

## Constraints del proyecto (no negociar sin ADR)

- **Stack:** Laravel 11/12 + Filament + PostgreSQL
- **Tenancy:** una sola base de datos + columna `tenant_id`
- **Media:** Cloudflare R2 (S3-compatible)
- **MVP Cliente 0:** Headless + plan Free Forever
- **API MVP:** REST (`/api/v1`); GraphQL más adelante
- **Frontend Cliente 0:** Astro o Next.js con **static export** (shared hosting)

## Protocolo de trabajo

1. Un agente = un owner de la tarea en `TASK.md`.
2. Trabajar solo la subtarea prioritaria (P0 primero).
3. Al finalizar sesión:
   - Actualizar `CURRENT_STATE.md`
   - Actualizar `TASK.md` (checks, owner)
   - Añadir entrada en `PROGRESS.md`
4. Si se toma una decisión nueva → ADR en `DECISIONS.md`.
5. No expandir alcance: sin billing, sin monólito Blade, sin verticales completas en el MVP.

## Qué optimizar

- Claridad multi-tenant (scopes, middleware, tests de aislamiento)
- API consumible por frontend estático
- Docs de contexto siempre sincronizados con la realidad del repo

## Producto en una línea

Genesis CMS es un CMS headless/híbrido multi-tenant para freelancers y agencias de Latinoamérica; el primer hito es el Cliente 0 en modo Headless Free Forever.
