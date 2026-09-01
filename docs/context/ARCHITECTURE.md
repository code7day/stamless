# Genesis CMS — Arquitectura

> Documento de referencia de arquitectura. Actualizar cuando cambien decisiones estructurales.
> Última actualización: 2026-08-20 (auditoría y consolidación del ecosistema de dominios/rutas/seguridad/excepciones — ver §4, ADR-012/016/018/020/023/024/025)

---

## Tabla de dominios (Local vs Producción)

| Rol / Dominio | Entorno Local | Entorno Producción |
|---|---|---|
| Landing / Marca | `stamless.host` | `stamless.com` |
| Studio (Tenant CMS) | `studio.stamless.host` | `studio.stamless.com` |
| API Pública | `api.stamless.host` | `api.stamless.com` |
| Platform (Super-admin) | `platform.stamless.host` | `platform.stamless.com` |
| GraphQL (reservado) | `graphql.stamless.host` | `graphql.stamless.com` |

---

## 1. Visión general

**Stamless** es una plataforma CMS **headless + híbrida**, multi-tenant y multi-domain, orientada a freelancers y agencias de Latinoamérica.

Objetivo de producto:
- Entregar sitios y contenidos de forma rápida, con un panel de administración profesional (Filament).
- Permitir frontends desacoplados (Headless) o monólito tradicional (híbrido) según el cliente.
- Escalar a múltiples tenants con aislamiento lógico en una sola base de datos.
- Monetizar con modelo freemium + módulos verticales (seguros, legal, inmobiliaria, etc.).

**Cliente 0 (MVP):** consultora uruguaya de seguros y temas jurídicos. Entrega en modo **Headless**, plan **Free Forever**.

---

## 2. Stack tecnológico

| Capa | Tecnología | Notas |
|------|------------|--------|
| Backend / API | Laravel 13 (ver ADR-007) | PHP 8.3 compatible / 8.4 en servidor |
| Admin panel | Filament 5 (ver ADR-007) | Dos paneles: `PanelCmsProvider` (Studio, con tenancy) y `PanelPlatformProvider` (Platform, sin tenancy) |
| Base de datos | PostgreSQL | Single DB + `tenant_id` |
| Auth | Laravel Sanctum (personal access tokens, guard `sanctum`) — ver ADR-018 | Implementado y en producción para la API; Passport descartado explícitamente (ver ADR-018, "Alternativas consideradas") |
| Assets / media | Cloudflare R2 | S3-compatible, bajo costo |
| API | REST (MVP) → GraphQL (fase 2) | Versionado `/v1` |
| Frontend Cliente 0 | Astro o Next.js (static export) | Hosting compartido del cliente |
| Colas / jobs | Laravel Queue (Redis o DB) | Publicación, media processing |
| Caché | Redis (recomendado) / file | Config por tenant cuando aplique |

---

## 3. Arquitectura multi-tenant

### Enfoque: Single Database + `tenant_id`

```
┌─────────────────────────────────────────────────────────┐
│                    Genesis Platform                     │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐     │
│  │  Tenant A   │  │  Tenant B   │  │  Tenant C   │     │
│  │ (Cliente 0) │  │             │  │             │     │
│  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘     │
│         │                │                │            │
│         └────────────────┼────────────────┘            │
│                          ▼                             │
│              PostgreSQL (single DB)                    │
│         todas las tablas con tenant_id                 │
└─────────────────────────────────────────────────────────┘
```

**Principios:**
- Toda entidad de negocio pertenece a un `tenant` (excepto tablas globales de plataforma).
- Scoping automático por `tenant_id` (global scopes, middleware, policies).
- Un tenant puede tener **múltiples dominios** (primary + aliases).
- Resolución de tenant por: dominio, header, o path (prioridad a definir en implementación).
- Aislamiento **lógico**, no físico. No se usan schemas por tenant en el MVP.

**Tablas globales (sin tenant_id o con flag de plataforma):**
- `tenants`, `domains`, `plans`, `modules`, `platform_users` (super-admin).

**Tablas tenant-scoped (con `tenant_id`):**
- `users`, `pages`, `posts`, `blocks`, `media`, `settings`, `menus`, etc.

---

## 4. Ecosistema de Dominios, Ruteo y Seguridad

Genesisly es un monolito Laravel 13 único que responde dinámicamente según el Host de la petición entrante. El sistema de subdominios, su estructura de ruteo, sus políticas de seguridad y la gestión de excepciones operan bajo las siguientes directrices. Decisiones fuente (no reabrir sin ADR nuevo): ADR-012 (subdominios/paneles), ADR-020 (dominios desde `.env`), ADR-022 (marca/hosts), ADR-023/024 (excepciones/envelope), ADR-025 (sin prefijo `/api`).

