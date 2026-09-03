# Genesis CMS — Know-how completo: integración del Home (Cliente 0 / CICA360)

> **Propósito de este documento**: el Home de CICA360 (Cliente 0) terminó de integrarse 2026-09-02, confirmado por el Tech Lead como fiel al diseño de Figma. Este doc es un resumen curado y estable — a diferencia de `CURRENT_STATE.md`/`PROGRESS.md` (cronológicos, muy largos) — para que CUALQUIER agente que retome el trabajo (sobre todo tras quedarse sin cuota a mitad de sesión) se ubique rápido: qué bloques tiene el Home, cómo están armados del lado Filament/Eloquent/API, qué patrones ya quedaron establecidos como estándar, y qué errores ya se cometieron y corrigieron.
>
> Ver también el equivalente del lado frontend: `cica360/docs/context/HOME_INTEGRATION.md` (ahí está el detalle del patrón de carousel dinámico, que es compartido conceptualmente pero vive 100% del lado frontend).

---

## 1. Estructura del Home — orden real de bloques

Fuente de verdad: `database/seeders/Cliente0ContentSeeder.php`, método `upsertHomePage()`. Orden real, de arriba a abajo (`BlockTypeEnum` → resumen de contenido/properties clave):

1. **`hero`** (`content.mode: 'slider'`, `content.slider_id`) — no lleva título/imagen propios, referencia un `Slider` con 3 slides (`Cliente0HomeSlidesSeeder`), cada uno con su propio CTA.
2. **`rich_text`** — intro "Centro Internacional de Consultoría y Asesoría". `properties.show_scroll_indicator: true` (flecha invitando a bajar — property vive a NIVEL DE BLOQUE, no del slider, ver ADR-027 si se necesita el porqué). `content_width: boxed`. Link outline a la página `sobre-cica`.
3. **`split`** ×2 — "¿Qué hacemos?" (imagen izquierda) y "¿A quién nos dirigimos?" (imagen derecha, `media_position: right`). Ambos con `content_width: full` (bleed a los bordes) y `text_background_color: #F6F6F6`.
4. **`testimonials`** — "Casos de éxito", `content.limit: 5`, `content.order: desc`. Fondo `#206576`, tarjetas `#4D919E`. Link a página `casos-de-exito` (sin límite, lista todos).
5. **`logos`** — "Empresas con las que trabajamos", 10 logos reales (`Cliente0MediaSeeder`).
6. **`cta`** — cierre de la home.

El **Footer** vive aparte, en la página especial `footer-principal` (`type: Footer`) con bloques `colophon` + `footer_bottom` — resuelto y expuesto globalmente, no por página. El **Header** usa el módulo `Menu`/`MenuItem` (no es contenido de página).

---

## 2. Dónde está cada pieza (Filament / Eloquent / API)

| Bloque | Schema del Builder (Filament) | Resolución pública (API) |
|---|---|---|
| Todos los bloques de página | `app/Filament/Resources/PageResource.php` — cada `type` es un `Builder\Block` con su propio `->schema()` | `app/Http/Resources/Api/V1/*` + `app/Support/ResolvesPublicLinks.php` (`transformBlockContent()`) |
| Colores/fondo/ancho reusables | `app/Filament/Schemas/PropertiesSchema.php` — `background_type`, `content_width`, filtros de media (`media_filter_grayscale`/`media_opacity`), campos de link (`LinkSchema.php`) | — |
| Testimonios (módulo propio, con tabla) | `app/Filament/Resources/TestimonialResource.php`, modelo `Testimonial` | Resuelto en runtime contra la tabla real (`is_visible=true`, ordenado por `sort_order`) dentro de `ResolvesPublicLinks`, rama `testimonials` — el bloque NO tiene su propio Repeater de contenido, `content.items[]` se arma en runtime. Además expuesto standalone en `GET /v1/{tenant}/testimonials` (ADR-046, list-only, sin `show()`). |
| Servicios (módulo propio, con tabla) | `app/Filament/Resources/ServiceResource.php`, modelo `Service` | `GET /v1/{tenant}/services` + `/services/{slug}` (mismo patrón que Posts). Consumido por el Home indirectamente (vía `services_grid`, no directamente en el Home actual) y por `cica360` en `/servicios`. |
| Logos (sin tabla propia) | Repeater dentro del bloque `logos` en `PageResource.php` — el Tech Lead confirmó explícitamente que NO quiere una tabla dedicada, solo filtrar primeros/últimos sin eliminar un logo | `content.limit`/`content.order` resueltos en `ResolvesPublicLinks`, misma idea que otros bloques con Repeater. |
| Menú (Header) | `app/Filament/Resources/MenuResource.php` + campo custom `MenuTreeBuilder` (drag-and-drop estilo WordPress, tope de autoría de 3 niveles vía `maxDepth(3)`) | `GET /v1/{tenant}/menus/{slug}` — árbol RECURSIVO de profundidad ilimitada (`MenuController::buildTree()`), sin relación con el tope de 3 niveles de Studio (ese es solo un límite de UI de autoría). |

