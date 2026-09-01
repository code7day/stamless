# Genesis CMS — Tarea actual

> Última actualización: 2026-09-01 (**cadena de bugs de guardado en Studio cerrada y verificada por el Tech Lead** — dos causas raíz confirmadas: `MediaUpload` y `Slider`, ver ADR-037 + adenda; lado producto/API sigue cerrado salvo esta cadena de fixes de Console; único foco de fondo sigue siendo front CICA360)  
> Owner actual: _(libre)_  
> Estado: **Ready — API cerrada de producto. Próxima sesión = frontend CICA360 en shared hosting.**

---

## Tarea activa

**Frontend CICA360 (shared hosting) consumiendo REST v1**

Lado producto (API) **cerrado**: REST v1, auth Sanctum, envelope, seeds CICA360, playground en Console, hosts Genesisly.

Fase inmediata de ingeniería:

> **Sitio estático de CICA360 en el shared hosting del cliente, consumiendo solo estos endpoints con Bearer `content:read` / `forms:submit`.**

```
GET  /v1/cica360/pages
GET  /v1/cica360/pages/{slug}
GET  /v1/cica360/menus/menu-principal
GET  /v1/cica360/sliders/home
POST /v1/cica360/forms/contacto/submit
```

Host API: `config('genesis.urls.api')` (sin prefijo `/api`). GraphQL y marca fina **fuera** hasta que el sitio esté en el aire.

---

## Objetivo del MVP (Cliente 0)

Entregar una plataforma CMS usable en modo **Headless** para una consultora uruguaya de seguros y temas jurídicos, de modo que:

1. Un **tenant** represente al Cliente 0.
2. El equipo admin gestione **páginas, bloques y media** desde Filament.
3. Un **frontend estático** (Astro o Next.js export) consuma la **API REST** y se aloje en shared hosting del cliente.
4. El tenant opere bajo plan **Free Forever** (sin cobro, con límites de core).

---

## Criterios de aceptación del MVP

### Backend / plataforma

- [x] Proyecto Laravel 13 instalado y documentado en `README.md`
- [x] Filament panel accesible (login admin)
- [x] PostgreSQL configurado; migraciones base corriendo
- [x] Modelo multi-tenant: `tenants`, `domains`, `tenant_id` en entidades de negocio
- [x] Aislamiento: un tenant no puede leer/escribir datos de otro (test mínimo)
- [x] CRUD de **Pages** con **Blocks** tipados (al menos: hero, rich_text, image, cta)
- [x] Media upload hacia disk configurable (local en dev; R2 en prod)
- [x] API REST pública versionada (`/api/v1/...`) para pages por slug, menús y settings públicas — ver ADR-016
- [x] CORS y lectura pública de contenido publicado
- [x] Seed o setup del tenant Cliente 0 + usuario admin (tenant CICA360 + owner, ver ADR-014)

### Frontend Cliente 0

- [ ] Decisión documentada: Astro **o** Next.js (static export)
- [ ] Sitio estático que consume la API (home + al menos 2 páginas internas)
- [ ] Build exportable a HTML/CSS/JS para shared hosting
- [ ] Variables de entorno para `API_BASE_URL` / tenant domain

### Producto / ops

- [x] Plan Free Forever reconocido en datos (`PlanSeeder`: límites simples — max_users/max_pages/max_posts/max_storage_mb)
- [x] Docs de contexto actualizados (`CURRENT_STATE`, `PROGRESS`, ADRs si hubo cambios) — cierre API 2026-08-20
- [ ] Instrucciones de setup local en `README.md`

### Fuera de alcance ahora (hasta que el sitio CICA360 esté en el aire)

- GraphQL
- Marca fina / polish visual de Genesisly
- Filament Resource de Contacts, `FriendlyDate` en resto de Console, honeypot/reCAPTCHA
- Módulos verticales completos (insurance/legal como producto)
- Modo monólito Blade
- Billing / pasarelas de pago
- Marketplace de bloques
- Multi-idioma avanzado (salvo necesidad explícita del cliente)

---

## Desglose de subtareas prioritarias

### P0 — Fundación (hacer primero)

| # | Subtarea | Estado |
|---|----------|--------|
| 1 | Scaffold Laravel 11/12 + Git baseline | Done |
| 2 | Configurar PostgreSQL + `.env.example` | Done |
| 3 | Instalar Filament y panel admin | Done |
| 4 | Migraciones `tenants`, `domains`, users tenant-aware | Done |
| 5 | Middleware / resolver de tenant + global scopes | Done |

### P1 — Contenido y API