### Mapeo de Dominios (Local vs Producción)

| Rol / Dominio | Entorno Local | Entorno Producción |
|---|---|---|
| Landing / Sitio de Ventas | `stamless.host` | `stamless.com` |
| Studio (CMS Tenant — Filament) | `studio.stamless.host` | `studio.stamless.com` |
| API Pública (REST) | `api.stamless.host` | `api.stamless.com` |
| Platform (Super-admin) | `platform.stamless.host` | `platform.stamless.com` |
| GraphQL (reservado, 404 vacío) | `graphql.stamless.host` | `graphql.stamless.com` |

### Reglas y Convenciones de Ruteo

1. **Subdominio de API (`api.stamless.host`)** — `routes/api.php`, envuelto en `Route::domain(parse_url(config('stamless.urls.api'), PHP_URL_HOST))`:
   - **Cero Prefijos Redundantes** (ADR-025): `apiPrefix: ''` en `bootstrap/app.php` elimina el prefijo `/api`. Las URLs resuelven directamente bajo `/v1/{tenant_slug}/...` (ej: `https://api.stamless.host/v1/cica360/pages`). **No** es `/api/v1/...` — cualquier doc/código que lo muestre así está desactualizado.
   - **Aislamiento por Host**: Todas las rutas de `routes/api.php` solo resuelven si el `Host` de la petición coincide con `config('stamless.urls.api')`. No son accesibles desde ningún otro dominio del monolito.
   - **Auth y tenant** (ADR-016/018): cada grupo de rutas exige `auth:sanctum` + middleware `abilities:content:read` o `abilities:forms:submit`; el tenant se resuelve explícitamente dentro de cada controller (trait `App\Http\Concerns\ResolvesTenant`, no middleware global — ver ADR-016 "Contexto" para el porqué) y valida que el token pertenezca a ese tenant.
   - **Chequeo de Salud (Health Check)**: endpoint público y no autenticado en `routes/web.php` (grupo del dominio de la API), en **`https://api.stamless.host/v1/health`** (sin `/api` — mismo `apiPrefix: ''` aplicado a nivel de convención de URL, aunque esta ruta específica vive en `web.php`, no en `api.php`). Verifica conectividad a PostgreSQL (`DB::connection()->getPdo()`) y retorna `200`/`{"status":"ok"}` o `503`/`{"status":"error"}`.
   - **Silencio en la Raíz**: `response('', 404)` vacío en `/` y en `/graphql/{any?}` de este dominio, para no delatar la existencia del framework ante bots/escáneres.

2. **Dominio Principal (`stamless.host`)** — `routes/web.php`, grupo `Route::domain(parse_url(config('app.url'), PHP_URL_HOST))`:
   - **Landing Minimalista**: Responde a la raíz `/` sirviendo el teaser ultra-minimalista (`view('public.home')`).
   - **Bloqueo Silencioso de Rutas API/GraphQL**: Cualquier petición a `/api/{any?}` o `/graphql/{any?}` en este dominio es capturada y retorna una respuesta `404` vacía, previniendo redirecciones o descargas accidentales.
   - **Redirección de Fallbacks**: Cualquier petición a `/` en un dominio no mapeado explícitamente por ninguno de los grupos anteriores redirige a `config('app.url')` (la landing).
   - **Excepción documentada**: `GET /openapi.v1.yaml` vive en el dominio de **Console** (`studio.stamless.host`), no en la landing ni en la API — sirve el spec OpenAPI crudo sin auth porque es un contrato público, y está ahí porque el link que lo referencia vive dentro de `App\Filament\Pages\ApiDocumentation` (ver `routes/web.php` para el comentario completo).

3. **Regla de oro (vinculante, ver CURRENT_STATE.md "No-hardcoded domains")**: ningún dominio/subdominio concreto puede aparecer en código ejecutable — todo pasa por `config('app.url')` o `config('stamless.urls.*')` (`config/stamless.php`, leído de `.env`: `APP_URL`, `APP_URL_API`, `APP_URL_STUDIO`, `APP_URL_PLATFORM`).

### Seguridad y Excepciones

1. **CORS (Cross-Origin Resource Sharing)** — `config/cors.php`:
   - `'paths' => ['v1/*']` — **tiene que matchear el path real de la API sin `/api`** (desde ADR-025). `HandleCors` de Laravel matchea por path, no por Host, así que este valor tiene que mantenerse sincronizado a mano con cualquier cambio futuro de prefijo/convención de rutas de la API — si alguna vez diverge de lo que realmente sirve `routes/api.php`, CORS deja de aplicarse en silencio, sin error visible (bug real encontrado y corregido en esta auditoría: seguía en `api/*` después de adoptar ADR-025).
   - `allowed_origins`/`allowed_origins_patterns` permiten el host de `config('app.url')` (landing) y cualquier subdominio de ese mismo host — pensado para un frontend headless servido bajo un dominio/subdominio del propio Genesisly o del cliente; contenido público sin cookies (`supports_credentials: false`).