---

## 3. Patrones ya establecidos — reusar, no reinventar

### 3.1 `PropertiesSchema` — properties genéricas compartidas
`background_type` (solid/gradient, +`image` en `cta`/`hero`/`heading`), `content_width` (full/boxed/narrow — cada bloque frontend decide cuáles de los 3 usa), `media_filter_grayscale`/`media_opacity` (reusado en `logos` y `split`). Cualquier bloque nuevo con fondo o filtro de imagen debe usar estos campos de `PropertiesSchema`, no crear campos propios duplicados.

### 3.2 `LinkSchema` con `Page::scopePubliclyLinkable()`
El selector de "página de destino" en cualquier link (Repeater de links, `MenuTreeBuilder`, `services_grid`) debe usar `Page::publiclyLinkable()` — excluye páginas de tipo `Header`/`Footer` (que no son navegables directamente). Ya se corrigió un bug real donde 2 sitios de `LinkSchema.php` + `PageResource::services_grid` no aplicaban este scope y dejaban elegir páginas internas del sitio (footer/header) como destino.

### 3.3 Multi-tenancy: single DB + `tenant_id`, con planes que afectan contenido
`Tenant` tiene helpers de tier: `isFreeTier()`, `isSponsorshipTier()` (plan "Auspicio/Convenio", nuevo — mismas restricciones que Free salvo que SÍ puede personalizar el copyright del footer de forma acotada, ver ADR-043), y el resto de planes pagos. CICA360 (Cliente 0) está en el plan `sponsorship`, NO en `free` — si se toca cualquier lógica de gating de features por plan, confirmar contra el `Tenant` real de CICA360 antes de asumir "Cliente 0 = Free Forever" (asunción que era cierta al inicio del proyecto pero dejó de serlo el mismo día del ADR-043).

### 3.4 Identificadores — regla dura del proyecto
`id` (integer) solo para relaciones ORM internas, `uuid` para todo lo público (API, rutas de Filament), `slug` para URLs amigables. `lang_iso` (`varchar(5)`, default `es`) sin tabla `languages` — enum PHP (`LanguageEnum`). Esto es un requisito transversal del MVP (ver `CLAUDE.md` de este repo), no específico del Home, pero todo lo construido para el Home lo respeta y cualquier bloque/tabla nueva debe seguir el mismo criterio.

---

## 4. Historia de bugs ya resueltos — leer ANTES de tocar estos archivos

### 4.1 `MenuTreeBuilder` (campo custom de Filament) sin ningún estilo
**Causa raíz**: Filament 5 (Tailwind v4) NO compila clases Tailwind arbitrarias usadas en Blade views custom de la APP — solo compila lo que usan los propios templates vendor de Filament. Cualquier campo/página custom con clases Tailwind propias necesita un **theme de panel registrado**. Fix aplicado manualmente (sin poder correr el Artisan real en el sandbox, replicando lo que hace `php artisan make:filament-theme {panel}`):
1. `resources/css/filament/cms/theme.css` — importa el `theme.css` base de Filament + declara `@source` (Tailwind v4 no tiene `tailwind.config.js`, el content-detection se hace con `@source`) apuntando a `app/Filament/**/*` y `resources/views/filament/**/*`.
2. `vite.config.js` — el nuevo `theme.css` se agrega al array `input` del plugin de Laravel.
3. `app/Providers/Filament/PanelCmsProvider.php` — `->viteTheme('resources/css/filament/cms/theme.css')`.
4. Requiere `npm run build` real (no verificable en este sandbox, ver §5) para tomar efecto.

**Si se agrega CUALQUIER campo/página Filament nueva con clases Tailwind propias (no solo las de componentes `<x-filament::*>`), este theme YA debería cubrirlas** (los `@source` apuntan a todo `app/Filament/**` y `resources/views/filament/**`) — no hace falta repetir el setup, solo confirmar que el archivo nuevo cae dentro de esos globs.