| # | Subtarea | Estado |
|---|----------|--------|
| 6 | Modelos Page + Block (migraciones + Eloquent) | Done |
| 6b | Modelos Post, Slider/Slide, Menu/MenuItem (migraciones + Eloquent) | Done |
| 7 | Media library (migración + modelo `Media`) + disk R2/local | Done |
| 7b | Plans/PlanFeatures/Subscriptions/PaymentMethods/Invoices/InvoiceItems/Transactions (migraciones + Eloquent) | Done (schema only, sin integración de pasarela de pago) |
| 7c | Modules/PlanModule/TenantModules (migraciones + Eloquent) | Done |
| 7d | FormFieldDefinitions/Forms/FormFields/Contacts/ContactActivities (migraciones + Eloquent, cifrado en Contact) | Done |
| 7e | Dominio y seguridad de Forms + Contacts: `ContactSubmissionService`, `ContactFormSubmitted` (Mail), `ContactResource`, `ContactPolicy`, `DataMasker` | Done — ver ADR-015 |
| 7f | Re-esquematización de Filament Resources Console (Link/Properties schemas, 12 blocks, slide condicionales, destino menú) | Done |
| 7g | Ocultado del idioma (lang_iso) en UI del Console + Corrección de upload disk | Done |
| 7h | Ajustes y optimización de recursos (MediaSelect modal — superseded por 7i, 3 imágenes responsivas, sliders enums, bloque HEADING, SEO/OG segmentado) | Done |
| 7i | `MediaUpload`: subida directa con preview real (imagen/video) reemplaza `MediaSelect` (dropdown+modal) en los 24 campos de media de Console (Slider/Page/Post), sin cambiar la tabla `media` ni el contrato de la API | Done — ver ADR-028 |
| 8 | API REST `/api/v1` (pages, menus, sliders, posts, media, forms/submit) | Done — ver ADR-016 |
| 9 | Seed Cliente 0 (tenant CICA360, dominio, owner, suscripción, módulos, settings) | Done — ver ADR-014 |
| 9c | Seed de contenido mínimo real de CICA360 (5 páginas + bloques, menú principal, form de contacto, CTAs de slides → páginas reales) | Done — ver ADR-017 |
| 9d | Seed de posts de prueba de CICA360 (3 posts publicados) | Done — ver ADR-018 |
| 13 | Seguridad API por tokens (Sanctum): guard, abilities, ownership de tenant | Done — ver ADR-018 |
| 14 | Console: API Tokens (crear/revocar, plaintext una sola vez, masking) | Done — ver ADR-018 |
| 15b | Console: API Playground (custom Filament Page, requests reales) | Done — ver ADR-018 |
| 16b | Documentación API (`docs/api/v1.md`, `docs/api/openapi.v1.yaml`, Console: API Documentation) | Done — ver ADR-018 |
| 17b | Contrato público de responses sin ids internos (links → source_slug/href, hero → slider_slug, properties/content/meta siempre objeto) | Done — ver ADR-018 |
| 17c | Cierre de gaps: ids anidados en content.items[] (features/testimonials/logos/services_grid) + expiración configurable de tokens | Done — ver ADR-019 |
| 17d | Fix de middleware `abilities` faltante en `bootstrap/app.php` (detectado en el primer `php artisan test` real con Sanctum instalado) + fix de test de revocación (`forgetGuards()`) | Done |
| 17e | Rediseño visual de ApiPlayground/ApiDocumentation/ApiTokens (CSS plano sin build step, alineado a los tokens/patrones reales de Filament) | Done |
| 18 | Dominios base del monolito (`api`/`console`/`manager`) centralizados desde `.env` vía `config/genesis.php` | Done — ver ADR-020 |
| 19 | Preferencias de usuario (idioma/zona horaria) + `FriendlyDate` (fechas amigables/abreviadas) aplicado a `ApiTokens` + fix de `last_four`/`expires_at` no visibles | Done — ver ADR-021. **Requiere `php artisan migrate` local (migración nueva de `users`)** |
| 19b | Extender `FriendlyDate` a las demás columnas de fecha de Console (Pages/Posts/Media/Contacts/etc.) | Pending |
| 19c | Menú del avatar: opciones "Preferencias" y "Cambiar contraseña" (`ChangePassword` page + `Panel::userMenuItems()`) | Done — ver ADR-021 |
| 19d | Fix link roto a `openapi.v1.yaml` (404) en API Documentation (ruta pública nueva + reescritura del href) | Done |
| 19e | Limpieza de lenguaje interno en `docs/api/v1.md`/`openapi.v1.yaml` (sin "MVP", sin referencias a ADRs/docs internos — son client-facing, los ve el desarrollador del tenant) + fix de inconsistencia real de `/api` prefix en ejemplos | Done |
| 20 | Fix: 401 de `/api/*` filtraba `route('login')` (422 con "Route [login] not defined.") cuando el request no mandaba `Accept: application/json` — `bootstrap/app.php` (`redirectGuestsTo`) + envelope 401 distingue sin-token/token-inválido + `errors.code` + 3 tests nuevos en `ApiAuthTest` | Done — ver ADR-023. **Requiere `php artisan test` local (no se pudo correr desde el sandbox)** |
| 19f | Landing page pública tipo tarjeta de presentación en `genesisly.host` | Done |
| 21 | Envelope de error API formalizado: taxonomía `errors.code`, `errors.fields` (validación), `ErrorEnvelope` compartido (`ApiResponds`/handler global), `MissingRequiredFieldsException` en forms/submit, tests nuevos (`FormSubmissionApiTest`) | Done — ver ADR-024. **Requiere `php artisan test` local (no se pudo correr desde el sandbox)** |
| 9b | Seeders de catálogo: Plan Free + features, Módulos core + plan_module, Form field definitions del sistema, Super-admin de plataforma | Done — ver ADR-014 |
| 10 | Tests de aislamiento multi-tenant (incluir nuevos módulos) | Done — `ContactSubmissionServiceTest`, `TenantIsolationTest` y `Api/V1/{PageApiTest,ApiAuthTest,PostApiTest}` cubren aislamiento tenant + auth. `php artisan test` confirmado en verde: **26/26** por el humano el 2026-08-17 (tras instalar Sanctum y corregir el alias de middleware `abilities`, ver ADR-020) |
| 11 | Correr `php artisan migrate` contra la BD real y validar el esquema de ADR-013 | Done (confirmado por el humano: 25 migraciones corridas) |
| 11b | Correr `php artisan db:seed` y `php artisan test` contra la BD real y validar seeders (ADR-014) + tests de Contacts (ADR-015) | Done (todos los seeders corrieron y la suite pasa al 100%) |
| 12 | Auth avanzado del DBML final: roles/permissions tenant-aware, Passport, Sanctum, `social_accounts`, migración `users`↔`tenants` a `tenant_user` | Pending — requiere ADR propio antes de tocar `HasTenant`/`User` (ver ADR-013) |
| 22 | Auditoría y consolidación del ecosistema de dominios/rutas/seguridad/excepciones (código vs. docs) + fix de bug real de CORS (`config/cors.php` seguía en `api/*` tras ADR-025) | Done — ver PROGRESS.md 2026-08-20 |