2. **Laravel Sanctum y Estado (Cookies)**:
   - `SANCTUM_STATEFUL_DOMAINS` (`.env`) incluye `stamless.host`, `studio.stamless.host` y `platform.stamless.host` — habilita auth por cookie/sesión para los paneles Filament (Console/Manager). La API pública **no** usa este mecanismo: usa personal access tokens de Sanctum (guard `sanctum`, `Authorization: Bearer {token}`), completamente independiente del estado por cookie — ver ADR-018.
3. **Mapeo de Excepciones de la API** (ADR-023, formalizado en ADR-024):
   - Centralizado en un único closure `$exceptions->render()` de `bootstrap/app.php`, activo solo cuando `$request->getHost() === parse_url(config('stamless.urls.api'), PHP_URL_HOST)` (o `expectsJson()`). Todas las excepciones ahí son interceptadas y devueltas en un JSON estructurado vía `App\Support\Api\ErrorEnvelope::make()` (clase estática — el closure de `bootstrap/app.php` no tiene `$this`, así que no puede usar un trait).
   - Taxonomía de `errors.code` (snake_case, estable, pensada para `switch` en el cliente): `unauthenticated` (401, sin token), `token_invalid` (401, token presente pero inválido/expirado/revocado), `forbidden` (403), `not_found` (404), `validation` (422, con `errors.fields`), `too_many_requests` (429), `server_error` (500, con `errors.detail` solo si `APP_DEBUG=true`).
   - **Regla crítica descubierta durante ADR-024** (no reintroducir el bug): el `message` de 401/403/404/429/500 es **fijo por status, nunca `$e->getMessage()`** de la excepción real. Razón: `Illuminate\Foundation\Exceptions\Handler::prepareException()` (vendor, corre antes que cualquier `$exceptions->render()`) convierte incondicionalmente toda `AuthorizationException` (incluida `Laravel\Sanctum\Exceptions\MissingAbilityException`) en `AccessDeniedHttpException`, perdiendo el tipo original — un `instanceof AuthorizationException` en el callback nunca matchea, y cualquier fallback a `$e->getMessage()` termina filtrando texto interno de la librería (ej. `"Invalid ability provided."` sin traducir) al cliente público de la API. Ver ADR-024, Consecuencias, punto 2, para el detalle completo.
   - `App\Http\Concerns\ResolvesTenant` (usado por todos los controllers de `Api/V1`) nunca revela **cuál** de dos motivos causó un error: tenant inexistente/inactivo → 404 genérico siempre primero; recién después se chequea si el token pertenece a ese tenant → 403 genérico (ver ADR-018, Consecuencias, punto 3).

---

## 5. Modos de entrega: Headless vs Monolítico

| Modo | Descripción | Cuándo usarlo |
|------|-------------|----------------|
| **Headless** | Solo API + panel Filament. Frontend externo consume REST. | Cliente 0, sitios estáticos, JAMstack |
| **Híbrido / Monolítico** | Laravel sirve vistas Blade/Livewire + API opcional. | Clientes que quieren todo en un solo deploy |

```
Headless (MVP Cliente 0):
  [Filament Admin] ──► [Laravel API REST] ──► [Astro/Next static] ──► Shared hosting
                              │
                              ▼
                         [PostgreSQL]
                         [Cloudflare R2]

Híbrido (futuro):
  [Filament Admin] ──► [Laravel] ──► Blade/Livewire + API
                              │
                              ▼
                         [PostgreSQL + R2]
```

El mismo backend soporta ambos modos. El modo se configura por tenant.

---

## 6. Sistema de bloques (Block System)

Contenido modular basado en bloques reutilizables, serializados (JSON) y tipados.

**Concepto:**
- Una **Page** (o Post) tiene un array ordenado de **Blocks**.
- Cada block tiene: `type`, `data` (JSON), `order`, `settings` opcionales.
- El admin (Filament) edita bloques con formularios tipados.
- La API expone bloques ya resueltos (o crudos) para que el frontend los renderice.

**Bloques prioritarios MVP (Cliente 0):**
- `hero`, `rich_text`, `image`, `cta`, `features`, `faq`, `contact_form`, `legal_notice`