### 4.2 Campos de formulario dentro de `MenuTreeBuilder` sin estilo (bug DISTINTO al 4.1)
Aun con el theme del panel activo, los `<input>`/`<select>` crudos con clases `fi-input`/`fi-select` escritas a mano en el Blade NO se veían con el estilo de Filament. **Causa raíz**: el box visible (borde/rounded/dark) de un campo Filament viene de un `<div class="fi-input-wrp">` — un WRAPPER que solo genera el componente Blade real `<x-filament::input.wrapper>`, no la clase `fi-input` puesta a mano sobre un `<input>` plano. Fix: reemplazar cualquier `<input>`/`<select>`/`<input type="checkbox">` hecho a mano dentro de Blade views custom por los componentes reales:
```blade
<x-filament::input.wrapper>
  <x-filament::input type="text" x-model="..." x-on:input="..." />
</x-filament::input.wrapper>
<x-filament::input.select x-model="...">...</x-filament::input.select>
<x-filament::input.checkbox x-model="..." x-on:change="..." />
```
Estos componentes forwardean atributos extra (incluyendo `x-model`/`x-on:*` de Alpine) vía el `$attributes` bag de Blade — no hace falta declarar esos atributos en el componente para que funcionen.

### 4.3 API Playground / API Documentation desactualizados tras agregar endpoints
Cuando se agregó el endpoint público de Servicios, se actualizó `cica360/docs/context/api/stamless-api-v1.md` pero se olvidó actualizar 3 superficies propias de `genesis`: `docs/api/v1.md` (manual que también renderiza en vivo la página Filament "API Documentation"), `docs/api/openapi.v1.yaml`, y el array `PRESETS` de `app/Filament/Pages/ApiPlayground.php`. **Regla para cualquier endpoint público nuevo**: actualizar SIEMPRE estas 4 superficies juntas (más el manual de `cica360` si ese repo lo consume) — no solo el manual del consumidor.

### 4.4 Documentación de anidamiento de menús, desactualizada
`docs/api/v1.md` decía "children anidados hasta un nivel", pero el código real (`MenuItemResource`, `MenuController::buildTree()`) soporta profundidad recursiva ilimitada — la doc había quedado desactualizada desde antes de que existiera el builder de 3 niveles. Corregido en ambos manuales (genesis y cica360). Recordatorio: la API no tiene límite de profundidad; el único límite (`maxDepth(3)`) es de AUTORÍA en `MenuTreeBuilder` (Studio), y el frontend (`Header.astro`, cica360) hoy solo renderiza 1 nivel — ver el doc de cica360 §5.7 para el estado de ese gap.

---

## 5. Limitaciones del sandbox de trabajo (recordatorio permanente)

- **No hay runtime PHP en el sandbox de verificación** — ningún cambio de este tipo de sesión pudo correrse (`php artisan migrate`, `php artisan test`, `php artisan tinker`, etc.). Verificación de sintaxis PHP se hizo con un checker Python de balance de brackets (con un workaround conocido para falsos positivos de la sintaxis de atributos PHP 8, `#[Fillable(...)]`).
- **`npm run build`/`npm run dev` tampoco corren** — los binarios nativos de `rolldown`/Vite instalados en `node_modules` son `darwin-arm64` (Mac), no compatibles con este contenedor Linux. El fix del theme de Filament (§4.1) en particular NO puede confirmarse visualmente desde acá — requiere que el Tech Lead corra el build localmente.
- Ningún cambio de Filament (temas, campos custom, resources) fue confirmado visualmente en un navegador real desde este sandbox — todo queda pendiente de confirmación del Tech Lead.

---

## 6. Estado al cierre de esta sesión (2026-09-02)

Home 100% integrado, confirmado por el Tech Lead como fiel al diseño de Figma. Backend del Home (schemas de bloques, `ResolvesPublicLinks`, seeders, módulos de Testimonios/Servicios/Logos/Menú) estable — ver `PROGRESS.md`/`CURRENT_STATE.md`/`DECISIONS.md` (ADRs 027–046) para el detalle cronológico completo. Próximos pasos sugeridos (no bloqueantes): Resources de Filament para los módulos que aún les falten pulido de UX, y el resto de páginas de contenido con el mismo nivel de detalle que el Home. Recordar que este proyecto NO debe implementar Resources de Filament adicionales al alcance del MVP ni billing sin coordinar — ver reglas de `CLAUDE.md`.