### P2 — Frontend Cliente 0 (**único foco**)

Contrato mínimo a cablear (Bearer `content:read` / `forms:submit`):

| Método | Path |
|--------|------|
| GET | `/v1/cica360/pages` |
| GET | `/v1/cica360/pages/{slug}` |
| GET | `/v1/cica360/menus/menu-principal` |
| GET | `/v1/cica360/sliders/home` |
| POST | `/v1/cica360/forms/contacto/submit` |

> **Actualizado 2026-08-24**: esta sección quedó desactualizada — el frontend se está construyendo en un repo separado (`/Users/edu/Storage/webapps/cica/cica360/`, ver ADR-003 de ese repo) con su propio `docs/context/`, no acá. No duplicar detalle: la fuente de verdad del estado real del front es `cica360/docs/context/CURRENT_STATE.md` y `TASK.md`. Resumen mínimo para no dejar esta tabla mintiendo:

| # | Subtarea | Estado |
|---|----------|--------|
| 11 | Elegir Astro vs Next (documentar ADR) | Done — Astro SSG + islands (ver `cica360/docs/context/DECISIONS.md` ADR-001) |
| 12 | Scaffold frontend + fetch de los 5 endpoints | Done — `npm run build` confirmado en verde por el humano |
| 13 | Templates de páginas/bloques (home + internas) | En progreso — Hero Slider implementado, resto de bloques son stubs pendientes de diseño visual |
| 14 | Static export + guía de deploy shared hosting | Parcial — CI de build listo, falta confirmar credenciales FTP/SFTP del hosting real |

### P3 — Cierre MVP

| # | Subtarea | Estado |
|---|----------|--------|
| 15 | Hardening básico (auth API, rate limit, published-only) | Done — ver ADR-018 (auth Sanctum + abilities; rate limit ya estaba de ADR-016; published-only ya estaba de ADR-016) |
| 16 | Actualizar docs de contexto y README | Done |
| 17 | Checklist de aceptación firmado en `PROGRESS.md` | Pending |

---

## Definition of Done (sesión de trabajo)

Una subtarea solo se marca **Done** si:

1. El código/docs necesarios están en el repo.
2. Se puede verificar localmente (comando o test).
3. `CURRENT_STATE.md` y este `TASK.md` reflejan el cambio.
4. Se añadió una línea en `PROGRESS.md`.

---

## Handoff

Al soltar la tarea:

```
Owner actual: (libre)
Última subtarea completada (2026-09-01): **Cadena de bugs de guardado en Studio cerrada y verificada por el Tech Lead** ("quedó") — dos causas raíz distintas, ambas confirmadas con logs/inspector reales, ver ADR-037 + adenda. (1) `MediaUpload`: `saveRelationshipsUsing` guardaba `content.media_id` en su forma interna cruda (array uuid-keyed) en vez del escalar — fix `unwrapFileUploadState()`. (2) `Slider` (opacidad/brillo/saturación/contraste): mismo patrón de fondo pero en otro componente — `SliderStateCast::get()` hace `floatval($state)`, y como el seeder nunca escribe estas propiedades, el valor crudo es `null` → `floatval(null)` = `0`, pisando el default real (100) sin importar el `->default(100)` configurado en Filament (solo aplica al crear un registro nuevo, no al hidratar datos parciales). El Tech Lead lo detectó con el inspector del navegador: el `<img>` tenía el `src` correcto pero `style="filter: brightness(0%) saturate(0%) ...; opacity: 0"` lo volvía invisible — no era un problema de media, era CSS. Fix: mapa `PageResource::SLIDER_PROPERTY_DEFAULTS` + helper `backfillSliderDefaults()`, aplicado al cargar y al guardar. Ambos fixes verificados juntos con el log real del guardado de confirmación: `media_id` llegó escalar y todas las propiedades de slider en `100`. De paso se detectó y resolvió un problema de entorno: MAMP Pro (el servidor local del Tech Lead) estaba sirviendo código cacheado (opcache) — reiniciar los servidores fue necesario para que los fixes tomaran efecto.
Siguiente subtarea recomendada: ninguna urgente sobre este bug — quedó cerrado y confirmado. Si se agrega un `Slider::make('properties.X')` nuevo en cualquier Resource a futuro, hay que sumarlo a `PageResource::SLIDER_PROPERTY_DEFAULTS` con su default real para no reabrir este patrón. Volver al foco de fondo de la sesión: frontend CICA360 en shared hosting (ver tarea activa arriba). Pendiente de más largo plazo, sin urgencia: el dato legado corrupto original (ids no-escalares, `total:24 scalar:12` de los fixes de `ResolvesPublicLinks`) nunca se identificó a nivel de fila — sigue solo blindado, no limpiado, en la DB real.
Notas de subtareas anteriores (2026-09-01): **Causa raíz confirmada y corregida** (no solo blindada) del reporte crítico "guardo sin cambiar nada y se borra/daña el jsonb" — ver ADR-037. Pedí al Tech Lead reproducir el guardado de nuevo; sus capturas mostraron el widget de `MediaUpload` con la miniatura correcta en el modal (descartando problema de hidratación), pero el sitio público perdió la imagen del bloque `split` Y los logos del bloque `logos` ("Empresas con las que trabajamos" quedó vacío) tras el mismo guardado. Agregué un `Log::info()` temporal en `saveRelationshipsUsing()` — el Tech Lead reprodujo una vez más y el log real confirmó la causa exacta: `content.media_id` llegaba como `{"537a6e80-...-uuid":"4"}` (array, no el escalar `"4"` esperado). Causa: el `$state` que recibe ese closure trae los campos `MediaUpload`/`FileUpload` en su forma interna cruda (keyeada por el UUID interno de Livewire) porque el cast que los limpia normalmente (`FileUploadStateCast::get()`) es parte del camino de deshidratación nativo de Filament (`->relationship()`), que este Builder no usa (usa `saveRelationshipsUsing` manual, necesario para la lógica propia de `tenant_id`/`sort_order`/tipo de `Block`). El array corrupto se guardaba tal cual en el jsonb; `ResolvesPublicLinks` (ya blindado en fixes previos del día) lo descarta por no ser escalar → imagen `null` en el sitio público — pero en Studio el widget la sigue mostrando bien porque esa forma sigue siendo "un archivo válido" para el propio FileUpload. El Tech Lead notó el patrón por primera vez trabajando sobre el bloque de partners/logos, pero el bug es genérico a CUALQUIER guardado con campos `MediaUpload` (split, logos, features, services_grid, heading, hero manual) — como se resguardan todos los bloques de la página juntos, un solo "Guardar cambios" en Home corrompió `split` y `logos` a la vez. Fix: helper nuevo `PageResource::unwrapFileUploadState()` (recursivo, detecta la firma exacta por regex de UUID para no tocar objetos legítimos de una sola propiedad como `properties.background_color`), aplicado a `content`/`properties`/`links` de cada bloque antes de guardar. Como la corrupción existente en DB tiene la misma forma que el fix normaliza, el próximo guardado de una fila ya corrupta se autorepara. Log de diagnóstico retirado tras confirmar la causa. El guard de "estado vacío" de la subtarea anterior se mantiene (protección distinta, sigue siendo válida).
Siguiente subtarea recomendada: el Tech Lead debe (1) correr `php artisan db:seed` para partir de datos limpios en los bloques que quedaron corruptos durante las pruebas de hoy (Split 3/4 de Home, `logos`); (2) abrir Home en Studio, guardar sin cambios, y confirmar en el sitio público que la imagen del split y los logos de partners sobreviven; (3) revisar también el fondo de la sección de testimonios que reportó como afectado — ese campo es `properties.background_color` (ColorPicker, no `MediaUpload`), así que no está cubierto por la causa confirmada acá; si sigue roto después del `db:seed`, es un bug distinto y hay que diagnosticarlo de cero. Si todo lo anterior queda bien, dar por cerrada esta cadena de bugs de guardado y volver al foco de fondo de la sesión (frontend CICA360 en shared hosting, ver tarea activa arriba).
Notas de subtareas anteriores (2026-09-01): Fix real y distinto de los 4 anteriores — `content.body` salía como JSON TipTap crudo en vez de HTML, mostrando literalmente `[object Object]` en el sitio real (screenshot del Tech Lead, home ya sin 500). Causa: `Forms\Components\RichEditor::make('content.body')` en `PageResource.php` (usado en los bloques `rich_text`, `split`, `legal_notice`, y en `answer` de cada item de `faq`) guarda el contenido como documento TipTap/ProseMirror JSON — comportamiento de Filament 5, no un error de configuración — pero `RichText.astro`/`Split.astro` siempre esperaron `content.body` como string HTML listo para `set:html`. Nunca existió una conversión entre ambos — bug preexistente que recién se pudo ver una vez que la home dejó de tirar 500 (los 4 fixes anteriores de la sesión). Fix: helper nuevo `renderRichContent()` en `ResolvesPublicLinks.php`, usando `Filament\Forms\Components\RichEditor\RichContentRenderer` — el conversor OFICIAL de Filament (basado en `ueberdosis/tiptap-php`, ya presente en `vendor/`, no hubo que instalar nada) que convierte el JSON a HTML sanitizado (`Str::sanitizeHtml()`, protege contra XSS). Si el valor ya es un string (`cta` usa `Textarea` plano, no `RichEditor`), se devuelve sin tocar. Aplicado a `content.body` de cualquier tipo de bloque (genérico) y a `faq.items[].answer`.
Siguiente subtarea recomendada: el Tech Lead debe recargar la home y confirmar que "¿Qué hacemos?"/"¿A quién nos dirigimos?" (y cualquier `rich_text`/`split`/`legal_notice`/`faq`) muestran el texto real en vez de `[object Object]`, y que el HTML generado (negritas, listas) se ve bien con las clases `.richtext-body` ya definidas en el frontend. Con este fix, los 5 problemas reales encontrados hoy en `ResolvesPublicLinks.php` (array_unique blindado, Collection::get() blindado, content.items reindexado, links reindexado, content.body convertido a HTML) deberían dejar el sitio completamente funcional — si algo TODAVÍA falla después de esto, es un bug nuevo, no una continuación de esta cadena.
Notas de subtareas anteriores (2026-09-01): Tercera y cuarta vuelta del fix de `ResolvesPublicLinks` (mismo dato legado con keys no-secuenciales de las 2 vueltas anteriores, 2 síntomas más). Con los primeros 2 fixes la home de `cica360` ya devolvió **200** por primera vez en esta sesión (confirmado por el Tech Lead: `00:29:07 [200] / 602ms`), pero un segundo después salió `(content.items ?? []).filter is not a function` en `Logos.astro:47`, y luego `block.links.map is not a function` en `Cta.astro:22`. Causa en los dos casos: un array PHP con keys no-secuenciales (mismo dato corrupto) se serializa como objeto JS (`{}`) en vez de array (`[]`) — `array_map()` (para `content.items` de features/logos/services_grid) y `Collection::map()->all()` (para `attachResolvedLinks()`) preservan las keys originales tal cual. Fix: `array_values()` envolviendo esos `array_map()` + una red de seguridad general al final de `transformBlockContent()` (cualquier `content.items`, incluido `faq` que antes pasaba sin tocar, sale reindexado), y `->values()` antes de `->all()` en `attachResolvedLinks()`. En paralelo, `Logos.astro` (cica360) suma `Array.isArray(content.items) ? content.items : []` como defensa en profundidad del lado del frontend, independiente del fix del backend.
Siguiente subtarea recomendada: el Tech Lead debe recargar la home una vez más y confirmar que la terminal de Astro y `laravel.log` quedan sin ningún error. Con los 4 fixes de hoy (array_unique blindado, Collection::get() blindado, content.items reindexado, links reindexado) cualquier forma de array no-secuencial que tenga el dato legado en DB ya no debería poder romper ningún response — el dato corrupto de origen sigue existiendo en la DB real (nunca se identificó la fila/bloque exacto, sin acceso a Postgres desde este sandbox en toda la sesión), pero ya no tiene forma de tirar 500 ni TypeError. Si algo TODAVÍA falla después de esto, ya no es este patrón — hay que diagnosticar el error puntual de cero, probablemente sí necesitando acceso directo a la DB para encontrar la fila exacta.
Notas de subtareas anteriores (2026-09-01): Segunda vuelta del fix de `ResolvesPublicLinks` — el Tech Lead probó el fix anterior reiniciando `npm run dev` y compartió la terminal: el `Array to string conversion` original ya no aparece (confirmado en `laravel.log`), pero salió un error nuevo en el mismo minuto de prueba: `TypeError: array_key_exists(): Argument #1 ($key) must be a valid array offset type at vendor/.../Collection.php:496`. Mismo dato corrupto (el mismo id con forma de array de la vuelta anterior), síntoma distinto: `uniqueScalarIds()` ya protegía el `whereIn()` que arma la Collection `$media`/`$pages`/`$sliders`, pero `resolveMediaRef()` y otros 4 lookups puntuales (`transformPublicLink()` para page/post, `transformBlockContent()` para el slider de `hero` y el `page_id` de `services_grid`) seguían pasando el id CRUDO directo a `->get()`, que internamente hace `array_key_exists($key, ...)` — no tolera `$key` array. Fix: helper nuevo `scalarOrNull()` (`is_scalar($value) ? $value : null`) envolviendo esos 5 `->get()`. Confirmado que `Collection::get()` maneja `null` sano (`$key ??= ''`).
Siguiente subtarea recomendada: el Tech Lead debe reiniciar `npm run dev` una vez más y confirmar que la terminal de Astro ya no muestra ningún `ApiError`/`TypeError` al pedir la home, y que `storage/logs/laravel.log` no suma nuevas entradas de error para ese request. Si TODAVÍA aparece algo, el próximo paso ya no es blindar más código — es encontrar el dato corrupto de raíz en Postgres directamente (`SELECT id, type, content FROM blocks WHERE page_id = (SELECT id FROM pages WHERE slug='home' AND tenant_id=...)`, buscando a simple vista qué `_id` tiene forma de array) y regrabarlo bien desde Studio — este sandbox no tiene conexión a esa DB, esa consulta la corre el Tech Lead.
Notas de subtareas anteriores (2026-09-01): Fix real (primera vuelta) de un 500 en `GET /pages/home` de `cica360` (screenshot del Tech Lead: pantalla de error de Astro, `ApiError`, "Error interno. Intentá de nuevo." — el mensaje fijo que `bootstrap/app.php` devuelve para cualquier 500). Diagnosticado leyendo `storage/logs/laravel.log` REAL (archivo del proyecto en la Mac del Tech Lead, accesible desde este sandbox aunque no haya PHP para ejecutarlo): `ErrorException: Array to string conversion at app/Http/Concerns/ResolvesPublicLinks.php:225`, dentro de `array_unique($mediaIds)` en `attachResolvedBlockContent()`, 9 ocurrencias hoy ~05:20, todas para `page=home`. Causa: algún id (`media_id`/`page_id`/`slider_id`) dentro de un bloque de la home YA guardado en la DB real es un array, no un escalar — `array_unique()` no tolera eso. Repasé `Cliente0ContentSeeder`: todos los ids que siembra son escalares (`Cliente0MediaSeeder::mediaId()` devuelve `?int`), así que el dato corrupto es contenido preexistente en la DB real, no producido por el seeder ni por código que yo haya tocado hoy — y no pude identificar la fila exacta porque este sandbox no tiene conexión a Postgres del Tech Lead. Decisión: blindar en vez de perseguir — nuevo helper `uniqueScalarIds()` en `ResolvesPublicLinks.php` filtra valores no-escalares antes de cada `array_unique()` (5 lugares: `mediaIds`/`pageIds`/`sliderIds` en `attachResolvedBlockContent()`, `pageIds`/`postIds` en `attachResolvedLinks()`) y logea un `Log::warning()` con un label por origen (`content.media`, `content.page`, etc.) cuando descarta algo — el 500 desaparece, pero el dato corrupto de origen sigue sin limpiarse, solo se ignora de forma segura.
Siguiente subtarea recomendada: el Tech Lead debe (1) recargar `/` en `cica360` y confirmar que el 500 ya no aparece; (2) revisar `storage/logs/laravel.log` después de esa recarga — si aparece el nuevo warning `ResolvesPublicLinks: se descartaron ids no-escalares...`, el label indica qué bloque/link tiene el dato corrupto (ej. `content.media` → revisar `media_id` de algún item de `features`/`logos`/`services_grid`/`heading`/`image`/`split` en Studio, guardarlo de nuevo con el valor correcto). Sin eso, el warning queda ahí pero el dato roto sigue existiendo, solo invisible para el usuario final.
Notas de subtareas anteriores (2026-09-01): Regla "Repeaters collapsed by default" aplicada de verdad. El Tech Lead ya había pedido esto ("los sections dentro de cualquier repeat tiene que ser collapsed by default") en una vuelta anterior — audité buscando `Section::make()` anidado dentro de un `Repeater` y no encontré ninguno, reporté "nada que corregir hoy". Lectura incompleta: el Tech Lead volvió a señalarlo con una captura del bloque `logos` (items "Empresa asociada 1/2" expandidos por default) — el problema real eran los `Repeater`s mismos con `->collapsible()` pero sin `->collapsed()`. Corregidos todos los que tenían ese gap: `logos`/`features`/`faq`/`services_grid` (`PageResource.php`), `LinkSchema::make()` (Repeater compartido por CTA/Hero manual/testimonials/etc. — un solo fix cubre todos sus usos), `ServiceResource.php` (Repeaters de "¿Qué ofrecemos?" y "Coberturas"). `MenuResource`/`SliderResource` ya cumplían de antes, no se tocaron. Dejados sin tocar a propósito: `Section::make(...)->collapsible()` de nivel superior que no están anidados dentro de un Repeater (ej. "Diseño de la sección" del Hero, "¿Por qué elegirnos?"/"Tip de ayuda" de Services) — la regla es sobre repeats.
Siguiente subtarea recomendada: el Tech Lead debe confirmar en Studio que los Repeaters tocados arrancan colapsados. Queda como estándar sitewide para cualquier `Repeater` nuevo: `->collapsible()->collapsed()` salvo pedido explícito en contra — vale la pena anotarlo en `ARCHITECTURE.md` o donde se documenten convenciones de Filament si se formaliza más adelante (no hay `.ai/rules`/`record-rule` disponible en este entorno para fijarlo de forma más durable).
Notas de subtareas anteriores (2026-09-01): Fix real de un 500 reportado por el Tech Lead en `/cica360/pages` (`Illuminate\Database\QueryException`, `SQLSTATE[22P02]: invalid input syntax for type integer`, Postgres rechazando un UUID en la columna `sort_order` de `blocks`). Causa: `PageResource.php`, `saveRelationshipsUsing` del `Builder` de bloques — `foreach ($state as $index => $blockData) { ... 'sort_order' => $index }` usaba la key del array de estado como posición, pero el `Builder` de Filament 5 (a diferencia de `Repeater`) keyea su estado por el ID interno de Livewire de cada item (string tipo UUID), no por una posición secuencial. Mientras esas keys "parecían" enteros chicos el bug quedaba invisible; en cuanto Livewire le asignó a un item una key con forma de UUID real, se rompió. Fix de una línea: `foreach (array_values($state) as $index => $blockData)` — reindexa 0,1,2... preservando el orden real en pantalla. Único lugar de la app con este patrón (confirmado por grep sobre todos los Resources).
Siguiente subtarea recomendada: el Tech Lead debe confirmar en Studio que editar/reordenar/duplicar bloques de `/cica360/pages` ya no tira el 500 — probar puntualmente drag-and-drop de reorden (el caso más propenso a generar keys "no numéricas") y guardar. Este sandbox no tiene PHP — no se pudo reproducir el error real ni correr el fix, solo revisión de código + balance de sintaxis.
Notas de subtareas anteriores (2026-09-01): Bloque `logos` ampliado de 7 a 10 placeholders ("generar 10 logos de partners de ejemplo", pedido explícito). 3 archivos nuevos (`cica360_media_logo_8/9/10.png`, 128×128 transparente, generados con Python/Pillow en el mismo estilo que los 7 existentes — badge translúcido + glifo abstracto + subrayado, sin nombre de marca real) commiteados a `storage/app/public/media/`. `Cliente0MediaSeeder::FILES` y `Cliente0ContentSeeder` (bloque `logos` de la home) extendidos a 10 entradas. Con 10 > 7, `Logos.astro` ahora pagina de verdad (7 + 3) — primera vez que el modo carousel del bloque se prueba con datos reales, no solo por revisión de código. Sin cambios de schema/properties/`maxItems` (10 sigue bajo el tope de 28).
Siguiente subtarea recomendada: correr en local `php artisan db:seed --class=Cliente0MediaSeeder --class=Cliente0ContentSeeder` (idempotente, `firstOrCreate`/`updateOrCreate`) y confirmar visualmente en `/` que el carousel de logos pagina 7+3 con flechas/dots/drag. Este sandbox no tiene intérprete PHP (`php -l`/`php artisan` no disponibles) — la única verificación posible fue balance de llaves/paréntesis/corchetes comment-aware sobre los 2 seeders tocados.
Notas de subtareas anteriores (2026-08-31): Bloque `logos` ("Empresas con las que trabajamos") — nunca mostraba nada real en el sitio (5 items placeholder con `media_id: null`). `Cliente0MediaSeeder` gana 7 archivos ya commiteados (`cica360_media_logo_1..7.png`); `Cliente0ContentSeeder` los referencia + agrega `subtitle` + `properties.media_filter_grayscale`/`media_opacity` (ambas properties YA existían — genéricas, mismo set que usa `split` — no se inventó nada nuevo). `PageResource.php`: el bloque `logos` gana una Section "Personalización de estilos" (2 columnas) con esas 2 properties, y el `Repeater::make('content.items')` gana `->maxItems(28)` (4 páginas de 7) como respuesta explícita a "cuánto máximo lista el api" — es un Repeater autocontenido (no una tabla resuelta en runtime como `testimonials`), así que el tope es de UX, no de query. Además, refinamientos varios sobre `testimonials` en esta misma vuelta: `item_background_opacity` (Slider 0-100%, +20 automático al hover) sumado a `item_background_color`, Section "Personalización de estilos" pasada a 2 columnas, `parent_id` fusionado al Grid de 3 columnas en el tab Configuración de `PageResource`, y el bloque testimonials del `LinkSchema` ("Enlace Ver más") pasado de 3 a 2 columnas. El frontend completo (carousel responsive de testimonios, drag+touch, dots; carousel paginado de logos con hover a color real) se implementó en `cica360` — ver su `PROGRESS.md`.
Siguiente subtarea recomendada: correr en local (este sandbox no tiene PHP): `php artisan db:seed` o específicamente `--class=Cliente0MediaSeeder --class=Cliente0ContentSeeder --class=Cliente0TestimonialsSeeder` (siembra los 7 logos, aplica colores/subtítulo/properties nuevas, y blanquea `role` en los 12 testimonios demo — todo `firstOrCreate`/`updateOrCreate`, seguro re-ejecutar), `vendor/bin/pint --dirty --format agent`, `php artisan test`. Confirmar visualmente en Studio: bloque `logos` con la nueva Section de estilos (grayscale/opacidad), tope de 28 items en el Repeater; bloque `testimonials` con Personalización de estilos a 2 columnas. Auditoría pendiente resuelta esta vuelta (sin cambios de código): el pedido "los sections dentro de cualquier repeat tienen que ser collapsed by default" — repasados todos los `Repeater::make()` de la app (`ServiceResource`, `PageResource` ×4, `MenuResource` ×2, `SliderResource`, `LinkSchema`) y ninguno anida hoy un `Section::make()` (usan `Fieldset`, que no tiene collapse) — no hay nada que corregir hoy, pero la regla aplica a partir de ahora para cualquier `Section` nueva dentro de un `Repeater`.
Notas de subtareas anteriores (2026-08-31): Bloque `testimonials` — corrección "expectativa vs realidad" contra 2 capturas del Tech Lead (mockup vs. lo real en CICA360). Property `item_background_color` en `PropertiesSchema`/bloque `testimonials`, colores reales del Design System (`cicagreen-500`/`cicagreen-400`) + subtítulo sembrado en la home. Confirmado sin necesitar cambios: pretitle/title/subtitle ya eran editables en el admin, el CTA "Más casos de éxito" ya apuntaba a la página real, la API ya respetaba el `limit`/`order` por bloque.
Notas de subtareas anteriores (2026-08-31): Árbol de `pages` hasta 3 niveles — ver ADR-036. Pedido del Tech Lead ("como podemos hacer para armar arbol tree de navegacion hasta en 3 niveles, cuando una pagina tenga parent a otra pagina"), reusando la UI de jerarquía de `MenuResource`. Confirmado explícitamente con el Tech Lead (`AskUserQuestion`) que es **solo organización interna en Studio** — la URL pública de cada página sigue siendo su `slug` plano, sin tocar `ResolvesPublicLinks`, la API pública ni la unicidad de slug. Migración nueva `parent_id` autorreferenciado (nullable, `nullOnDelete()`) en `pages`; `Page::parent()`/`children()`/`depth()` nuevos; `PageResource` gana `Select::make('parent_id')` (tab Configuración, `options()` filtrado para evitar ciclos y limitar a 3 niveles) y la tabla indenta visualmente por profundidad (título con eager-load `parent.parent`, orden raíz-primero, `->sortable()` retirado de esa columna).
Notas de subtareas anteriores (2026-08-31, mismo día): fusión de columnas en varios listados (Testimonios, Blog, Páginas: título+slug(+tipo) bajo `->description()`); `PostResource` probó slug-como-link con dominio real del tenant (`Tenant::publicUrl()`, nuevo, no usado por ahora) y se revirtió a texto plano a pedido explícito del Tech Lead; `PageResource.is_home` y `SliderResource.is_active` reescritos de ícono rojo-X/verde-check a un único check clickeable (gris/verde), con fix de paso de un gap de integridad (el toggle de tabla de `is_home` ahora desactiva cualquier otro home del tenant al activar uno nuevo — el `Toggle` del FORM en `HeadingFieldset` todavía no tiene esa protección, gap conocido); `FriendlyDate::ABSOLUTE_FORMAT` fijado a `d:m:Y h:i a`, extendido de `ApiTokens` a `PageResource`/`PostResource`; `MenuResource` — jerarquía de 3 niveles con drag-to-sort propio por nivel. Antes: fix real de un 500 en `/services` (`Service::sanitizeCountries()` + migración de normalización) y `Cliente0ServicesSeeder` ampliado de 7 a 12.
Siguiente subtarea recomendada: correr en local (este sandbox no tiene PHP): `php artisan migrate` (aplica `parent_id` en `pages`, y la normalización de `countries` si no se corrió aún), `vendor/bin/pint --dirty --format agent`, `php artisan test`. Confirmar en Studio: (a) `PageResource` — el nuevo campo "Página superior" aparece en el tab Configuración, al elegir un padre para una página y guardar el listado la muestra indentada debajo de su padre, y no se puede crear un 4to nivel (la página de profundidad 2 no aparece como opción elegible); (b) revisar los 5 servicios nuevos sin captura de mockup en `/services`; (c) confirmar visualmente los toggles de ícono (`is_home`/`is_active`) y el formato de fecha `d:m:Y h:i a` en los listados. Fuera de esta vuelta a propósito: exponer `services`/la jerarquía de `pages` en la API pública, sistema de tags genérico por tenant, protección de "un solo home" en el form (`HeadingFieldset`).
Notas de subtareas anteriores (2026-08-31): `CountryEnum` rediseñado a listado ISO 3166-1 completo — ver ADR-035 (supersede parcialmente ADR-034, módulo de Servicios). Módulo de Testimonios/Casos de Éxito — ver ADR-033. Detalle técnico completo en `DECISIONS.md`/`PROGRESS.md`.
Notas: El estado real de dominios/subdominios/rutas/seguridad/excepciones sigue documentado de forma autoritativa en ARCHITECTURE.md §4 — sin cambios ahí en esta vuelta. Ver ADR-028 en DECISIONS.md para el detalle completo del mecanismo de `MediaUpload`, reusado por `ServiceResource`/`TestimonialResource`.
```