**Extensibilidad:**
- Nuevos tipos de bloque se registran en un registry (PHP class + schema).
- Módulos verticales pueden registrar bloques propios (ej. `insurance_quote`, `lawyer_card`).

```
Page
 └── Block[0] type=hero        data={ title, subtitle, image, cta }
 └── Block[1] type=rich_text   data={ html }
 └── Block[2] type=features    data={ items[] }
 └── Block[3] type=cta         data={ label, url }
```

---

## 7. Módulos verticales (futuro)

Paquetes o feature flags que extienden el CMS core para industrias específicas.

| Módulo | Industria | Ejemplos de entidades |
|--------|-----------|------------------------|
| Insurance | Seguros | pólizas, cotizadores, coberturas |
| Legal | Jurídico | áreas de práctica, abogados, casos |
| Real Estate | Inmobiliaria | propiedades, agentes, tours |
| Agency | Agencias | portfolios, clientes, servicios |

- Core CMS siempre disponible en plan Free Forever (limitado).
- Módulos se activan por tenant y plan.
- Cliente 0: core Free Forever; verticales de seguros/legal se evalúan post-MVP.

---

## 8. Infraestructura

| Componente | Decisión | Justificación |
|------------|----------|---------------|
| Hosting app | VPS / Cloud (TBD: Hetzner, DigitalOcean, Railway, Forge) | Control y costo LATAM-friendly |
| DB | PostgreSQL managed o self-hosted | JSONB, robustez, multi-tenant |
| Object storage | Cloudflare R2 | S3 API, egress barato |
| CDN | Cloudflare | DNS + cache + R2 |
| Frontend Cliente 0 | Shared hosting del cliente | Static export (HTML/JS/CSS) |
| CI/CD | GitHub Actions (previsto) | Deploy backend + docs |

**Assets:**
- Upload vía Filament → storage disk `r2`.
- URLs públicas firmadas o públicas según política del tenant.
- Transformaciones de imagen: fase 2 (o Cloudflare Images / on-the-fly).

---

## 9. Capas de la aplicación (propuesta)

```
app/
├── Domain/           # (opcional) lógica de dominio por bounded context
├── Models/           # Eloquent (Tenant, Page, Block, Media, ...)
├── Filament/         # Resources, Pages, Widgets del panel
├── Http/
│   ├── Controllers/Api/
│   ├── Middleware/   # ResolveTenant, EnsureTenantActive
│   └── Resources/    # API Transformers
├── Services/         # BlockRegistry, TenantResolver, MediaService
└── Policies/         # Autorización tenant-aware
```

API REST v1 (implementada — este borrador quedó desactualizado, ver la fuente de verdad real: [`docs/api/v1.md`](../api/v1.md) + [`docs/api/openapi.v1.yaml`](../api/openapi.v1.yaml) + §4 arriba):
- Todas las rutas van bajo `/v1/{tenant_slug}/...` en el dominio `api.stamless.host` (nunca sin tenant en el path — ver ADR-016).
- `GET pages`, `GET pages/{slug}`, `GET posts`, `GET posts/{slug}`, `GET menus/{slug}`, `GET sliders/{slug}`, `GET media/{uuid}`, `POST forms/{slug}/submit`.
- Auth: **siempre** `Authorization: Bearer {token}` (Sanctum, ability `content:read` o `forms:submit` según endpoint) — no hay endpoints públicos sin token (ver ADR-018).

---

## 10. Seguridad y multi-tenancy (checklist)

- [ ] Global scope en todos los modelos tenant-scoped
- [ ] Middleware de resolución de tenant en API y panel
- [ ] Policies que validan pertenencia al tenant actual
- [ ] Tests de aislamiento (tenant A no ve datos de tenant B)
- [ ] Rate limiting por tenant en API pública
- [ ] Secrets y config por entorno (`.env`), nunca en repo

---

## 11. Roadmap arquitectónico (alto nivel)

1. **MVP Headless Cliente 0** — tenants, pages, blocks, media R2, API REST, frontend estático.
2. **Panel multi-tenant maduro** — dominios, settings, roles por tenant.
3. **GraphQL** — capa de consulta flexible para frontends.
4. **Módulos verticales** — insurance/legal como primeros.
5. **Modo monólito** — Blade/Livewire para clientes full-stack.
6. **Marketplace de bloques/módulos** — largo plazo.

---

## Referencias internas

- Estado actual: [`CURRENT_STATE.md`](./CURRENT_STATE.md)
- Decisiones (ADR): [`DECISIONS.md`](./DECISIONS.md)
- Tarea activa: [`TASK.md`](./TASK.md)
- Progreso: [`PROGRESS.md`](./PROGRESS.md)
