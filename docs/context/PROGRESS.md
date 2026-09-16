# Genesis CMS — Historial de progreso

> Log cronológico de avances. Entradas nuevas **arriba** (más reciente primero).
> Formato sugerido por entrada:
>
> ```
> ## YYYY-MM-DD — Título corto
> - **Agente/autor:** ...
> - **Qué se hizo:** ...
> - **Archivos/áreas:** ...
> - **Siguiente:** ...
> ```

## 2026-09-15 — DevOps / Infraestructura: Alineación de scripts de despliegue `deploy.sh` y `production.sh` para Stamless
- **Pedido del Tech Lead:** "podri alinear el deploy.sh al proyecto stamless, siguiendo la convencion que tienen este archivo, ya cuento con server-webapps que tiene configurado mi llave, necesito que todo esté listo para desplegar en stage que viene a ser como produccion solo para revision de cambios pero para produccion solo será un copy rsync en el mismo servidor y se publicará los cambios. nos basamos en deploy.sh a stage".
- **Implementación:**
  1. En `deploy.sh`, se adaptaron todas las variables institucionales al proyecto Stamless (`STAMLESS_DEPLOY_SERVER`, `STAMLESS_DEPLOY_DOMAIN`, `STAMLESS_DEPLOY_SUBDOMAIN`), configurando `server-webapps` como servidor predeterminado y `stage_stamless` (`/var/www/vhosts/stage_stamless/`) como destino de staging.
  2. Se optimizó la sincronización `rsync` excluyendo dependencias, tests, documentación y storage local, ejecutando la compilación frontend previa con Vite.
  3. En la ejecución remota, se incluyeron pasos para permisos (`fix-perms`), dependencias Composer, `storage:link`, cachés de configuración/rutas/vistas, optimización de Filament (`filament:optimize`) y migraciones automáticas (`php artisan migrate --force` y soporte de `-m` para fresh/seed).
  4. Se creó `production.sh` para promover de Stage a Producción mediante `rsync` local en `server-webapps` (`/var/www/vhosts/stage_stamless/` -> `/var/www/vhosts/stamless/`) sin volver a subir archivos desde la máquina local.
  5. En `.env.example`, se añadió el bloque de variables de infraestructura de Stamless.
- **Archivos:**
  - `deploy.sh`
  - `production.sh`
  - `.env.example`
- **Verificación:** Scripts con permisos de ejecución `+x`; suite de tests: **108 passed, 520 assertions**.

## 2026-09-15 — Seguridad / Hardening: Protección Honeypot, restricción de tipos de media y rate limiting inteligente
- **Pedido del Tech Lead:** "ejecuta las mejoras pero si no hay cambios en codigo procedemos desplegar, confirmame ahora".
- **Implementación:**
  1. **Honeypot Anti-Bot:** En `app/Http/Controllers/Api/V1/FormSubmissionController.php`, se añadió trampa honeypot (`honeypot`, `_hp_check`, `_gotcha`) que descarta envíos de bots automatizados de forma silenciosa (201 simulado) sin saturar la base de datos ni consumir cuota de emails transaccionales.
  2. **Blindaje de Subida de Archivos:** En `app/Filament/Resources/MediaResource.php`, se agregaron `acceptedFileTypes` explícitos (imágenes, videos web seguros, PDF) y `maxSize(51200)` para bloquear cualquier intento de subir ejecutables (`.php`, `.phtml`, `.exe`, `.sh`, `.phar`).
  3. **Rate Limiting Inteligente por Tenant:** En `app/Providers/AppServiceProvider.php`, se mejoró `RateLimiter::for('api')` para limitar por `tenant_id` cuando la solicitud está autenticada y por `ip` para tráfico no autenticado.
- **Archivos:**
  - `app/Http/Controllers/Api/V1/FormSubmissionController.php`
  - `app/Filament/Resources/MediaResource.php`
  - `app/Providers/AppServiceProvider.php`
- **Verificación:** Pint limpio; suite de tests: **108 passed, 520 assertions**.

## 2026-09-15 — Platform & Auth: Retiro de FilamentInfoWidget y corrección de nombre de Super Admin a "Eduardo Flores"
- **Pedido del Tech Lead:** "genial, quitar widget filament" y "corrige el nombre del usuario: soy Eduardo Flores o Edu. Flores" con capturas del panel Platform.
- **Implementación:**
  1. En `app/Providers/Filament/PanelPlatformProvider.php`, se eliminó `FilamentInfoWidget::class` del registro de widgets e imports.
  2. En `database/seeders/PlatformSeeder.php`, se actualizó el nombre del usuario `zedu77@gmail.com` a `Eduardo Flores`.
  3. Se ejecutó `php artisan db:seed --class=PlatformSeeder` sincronizando el nombre en la base de datos PostgreSQL.
- **Archivos:**
  - `app/Providers/Filament/PanelPlatformProvider.php`
  - `database/seeders/PlatformSeeder.php`
- **Verificación:** Seeder ejecutado; Pint limpio; suite de tests: **108 passed, 520 assertions**.

## 2026-09-15 — Seguridad / Auth: Auto-registro público bloqueado y vinculación social exclusiva para usuarios pre-existentes
- **Pedido del Tech Lead:** "no quiero register habilitado, por eso te pedi agregar como contenido inicial de stamless el usuario master".
- **Causa y Solución:**
  1. Con `registration(false)` como booleano estático, el paquete `filament-socialite` impedía que usuarios ya existentes en la tabla `users` (como el Master `zedu77@gmail.com`) vincularan por primera vez su cuenta en la tabla puente `socialite_users`.
  2. Se configuró el closure `->registration(fn (string $provider, mixed $oauthUser, ?User $user): bool => $user !== null)` en `PanelCmsProvider` y `PanelPlatformProvider`.
  3. Esto mantiene el **registro público estrictamente cerrado** (si `$user === null`, cualquier intento de login por un correo no registrado es denegado de inmediato con `RegistrationNotEnabled`), mientras permite autenticarse a los usuarios creados previamente vía seeders o administración.
- **Archivos:**
  - `app/Providers/Filament/PanelCmsProvider.php`
  - `app/Providers/Filament/PanelPlatformProvider.php`
- **Verificación:** Pint limpio; suite de tests: **108 passed, 520 assertions**.

## 2026-09-15 — Auth / OAuth: Soporte multi-dominio dinámico y modo stateless en Socialite para Studio y Platform
- **Pedido del Tech Lead:** "desde platform me carga gmail oauth y luego redirecciona a studio login" con error de inicio de sesión.
- **Causa raíz:**
  1. Si `GOOGLE_REDIRECT_URL` en `.env` apuntaba al dominio absoluto fijo de Studio (`https://studio.stamless.host/oauth/callback/google`), Google devolvía siempre la autenticación a Studio independientemente de haberse originado en Platform.
  2. Al llegar a Studio, la falta de coincidencia de cookie de sesión entre subdominios provocaba un `InvalidStateException` que rechazaba el login con "Error al iniciar sesión".
- **Implementación:**
  1. En `app/Providers/Filament/PanelCmsProvider.php` y `app/Providers/Filament/PanelPlatformProvider.php`, se activó `->stateless(true)` en todos los proveedores de `FilamentSocialitePlugin` para evitar fallos de estado entre dominios o políticas de cookies estrictas.
  2. En `.env.example`, se estandarizaron las rutas de redirección OAuth a rutas relativas (`GOOGLE_REDIRECT_URL="/oauth/callback/google"`), permitiendo que Socialite resuelva dinámicamente al subdominio desde donde se inició el login (`platform.stamless.host` o `studio.stamless.host`).
  3. Se documentó la necesidad de registrar ambas URIs en Google Cloud Console (`https://studio.stamless.host/oauth/callback/google` y `https://platform.stamless.host/oauth/callback/google`).
- **Archivos:**
  - `app/Providers/Filament/PanelCmsProvider.php`
  - `app/Providers/Filament/PanelPlatformProvider.php`
  - `.env.example`
- **Verificación:** Pint limpio; suite de tests: **108 passed, 520 assertions**.

## 2026-09-15 — Auth / Seeder: Creación de usuario Super Admin Master (Nivel GOD) con zedu77@gmail.com
- **Pedido del Tech Lead:** "en el seeder crear el usuario super usuario master, el general de generales, el mas perron de todos los usuarios, de nivel GOD, con el correo zedu77@gmail.com para poder autenticarme tambien con gmail".
- **Implementación:**
  1. En `database/seeders/PlatformSeeder.php`, se incorporó la creación/actualización idempotente del usuario `zedu77@gmail.com` con `name => 'Eduardo (Master GOD)'`, `is_super_admin => true`, `password => 'password123'`, `email_verified_at => now()`, `tenant_id => null`.
  2. En `app/Models/User.php`, se añadió `'is_super_admin' => 'boolean'` a `casts()`, y se extendieron los métodos `getTenants(Panel $panel)` para devolver `Tenant::all()` y `canAccessTenant(Model $tenant)` para devolver `true` incondicionalmente cuando `is_super_admin === true`.
  3. Se ejecutó `php artisan db:seed --class=PlatformSeeder` insertando y persistiendo el usuario en la base de datos PostgreSQL.
  4. Gracias a `registration(false)` y la búsqueda por email en `FilamentSocialite`, el usuario puede iniciar sesión tanto con contraseña como directamente mediante el botón de **Google** (Gmail) en Platform y Studio.
- **Archivos:**
  - `database/seeders/PlatformSeeder.php`
  - `app/Models/User.php`
- **Verificación:** Seeder ejecutado con éxito; Pint limpio; suite de tests: **108 passed, 520 assertions**.

## 2026-09-15 — Dashboard (UX): Altura acotada con scroll vertical en widget de "Uso del plan"
- **Pedido del Tech Lead:** "este widget de uso del plan , limitar la altura para que scrollee los indicadores" con captura de los 8 items en vertical.
- **Implementación:**
  1. En `resources/views/filament/cms/widgets/plan-usage-widget.blade.php`, se asignó la clase `.fi-wi-plan-usage-list` con `max-h-[340px]`, `overflow-y-auto`, `overscroll-contain` y gap ajustado.
  2. En `resources/css/filament/cms/theme.css`, se definió un scrollbar fino (`6px`), redondeado y adaptado para modo claro y oscuro (`scrollbar-color: rgba(...) transparent`).
  3. Esto permite que el widget mantenga una altura armónica en el dashboard (alineada a ~340px) y asome el quinto indicador para invitar al scroll vertical continuo.
  4. Se recompilaron los assets con `npm run build`.
- **Archivos:**
  - `resources/views/filament/cms/widgets/plan-usage-widget.blade.php`
  - `resources/css/filament/cms/theme.css`
- **Verificación:** `npm run build` exitoso; suite de tests: **108 passed, 520 assertions**.

## 2026-09-15 — Dashboard (UX Mobile): Corrección de selectores en schema grid del widget StatsOverview
- **Pedido del Tech Lead:** "nada se mantienen, y tienen espacio para ocupar" con captura de DevTools mostrando que el wrapper externo `div.fi-grid-col` estaba recibiendo el ancho del 76% en vez de ocupar el 100%.
- **Causa raíz:** Los selectores CSS anteriores coincidían tanto con la grilla del esquema externo (`.fi-wi-stats-overview > .fi-grid`) como con la grilla interna de las tarjetas (`.fi-wi-stats-overview-stat`), encogiendo todo el contenedor de la sección a 355px (76%) y dejando un 24% de espacio vacío en negro a la derecha.
- **Implementación:**
  1. En `resources/css/filament/cms/theme.css`, se forzaron los contenedores de nivel superior (`.fi-wi-stats-overview`, `.fi-grid-col` que contiene el `Section`/`GridComponent`) a `width: 100% !important; max-width: 100% !important; display: block !important;`.
  2. Se acotó el comportamiento horizontal (`display: flex; overflow-x: auto;`) y el dimensionamiento (`flex: 0 0 76%`) exclusivamente a la grilla interna y columnas directas de las tarjetas de métricas (`.fi-grid-col:has(> .fi-wi-stats-overview-stat)` y `.fi-sc-component > .fi-grid > .fi-grid-col`).
  3. Se recompilaron los assets con `npm run build`.
- **Archivos:**
  - `resources/css/filament/cms/theme.css`
- **Verificación:** `npm run build` exitoso; suite de tests: **108 passed, 520 assertions**.

## 2026-09-15 — UI / Auth: Cambio de layout de Login en Platform a MediaPosition::Right
- **Pedido del Tech Lead:** "cambia de login" con captura de `platform.stamless.host/login` en modo Cover (donde la tarjeta tapaba el logo Stamless del fondo).
- **Implementación:**
  1. En `app/Providers/Filament/PanelPlatformProvider.php`, se cambió `MediaPosition::Cover` y `blur(3)` por `MediaPosition::Right`.
  2. Ahora Platform presenta un layout dividido limpio (formulario a la izquierda y portada Stamless a la derecha, complementando a Studio que tiene la portada a la izquierda) sin que la tarjeta tape ni desenfoque el isotipo de la marca.
- **Archivos:**
  - `app/Providers/Filament/PanelPlatformProvider.php`
- **Verificación:** Pint limpio; suite de tests: **108 passed, 520 assertions**.

## 2026-09-15 — Dashboard (UX Mobile): Carrusel de indicadores ocupando el 100% del ancho con sangrado fluido
- **Pedido del Tech Lead:** "pero todo el container debe ocupar el 100% del ancho" con captura del Dashboard.
- **Implementación:**
  1. En `resources/css/filament/cms/theme.css`, se aplicó sangrado completo al carrusel móvil (`width: 100%; margin-inline: -1rem; padding-inline: 1rem;`).
  2. Esto permite que el carrusel ocupe el 100% del ancho total del viewport de extremo a extremo, alineando la primera tarjeta al margen del dashboard y permitiendo que el 1/3 de la siguiente tarjeta se asome sin cortes artificiales hasta el borde derecho de la pantalla.
- **Archivos:**
  - `resources/css/filament/cms/theme.css`
- **Verificación:** Pint limpio; suite de tests: **108 passed, 520 assertions**.

## 2026-09-15 — Dashboard (UX Mobile / Gestalt): Indicadores con ancho al 76% mostrando 1/3 de la siguiente tarjeta (Ley de Continuidad)
- **Pedido del Tech Lead:** "el ancho de cada elemento o widget en mobile que tengan limite para que asi se muestre 1/3 del siguiente elemento y asi se aplique la ley de continuidad de gestalt" con captura adjunta.
- **Implementación:**
  1. En `resources/css/filament/cms/theme.css`, se configuró el ancho de cada tarjeta de estadística a `flex: 0 0 76%; min-width: 76%; max-width: 76%` en pantallas móviles (`< 768px`).
  2. Esto permite que el elemento actual esté enfocado con claridad mientras que exactamente ~1/3 (24% restante del ancho) de la siguiente tarjeta asome por el borde derecho, aplicando la **Ley de Continuidad de Gestalt** para señalizar de forma natural la presencia de contenido interactivo deslizable.
  3. Se ajustó el padding interno a `1.125rem 1.25rem` para aprovechar el espacio en mobile con total legibilidad.
- **Archivos:**
  - `resources/css/filament/cms/theme.css`
- **Verificación:** Pint limpio; suite de tests: **108 passed, 520 assertions**.

## 2026-09-15 — Dashboard (UX Mobile): Indicadores full-width (100%) con scroll snap carrusel
- **Pedido del Tech Lead:** "podria ser pero fullwidth" con captura del indicador en mobile.
- **Implementación:**
  1. En `resources/css/filament/cms/theme.css`, se actualizó el layout móvil (`< 768px`) para que cada tarjeta de estadística ocupe el 100% del ancho (`flex: 0 0 100%; min-width: 100%; max-width: 100%`) con `scroll-snap-align: start; scroll-snap-stop: always;`.
  2. Al deslizar horizontalmente, la pantalla encaja de manera nítida y completa de tarjeta en tarjeta (tipo carrusel app-like), sin cortes intermedios de texto ni bordes truncados.
- **Archivos:**
  - `resources/css/filament/cms/theme.css`
- **Verificación:** Pint limpio; suite de tests: **108 passed, 520 assertions**.

## 2026-09-15 — Dashboard (UX Mobile): Indicadores con límite de ancho y scroll horizontal táctil con snap
- **Pedido del Tech Lead:** "esto poner un limite y que los indicadores tengan scroll" con captura de las tarjetas apiladas en mobile.
- **Implementación:**
  1. En `resources/css/filament/cms/theme.css`, se incorporaron reglas de layout horizontal para `.fi-wi-stats-overview .fi-grid` en pantallas móviles (`< 768px`):
     - `display: flex; overflow-x: auto; scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch;`.
     - Límite y ancho acotado por tarjeta de indicador (`min-width: 200px; max-width: 250px; flex: 0 0 68%; scroll-snap-align: start;`).
     - Espaciado `gap: 0.75rem` y padding inferior para scrollbar fino táctil.
  2. En desktop (`≥ 768px`), los 4 indicadores se mantienen en la cuadrícula estándar de 4 columnas.
- **Archivos:**
  - `resources/css/filament/cms/theme.css`
- **Verificación:** Pint limpio; suite de tests: **108 passed, 520 assertions**.

## 2026-09-15 — Dashboard / UX: `LeadsOverviewWidget` con 4 stats (Atendidos añadido) y layout responsivo de 2 columnas en mobile
- **Pedido del Tech Lead:** "quiero en mobile en 2 columnas, y agregar un widget mas apra que sean 4 y puedan dividirse adecuadamente" con captura del Dashboard.
- **Implementación:**
  1. En `app/Filament/Widgets/LeadsOverviewWidget.php`, se definió `$columns = ['default' => 2, 'sm' => 2, 'md' => 4]`, logrando una cuadrícula 2x2 en mobile y 1x4 en desktop.
  2. Se agregó la 4ta tarjeta de estadística **"Atendidos"** (`ContactStatusEnum::Closed`), con descripción `'Contactos resueltos'`, badge de color success cuando hay contactos resueltos e ícono `heroicon-m-check-badge`.
  3. Los 4 KPIs quedan equilibrados:
     - 1. **Leads nuevos** (`Sin atender todavía`)
     - 2. **En proceso** (`Contactos en seguimiento`)
     - 3. **Atendidos** (`Contactos resueltos`)
     - 4. **Total de contactos** (`X en los últimos 7 días`)
- **Archivos:**
  - `app/Filament/Widgets/LeadsOverviewWidget.php`
- **Verificación:** Pint limpio; suite de tests: **108 passed, 520 assertions**.

## 2026-09-15 — Branding / Auth: Brand names con sufijo estilizado "Studio" (Ámbar) y "Platform" (Teal) en logins
- **Pedido del Tech Lead:** "aqui deberia decir Stamless Studio arriba del login" + "y aqui Stamless Platform (Platform con el color de texto del theme para platform)" + "(Studio con el color de texto del theme para studio)".
- **Implementación:**
  1. En `app/Providers/Filament/PanelCmsProvider.php`, se ajustó `brandName` para mostrar siempre `Stamless <span class="fi-logo-suffix">Studio</span>` tanto en el login previo a autenticar como dentro del panel.
  2. En `app/Providers/Filament/PanelPlatformProvider.php`, se configuró `brandName` con `Stamless <span class="fi-logo-suffix">Platform</span>`.
  3. En `resources/css/filament/cms/theme.css`, la clase `.fi-logo-suffix` utiliza dinámicamente las variables `var(--primary-600)` (modo claro) y `var(--primary-400)` (modo oscuro):
     - En **Studio**, "Studio" se tiñe automáticamente con el color primario Ámbar (`#D97706`).
     - En **Platform**, "Platform" se tiñe automáticamente con el color primario Teal (`#0F766E`).
- **Archivos:**
  - `app/Providers/Filament/PanelCmsProvider.php`
  - `app/Providers/Filament/PanelPlatformProvider.php`
  - `resources/css/filament/cms/theme.css`
- **Verificación:** Pint limpio; suite de tests: **108 passed, 520 assertions**.

## 2026-09-15 — UI / Auth: Vinculación de `viteTheme` y restricciones explícitas de SVG para Platform panel
- **Pedido del Tech Lead:** "en platform tambien tiene que estar alineado" con captura donde el SVG de Google se desbordaba.
- **Implementación:**
  1. En `app/Providers/Filament/PanelPlatformProvider.php`, se agregó `->viteTheme('resources/css/filament/cms/theme.css')`, habilitando el procesamiento completo de clases Tailwind custom en el panel de Plataforma.
  2. En `resources/views/vendor/filament-socialite/components/buttons.blade.php`, se blindaron los elementos `<svg>` y `<x-filament::icon>` con atributos explícitos `width="16" height="16"` y estilos inline `style="width: 16px; height: 16px; min-width: 16px; max-width: 16px; flex-shrink: 0;"`, garantizando que bajo cualquier circunstancia o panel el ícono conserve exactamente su tamaño de 16x16px dentro del botón de 36px.
- **Archivos:**
  - `app/Providers/Filament/PanelPlatformProvider.php`
  - `resources/views/vendor/filament-socialite/components/buttons.blade.php`
- **Verificación:** Pint limpio; suite de tests: **108 passed, 520 assertions**.

## 2026-09-15 — UI / Auth: Separador split-line con fondo idéntico al contenedor y colores de proveedor con contraste en botones
- **Pedido del Tech Lead:** "mantener el boton con el color del proveedor y el background de "O inicia sesion con" tiene que tener el mismo fondo del fondo del container" con captura adjunta.
- **Implementación:**
  1. En `resources/views/vendor/filament-socialite/components/buttons.blade.php`, se eliminó la carga externa del CSS bundle de `filament-socialite` (`x-load-css`) que forzaba reglas rígidas `@media (prefers-color-scheme:dark)` e inyectaba clases `bg-white` conflictivas.
  2. El separador `"O inicia sesión con"` se rediseñó con una estructura flex split-line (`flex-grow border-t` a la izquierda, texto al centro y `flex-grow border-t` a la derecha), haciendo que el texto no requiera ningún color de fondo simulado y adopte de manera 100% natural e idéntica el fondo exacto del contenedor en modo claro, modo oscuro, blur o cualquier layout.
  3. Los botones de proveedores ahora cuentan con adaptación completa de color y contraste: `bg-white dark:bg-gray-900`, bordes sutiles de marca en hover (`border-[#EA4335]/60`, `border-[#00A4EF]/60`, etc.), texto de alto contraste `text-gray-700 dark:text-gray-200` y sus íconos oficiales multicolores / de marca.
- **Archivos:**
  - `resources/views/vendor/filament-socialite/components/buttons.blade.php`
- **Verificación:** Pint limpio; suite de tests: **108 passed, 520 assertions**.

## 2026-09-15 — Branding / Auth: Generación de cover JPG 1280x1280 de Stamless para Login y vinculación en paneles
- **Pedido del Tech Lead:** "Genera un JPG 1280x1280 para el cover del login de Stamless (public/images/auth/stamless-login-cover.jpg o el path que ya usa Login). Marca: Stamless (CMS headless multi-tenant para MUCHOS negocios, no solo CICA360). Fondo charcoal #1C1C1C. Wordmark Stamless en blanco, sans geométrica, grande, centrado. Sublinea cream: Una fuente. Todos los sitios. Detalle amber #D97706 (linea fina bajo el nombre). Composicion 1:1, mucho aire, look premium B2B. NO stock de personas, NO graficas en tablet, NO logo CICA360, NO watermark. Exportar exactamente 1280x1280. Si el login usa <img> o CSS background, apunta ese archivo y comprueba object-fit: cover. y lo guardas en storage/app/public/assets/ o donde corresponda".
- **Implementación:**
  1. Se generó el archivo JPG exactamente a 1280x1280 píxeles con supersampling 2x para máxima nitidez tipográfica y guardado con calidad 98.
  2. Diseño: fondo charcoal `#1C1C1C` con sutil profundidad radial, wordmark "Stamless" centrado en blanco con tipografía geométrica bold sans, línea fina de acento en ámbar `#D97706`, y subtítulo "Una fuente. Todos los sitios." en crema `#F6F3EE`.
  3. Guardado en:
     - `public/images/auth/stamless-login-cover.jpg`
     - `storage/app/public/assets/stamless-login-cover.jpg` (y symlink en `public/storage/assets/stamless-login-cover.jpg`)
  4. En `PanelCmsProvider` (Studio) y `PanelPlatformProvider` (Platform), se actualizó la configuración de `AuthDesignerPlugin` para utilizar `asset('images/auth/stamless-login-cover.jpg')`.
  5. Se comprobó `object-fit: cover` nativo en `.fi-auth-media` de `auth-designer.css`.
- **Archivos:**
  - `public/images/auth/stamless-login-cover.jpg`
  - `storage/app/public/assets/stamless-login-cover.jpg`
  - `app/Providers/Filament/PanelCmsProvider.php`
  - `app/Providers/Filament/PanelPlatformProvider.php`
- **Verificación:** Pint limpio; suite de tests: **108 passed, 520 assertions**.

## 2026-09-15 — UI / Auth: Separador opaco (sin transparencia sobre la línea) y mayor separación vertical del botón Google
- **Pedido del Tech Lead:** "que no sea transparente por que la linea horizontal esta encima y separar mas el boton de google de la linea" con captura adjunta.
- **Implementación:**
  1. En `resources/views/vendor/filament-socialite/components/buttons.blade.php`, se eliminó `background-color: inherit` que causaba que el fondo del texto fuera transparente y la línea horizontal quedara visible cruzando las letras. Se implementó estructura sólida de capa (`relative z-10 bg-white dark:bg-gray-950 px-3.5`) que cubre 100% la línea horizontal por debajo del texto en modo claro y modo oscuro.
  2. Se aumentó la separación vertical del separador con `my-4` y se agregó margen superior `mt-3` al contenedor de los botones de login social (`gap-3 mt-3`), otorgando un respiro visual holgado y equilibrado con el botón de Google.
  3. En `resources/css/filament/cms/theme.css`, se actualizó `@source '../../../../resources/views/**/*';` para garantizar el escaneo de todas las vistas bajo `resources/views/` (incluyendo `vendor/`).
- **Archivos:**
  - `resources/views/vendor/filament-socialite/components/buttons.blade.php`
  - `resources/css/filament/cms/theme.css`
- **Verificación:** Pint limpio; suite de tests: **108 passed, 520 assertions**.

## 2026-09-15 — UI / Auth: Calibración de espaciado (`gap-y-5`, `mt-6`) y fondo adaptativo en separador de Social Login
- **Pedido del Tech Lead:** "aumentar el gap, y el fondo de "O inicia sesión con" que sea del mismo color de fondo".
- **Implementación:**
  1. En `resources/views/vendor/filament-socialite/components/buttons.blade.php`, se aumentó el margen superior a `mt-6` y el espaciado vertical a `gap-y-5` (y `gap-3` en el grid de botones).
  2. El separador de "O inicia sesión con" ahora utiliza `style="background-color: var(--fi-bg, inherit);"` y clases `bg-white dark:bg-gray-950`, fundiéndose de manera idéntica y sin bordes residuales con el color de fondo exacto del panel en modo claro y modo oscuro.
- **Archivos:**
  - `resources/views/vendor/filament-socialite/components/buttons.blade.php`
- **Verificación:** Pint limpio; suite de tests: **108 passed, 520 assertions**.

## 2026-09-15 — UI / Auth: Altura fija de 36px y colores sutiles por proveedor en Social Login + rediseño visual de Login
- **Pedido del Tech Lead:** "mantener los colores sutilmente de los providers y cambiar login" + "mantener a la misma altura de 36px el boton".
- **Implementación:**
  1. Se calibró la altura de todos los botones de login social a exactamente 36px (`height: 36px`, `h-[36px] min-h-[36px] max-h-[36px]`) en `resources/views/vendor/filament-socialite/components/buttons.blade.php`, alineados con la altura estándar de los botones de acción de Filament.
  2. Acentos y estilos de color sutiles por cada proveedor:
     - Google: ícono oficial multicolor SVG y hover sutil rojizo (`#EA4335/5`).
     - Microsoft: ícono oficial de 4 colores y hover azul suave (`#00A4EF/5`).
     - LinkedIn: ícono `#0A66C2` y hover sutil corporativo.
     - X: ícono en color texto nativo y hover suave neutral.
     - Instagram: ícono `#E4405F` y hover degradado/rosa suave.
     - Facebook: ícono `#1877F2` y hover azul sutil.
  3. En `PanelCmsProvider` (Studio): `AuthDesignerPlugin` configurado con `mediaPosition(MediaPosition::Left)`, imagen destacada (`cica360_media_slide2.webp`) y selector de tema claro/oscuro (`themeToggle()`).
  4. En `PanelPlatformProvider` (Platform): `AuthDesignerPlugin` con imagen de fondo completa `MediaPosition::Cover`, blur suave (3) y `themeToggle()`.
- **Archivos:**
  - `resources/views/vendor/filament-socialite/components/buttons.blade.php`
  - `app/Providers/Filament/PanelCmsProvider.php`
  - `app/Providers/Filament/PanelPlatformProvider.php`
- **Verificación:** Pint limpio; suite de tests completa: **108 passed, 520 assertions**.

## 2026-09-15 — Localización: Internacionalización al español (`es`) completa en autenticación y Socialite
- **Pedido del Tech Lead:** "todo tiene que estar en español".
- **Implementación:**
  1. Se actualizó `config/app.php` estableciendo por defecto `'locale' => env('APP_LOCALE', 'es')`, `'fallback_locale' => env('APP_FALLBACK_LOCALE', 'es')` y `'faker_locale' => env('APP_FAKER_LOCALE', 'es_ES')`.
  2. Se publicaron y tradujeron los archivos de idioma de `dutchcodingcompany/filament-socialite` en `lang/vendor/filament-socialite/es/auth.php` (traducción de "O inicia sesión con", mensajes de error de autenticación y avisos de registro deshabilitado).
  3. Filament y Auth Designer renderizan todos sus formularios, campos, botones ("Acceder", "Recordarme", etc.) y notificaciones 100% en español.
- **Archivos:**
  - `config/app.php`
  - `lang/vendor/filament-socialite/es/auth.php`
  - `tests/Feature/Filament/SocialLoginRenderTest.php`
- **Verificación:** Pint limpio; suite de tests: **108 passed, 520 assertions**.

## 2026-09-15 — Seguridad / Auth: Desactivación de auto-registro social (`registration(false)`) y registro público en paneles
- **Pedido del Tech Lead:** "por ahora no deberia permitir registrar con sociallogin, y en filament deberia estar desactivado el registro de usuarios de forma publica".
- **Implementación:**
  1. En `PanelCmsProvider` y `PanelPlatformProvider`, se configuró explícitamente `FilamentSocialitePlugin::make()->...->registration(false)`.
  2. Con esta configuración, el Social Login solo permite autenticarse a usuarios que ya hayan sido dados de alta previamente en el sistema (por el administrador o en seeders). Si un usuario que no existe intenta ingresar con Google u otra red, se rechaza la autenticación sin crear registros en `users`.
  3. Los paneles de Filament mantienen desactivado el registro público nativo (sin `->registration()`).
- **Archivos:**
  - `app/Providers/Filament/PanelCmsProvider.php`
  - `app/Providers/Filament/PanelPlatformProvider.php`
- **Verificación:** Pint limpio; suite de tests: **108 passed, 519 assertions**.

## 2026-09-15 — Configuración & OAuth: Forzado automático de esquema HTTPS (`URL::forceScheme('https')`)
- **Pedido del Tech Lead:** "deberia ser https" (con captura de `.env` configurado con `https://studio.stamless.host`, etc.).
- **Implementación:**
  1. En `AppServiceProvider::boot()`, se agregó la directiva `URL::forceScheme('https')` condicionada a cuando `APP_URL` o `APP_URL_STUDIO` utilicen `https://`.
  2. Esto garantiza que cualquier generación de URLs en la aplicación, rutas relativas y el `redirect_uri` generado por Laravel Socialite para Google OAuth siempre utilicen el protocolo seguro `https://` (`https://studio.stamless.host/oauth/callback/google`).
- **Archivos:**
  - `app/Providers/AppServiceProvider.php`
- **Verificación:** Pint limpio; suite de tests: **108 passed, 519 assertions**.

## 2026-09-15 — Autenticación: Soporte para 6 proveedores (Google, LinkedIn, X, Instagram, Facebook, Microsoft) con visibilidad condicional según credenciales en `.env`
- **Pedido del Tech Lead:** "mantener: google, linkedin, X, instagram, facebook, microsoft como providers pero mientras no tenga los tokens en el .env mantener oculto".
- **Implementación:**
  1. Se configuraron los 6 proveedores en `config/services.php` (`google`, `linkedin-openid`, `twitter-oauth-2`, `instagram`, `facebook`, `microsoft`) y sus variables correspondientes en `.env.example`.
  2. En `PanelCmsProvider` y `PanelPlatformProvider`, se registraron los 6 `Provider::make()` con sus respectivos íconos oficiales FontAwesome (`fab-google`, `fab-linkedin`, `fab-x-twitter`, `fab-instagram`, `fab-facebook`, `fab-microsoft`) y condición de visibilidad `->visible(fn (): bool => !empty(config('services.<provider>.client_id')) && !empty(config('services.<provider>.client_secret')))`.
  3. Si no hay credenciales en `.env`, ningún botón ni separador se muestra; cuando se agregan claves para uno o varios proveedores, solo esos se muestran de forma limpia y estilizada.
  4. Pruebas ampliadas en `tests/Feature/Filament/SocialLoginRenderTest.php` cubriendo renderizado oculto por defecto y visibilidad selectiva cuando se configuran credenciales.
- **Archivos:**
  - `config/services.php`
  - `.env.example`
  - `app/Providers/Filament/PanelCmsProvider.php`
  - `app/Providers/Filament/PanelPlatformProvider.php`
  - `tests/Feature/Filament/SocialLoginRenderTest.php`
- **Verificación:** Pint limpio; suite completa: **108 tests, 519 assertions, 100% pasando**.

## 2026-09-15 — Autenticación: Migración a `dutchcodingcompany/filament-socialite` y `owenvoke/blade-fontawesome`
- **Pedido del Tech Lead:** "a lo mejor es mejor dutchcodingcompany/filament-socialite".
- **Implementación:**
  1. Se reemplazó el paquete anterior por `dutchcodingcompany/filament-socialite` (^3.2) y se incorporó `owenvoke/blade-fontawesome` (^3.3) para renderizado nativo de íconos oficiales (`fab-google`, `fab-linkedin`, `fab-microsoft`).
  2. Se publicó y ejecutó la migración `create_socialite_users_table`.
  3. Se configuraron los proveedores en `config/services.php` (Google, GitHub, LinkedIn OpenID, Microsoft).
  4. Se integró `FilamentSocialitePlugin::make()->providers([...])->registration(true)` tanto en `PanelCmsProvider` (Studio) como en `PanelPlatformProvider` (Platform).
  5. Se eliminó código obsoleto y se agregaron pruebas automatizadas de renderizado único en `tests/Feature/Filament/SocialLoginRenderTest.php`.
- **Archivos:**
  - `config/services.php`
  - `database/migrations/2026_09_15_154507_create_socialite_users_table.php`
  - `app/Providers/Filament/PanelCmsProvider.php`
  - `app/Providers/Filament/PanelPlatformProvider.php`
  - `tests/Feature/Filament/SocialLoginRenderTest.php`
- **Verificación:** Pint limpio; suite de tests completa: **107 passed, 508 assertions**.

## 2026-09-15 — Autenticación: Solución a duplicación de botones de Social Login mediante render hook con scope de panel
- **Pedido del Tech Lead:** "esta duplicado los social" (con captura de la pantalla de login mostrando los botones de Google, LinkedIn y Microsoft repetidos).
- **Causa Raíz:** El paquete `matondojk/filament-social-login` registra el render hook `panels::auth.login.form.after` de forma global (`scopes: null`) en `FilamentView`. Al tener dos paneles registrados en la aplicación (`PanelCmsProvider` para Studio y `PanelPlatformProvider` para Platform), la llamada a `register()` se ejecutaba dos veces sin scope, provocando que ambos hooks se acumularan y renderizaran en cualquier pantalla de login.
- **Implementación:**
  1. Se creó `App\Filament\Plugins\FilamentSocialLoginPlugin` implementando `Filament\Contracts\Plugin` con registro de render hook explícitamente acotado por scope de panel (`scopes: $panel->getId()`).
  2. Se actualizaron `PanelCmsProvider` y `PanelPlatformProvider` para utilizar el plugin con scope propio, garantizando que cada panel renderice sus botones sociales exactamente una vez.
- **Archivos:**
  - `app/Filament/Plugins/FilamentSocialLoginPlugin.php`
  - `app/Providers/Filament/PanelCmsProvider.php`
  - `app/Providers/Filament/PanelPlatformProvider.php`
- **Verificación:** Pint limpio; suite de tests: **105 passed, 498 assertions**.

## 2026-09-15 — Studio (UX): SlideOver con Section full-width (`columnSpanFull`) para usuarios, selector enriquecido de roles y acción dedicada de cambio de contraseña en tabla
- **Pedido del Tech Lead:** "para crear usuarios al menos con dignidad aplicar mejor UX, y al editar que no permita cambiar contraseña desde ahi, si no desde el listado por si desea el admin tenant cambiar contraseña a sus sub usuarios" + "que se mantenga como overslide(), pero igual si no haces que sea el section fullwidth al body del modal estamos en problemas".
- **Implementación:**
  1. **UX de Creación y Edición de Usuarios (`UserResource.php` / `ManageUsers.php`):**
     - Se mantuvo el modo `slideOver()` para crear y editar colaboradores, añadiendo `->columnSpanFull()` explícito a `Section::make('Datos del colaborador')` para que ocupe el 100% del ancho del cuerpo del slide-over en lugar de colapsar al 50%.
     - `Section::make('Datos del colaborador')` con organización limpia: inputs con íconos de prefijo (`heroicon-m-user`, `heroicon-m-envelope`, `heroicon-m-lock-closed`).
     - Selector de roles migrado a `Radio` enriquecido con descripciones claras por cada rol (`Admin`: control total de Studio, `Editor`: gestión y publicación de contenidos, `Author`: redacción de borradores).
     - Contraseña con visibilidad y requerimiento acotado exclusivamente a la operación de creación (`visible(fn ($op) => $op === 'create')`).
  2. **Acción Dedicada para Cambiar Contraseña desde el Listado:**
     - Se eliminó el campo de contraseña del modal/slideOver de edición de usuario.
     - Se incorporó la acción de registro `Action::make('changePassword')->slideOver()` en la tabla con ícono de llave (`heroicon-m-key`), color warning y ancho `md`.
     - Formulario de cambio de contraseña con validación de confirmación (`same('password_confirmation')`), mínimo 8 caracteres y botón de visibilidad de clave (`revealable()`).
  3. **Suite de Tests:**
     - Actualizado `UserResourceTenantLimitTest.php` con tests para la acción `changePassword`, la edición segura de usuarios sin tocar contraseñas y límite de 3 usuarios para plan Auspicio.
- **Archivos:**
  - `app/Filament/Resources/UserResource.php`
  - `app/Filament/Resources/UserResource/Pages/ManageUsers.php`
  - `tests/Feature/Filament/UserResourceTenantLimitTest.php`
- **Verificación:** Pint limpio; suite de tests al 100%: **105 passed, 498 assertions**.

## 2026-09-15 — Spatie Roles & Permissions multi-tenant, Super Admin, Social Login, Auth Designer y Contadores de Caracteres en tiempo real
- **Pedido del Tech Lead:** "veo que no tiene implementado los roles y permisos de spatie (spatie/laravel-permission) con la implementacion del super usuario propietario de todo el sistema y el tipo usuario admin propietario del tenant y que puede si no es free, crear usuarios para delegar roles y permisos, pero si creo que esta pendiente eso me confirmas, y la gestion opcional de usuarios por tenant, falta social login https://filamentphp.com/plugins/matondo-social-login y cambiar el aspecto al diseño de la pagina de login: https://filamentphp.com/plugins/caresome-auth-designer contadores en los textareas donde vale la pena tener limite: schmeits/filament-character-counter" + "solo actualizar que ya no es: manager.genesisly.host para la gestion del multitenant, cuentas, pagos, planes y CMS headless en general ahora es platform.stamless.com y El Admin del Tenant accede a Studio (console.genesisly.host), pero ahora es studio.stamless.com" + "sigue me mismo orden de lista, procede".
- **Implementación:**
  1. **Spatie Roles & Permissions multi-tenant:**
     - Instalado `spatie/laravel-permission` (^8.3.0) con configuración `teams => true` y `team_foreign_key => 'tenant_id'`.
     - Migración de base de datos de permisos y columnas `is_super_admin`, `provider`, `provider_id`, `avatar_url` en la tabla `users`.
     - Modelo `User.php`: traits `HasRoles`, `HasTenants`, `HasAvatar`, fillables y helper `getFilamentAvatarUrl()`.
     - `AppServiceProvider.php`: registrado `Gate::before(fn ($user, $ability) => $user->is_super_admin ? true : null)` para bypass global de Super Admin en toda la plataforma.
     - `UserRoleEnum.php`: enum tipado para `Admin`, `Editor`, `Author` con labels y colores.
     - `Tenant.php`: método `maxUsers(): ?int` según el plan (`free`/`freemium` = 1, `sponsorship` = 3, etc.).
     - `PlanSeeder.php`: límites de `max_users` actualizados (Free=1, Auspicio=3).
     - `Cliente0Seeder.php`: asignación automática del rol `Admin` en el contexto del tenant de CICA360 (`setPermissionsTeamId($tenant->id)`).
  2. **Gestión de Usuarios en Studio (`UserResource.php` & `ManageUsers.php`):**
     - Recurso de Filament para gestión de usuarios acotado estrictamente a `tenant_id === Filament::getTenant()->id`.
     - Badges de límite de plan en el sidebar (`FormatsUsageBadge`), validación `isUserLimitReached()`, bloqueo en `CreateAction` con tooltip y notificación preventiva.
     - Sincronización automática del rol seleccionado con Spatie teams al crear el usuario.
     - `DeleteAction` con protección para impedir que el usuario autenticado se elimine a sí mismo.
  3. **Social Login (`matondojk/filament-social-login`):**
     - Instalado y publicado `config/filament-social-login.php`.
     - Registrado `FilamentSocialLoginPlugin` en `PanelCmsProvider` (Studio) y `PanelPlatformProvider` (Platform).
  4. **Auth Designer (`caresome/filament-auth-designer`):**
     - Registrado `AuthDesignerPlugin` con `mediaPosition: Left` en Studio y `mediaPosition: Cover` en Platform para una experiencia visual refinada en login y registro.
  5. **Contadores de Caracteres en tiempo real (`schmeits/filament-character-counter`):**
     - Reemplazados `TextInput` y `Textarea` por `CharacterTextInput` y `CharacterTextarea` en:
       - `PageResource.php`: Título SEO (60), Descripción SEO (160), Título OG (60), Descripción OG (160), Colophon descripción breve (120).
       - `PostResource.php`: Extracto / Resumen (300), Título SEO (60), Descripción SEO (160), Título OG (60), Descripción OG (160).
       - `ServiceResource.php`: Párrafo intro (300), Título SEO (60), Descripción SEO (160), Título OG (60), Descripción OG (160).
       - `TestimonialResource.php`: Testimonio / Frase (300 / 500).
  6. **Suite de Tests:**
     - Creado `tests/Feature/Filament/UserResourceTenantLimitTest.php` (6 tests nuevos) cubriendo aislamiento multi-tenant en tabla, límite de plan Free (1 usuario), límite de plan Auspicio (2 usuarios), aislamiento de roles entre equipos/tenants Spatie, bypass global de Super Admin y protección de auto-eliminación.
- **Archivos:**
  - `composer.json` & `composer.lock`
  - `config/permission.php`
  - `config/filament-social-login.php`
  - `database/migrations/2026_09_15_140010_create_permission_tables.php`
  - `database/migrations/2026_09_15_140036_create_social_login_fields_on_users_table.php`
  - `app/Models/User.php`
  - `app/Models/Tenant.php`
  - `app/Enums/UserRoleEnum.php`
  - `app/Providers/AppServiceProvider.php`
  - `app/Providers/Filament/PanelCmsProvider.php`
  - `app/Providers/Filament/PanelPlatformProvider.php`
  - `app/Filament/Resources/UserResource.php`
  - `app/Filament/Resources/UserResource/Pages/ManageUsers.php`
  - `app/Filament/Resources/PageResource.php`
  - `app/Filament/Resources/PostResource.php`
  - `app/Filament/Resources/ServiceResource.php`
  - `app/Filament/Resources/TestimonialResource.php`
  - `database/seeders/Cliente0Seeder.php`
  - `tests/Feature/Filament/UserResourceTenantLimitTest.php`
- **Verificación:** Laravel Pint aplicado en dirty files; suite de tests ejecutada al 100%: **103 passed, 477 assertions**.

## 2026-09-15 — Blindaje integral de Scope Tenant: aislamiento en Studio y API v1, soft-deletes slug uniqueness con sufijo en restore e is_home por tenant
- **Pedido del Tech Lead:** "como este stamless es un sistema B2C con tenant por cuenta o proyecto necesito todo sea independiente por tenant que un tenant no sepa del contenido que tienen el otro tenant, eso deberia estar resuelto, y a traves del api de igual forma con total medidas de seguridad para que cualquier cliente desde un origen puedan usar los endpoints seguros o privados, el Blindaje del Scope de Tenant debe de estar afinado para subira produccion" + "considerar dentro del plan los ajustes necesarios para que las tablas de contenidos (pages) cuando hablamos de slug unico no cuente con los softdeletes, solo unique a nivel de activos, y si restablecemos un softdelete que no permita con el mismo slug si no que agregue sufijos a ese slug" + "cuando se elige el is_home o si es pagina principal (home) hay una funcion que cambia el estado y desactiva el resto, solo uno debe ser home de los contenidos, pero es por tenant eso, que no desactive estado de otros contenidos de otros tenants".
- **Implementación:**
  1. `app/Models/Page.php`:
     - El hook `saving()` para desactivar la página `is_home` previa se acotó estrictamente al tenant del modelo (`$tenantId = $page->tenant_id ?? app(TenantManager::class)->getTenantId() ?? Filament::getTenant()?->id;` + `static::where('tenant_id', $tenantId)->where('id', '!=', $page->id)->update(['is_home' => false])`), asegurando que Tenant A jamás altere o desactive el `is_home` de Tenant B.
     - Hook `restoring()` automático: si al restaurar un registro papelereado ya existe otra página activa en ese tenant con el mismo slug, genera automáticamente un sufijo incremental (`-restaurado`, `-restaurado-2`, etc.) vía `generateUniqueRestoredSlug()`, evitando colisiones de integridad en la base de datos.
  2. `app/Filament/Schemas/HeadingFieldset.php`:
     - Reemplazo de `->unique(Page::class, 'slug')` por `->scopedUnique(model: $modelClass, column: 'slug', ignoreRecord: true, modifyQueryUsing: ...)` filtrado por `tenant_id` y `whereNull('deleted_at')`. `validSlug()` ahora evalúa aislamiento multi-tenant y exclusión de soft-deleted.
  3. `app/Filament/Resources/*`:
     - `PostResource.php`, `ServiceResource.php`, `SliderResource.php`, `MenuResource.php`: `duplicateSlug()` explícitamente acotado con `->where('tenant_id', $record->tenant_id)`.
     - Validaciones de slug cambiadas de `unique` plano a `scopedUnique` con `tenant_id`.
     - Replicación y creación de items hijos (`SliderResource::ReplicateAction`, `MenuResource::duplicateMenuItemsRecursive`, `TestimonialResource::beforeReplicaSaved`, `syncMenuTree`) con asignación explícita de `tenant_id`.
     - `PageResource.php`, `LinkSchema.php`, `MenuTreeBuilder.php`, `MediaUpload.php`: selectores de `parent_id`, `slider_id`, `form_id`, `menu_id`, `target_page_id`, `page_id`, `post_id`, `service_id` y preview de `Media` 100% acotados al `tenant_id` actual.
  4. `app/Filament/Pages/ApiTokens.php`:
     - Verificación de autorización en la acción `revoke`: `abort_unless($record->tokenable_type === User::class && $record->tokenable_id === auth()->id(), 403);`.
  5. `app/Http/Controllers/Api/V1/*`:
     - `MediaController.php` y `FormSubmissionController.php` reforzados con comprobación explícita `where('tenant_id', $tenant->id)` (defensa en profundidad).
  6. `tests/Feature/TenantIsolationTest.php`:
     - Suite completa de 12 tests con 51 aserciones cubriendo: aislamiento de scopes, asignación automática de tenant, middleware por header/parámetro, UUIDs, settings por tenant, `is_home` independiente entre tenants, soft-deletes slug coexistence, restauración automática con sufijos incrementales anti-colisión, coexistencia de slugs idénticos entre diferentes tenants, y seguridad/bloqueo de accesos cruzados de tokens o recursos en la API v1.
- **Archivos modificados:**
  - `app/Models/Page.php`
  - `app/Filament/Schemas/HeadingFieldset.php`
  - `app/Filament/Resources/PostResource.php`
  - `app/Filament/Resources/ServiceResource.php`
  - `app/Filament/Resources/SliderResource.php`
  - `app/Filament/Resources/MenuResource.php`
  - `app/Filament/Resources/TestimonialResource.php`
  - `app/Filament/Resources/PageResource.php`
  - `app/Filament/Schemas/LinkSchema.php`
  - `app/Filament/Forms/Components/MenuTreeBuilder.php`
  - `app/Filament/Schemas/MediaUpload.php`
  - `app/Filament/Pages/ApiTokens.php`
  - `app/Http/Controllers/Api/V1/MediaController.php`
  - `app/Http/Controllers/Api/V1/FormSubmissionController.php`
  - `tests/Feature/TenantIsolationTest.php`
- **Verificación:** Laravel Pint limpio (`fixed 8 files`), suite completa ejecutada: `97 passed, 450 assertions` (100% en verde).

## 2026-09-15 — Studio (UX): opciones agrupadas por tipo de contenido en modal "Copiar a otro contenido", label "Contenido destino" y blindaje de scope tenant
- **Pedido del Tech Lead:** "agrupar las opciones por tipo de contenido" + "que sea 'contenido destino' el label por que se esta entendiendo que como footer no es pagina por eso no se lista, por eso es mejor cambiar de termino para que se entienda mejor, la descripcion 'Sólo se listan contenidos  que aceptan este tipo de bloque'" + "no olvidar el scope tenant".
- **Implementación:**
  1. En `app/Filament/Resources/PageResource.php`, la acción pasa a llamarse "Copiar a otro contenido" (`modalHeading: 'Copiar bloque a otro contenido'`), el campo `target_page_id` pasa a label `'Contenido destino'` y helperText `'Sólo se listan contenidos que aceptan este tipo de bloque.'`.
  2. En `PageResource::getTargetPageOptionsForBlock(?Page $currentRecord, ?string $blockName): array`, se blindó la consulta con aislamiento estricto por tenant (`$tenantId = $currentRecord?->tenant_id ?? Filament::getTenant()?->id;` + `->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))`), asegurando que jamás se listen contenidos de otros tenants.
  3. En la ejecución del callback de `action()`, se verificó la existencia del registro destino restringido estrictamente al tenant actual antes de insertar los bloques.
  4. En `PageResource.php` (bloque `footer`) y en `PropertiesSchema.php` (campo `footer_page_id`), se aseguró también el scope por `tenant_id` en las queries de opciones de footer.
  5. En `tests/Feature/Filament/PageCopyToPageGroupedTest.php`, se añadió `test_target_page_options_strictly_respects_tenant_scope` verificando aislamiento multi-tenant cruzado entre dos tenants independientes.
- **Archivos:**
  - `app/Filament/Resources/PageResource.php`
  - `app/Filament/Schemas/PropertiesSchema.php`
  - `tests/Feature/Filament/PageCopyToPageGroupedTest.php`
- **Verificación:** Pint pasado (`vendor/bin/pint --dirty --format agent`), 90 tests pasando (`90 passed, 418 assertions`).

## 2026-09-15 — Studio (UX): fix de z-index y footer sticky en modales — el dropdown del selector ya no queda detrás del footer
- **Pedido del Tech Lead:** "al intentar hacer copia de un registro en paginas resource , el dropdown del select esta detras del footer del modal cuando debe estar detras" con captura `media_1789450094780.png` mostrando los botones "Copiar" / "Cancelar" del footer cortando y tapando las opciones del dropdown de "Página destino".
- **Causa raíz:**
  1. En `public/css/filament/api-console.css`, la regla de footer sticky introducida el 2026-09-02 usaba `.fi-modal-footer` genérico sin acotar a `.fi-modal-slide-over`, forzando `position: sticky !important; bottom: 0 !important; z-index: 50 !important;` y fondo sólido (`#18181b` in dark mode) en TODOS los modales del panel, incluidos los modales centrados de acción como "Copiar bloque a otra página".
  2. Los paneles flotantes de dropdown (`.fi-dropdown-panel`, usados por `Select` y menús) tienen `z-index: 20` por defecto en Filament. Al quedar por debajo de `z-index: 50`, el footer del modal se dibujaba por encima del listado de opciones desplegadas.
  3. `.fi-modal-content` tenía `padding-bottom: 4rem !important` global forzado para slide-overs, dejando un espacio innecesario en modales estándar.
- **Fix:**
  1. `public/css/filament/api-console.css`:
     - Se acotó el footer sticky (`position: sticky`, `z-index: 50`, fondos y `box-shadow`) y el `padding-bottom: 4rem` de `.fi-modal-content` exclusivamente a `.fi-modal.fi-modal-slide-over` / `.fi-modal-slide-over`.
     - Se añadió `.fi-dropdown-panel { z-index: 100 !important; }` para asegurar que cualquier dropdown de `Select` o menú flotante flote siempre por encima de footers, controles o barras fijas.
  2. `resources/css/filament/cms/theme.css`: se replicó la regla `.fi-dropdown-panel { z-index: 100 !important; }`.
- **Archivos:**
  - `public/css/filament/api-console.css`
  - `resources/css/filament/cms/theme.css`
- **Verificación:** PHPUnit 86/86 pasando (`86 passed, 395 assertions`), cache-busting automático por `filemtime()` en `PanelCmsProvider`.

## 2026-09-15 — Backend & Studio: selector de footer dinámico en Servicios (`properties.footer_page_id`), resolución en API y seeder inicial
- **Pedido del Tech Lead:** "en el admin, en la seccion de servicios deberia tener en su configuracion un propertie para elegir un selector de los footer que deseo que tenga, eso ayudará a poder devolver el footer elegido en el api de detalle de servicio, nos falta footer dinamico elegido en studio" + "agregar en el seeder como parte del contenido inicial el footer principal".
- **Implementación en Studio & Core:**
  1. `app/Filament/Schemas/PropertiesSchema.php`: componente `'footer_page_id'` (Select) para elegir entre registros de `Page` con `type = PageTypeEnum::Footer` del tenant, con helperText y placeholder 'Sin footer'.
  2. `app/Filament/Resources/ServiceResource.php`: sección "Pie de página (Footer)" en la pestaña "Configuración" de servicios usando `PropertiesSchema::make(['footer_page_id'])`.
  3. `app/Http/Concerns/ResolvesPublicLinks.php`: método `resolveFooterPage(?int $footerPageId)` que carga el Page tipo Footer por id (aislado por `TenantScope`), adjunta links resueltos y resuelve el contenido de los bloques hijos con anti-recursión (`resolveFooterBlocks: false`), retornando `['slug' => ..., 'blocks' => [...]]` con la misma estructura que `content.footer_page` de los bloques de página.
  4. `app/Http/Controllers/Api/V1/ServiceController.php`: en `show()`, resuelve el footer asignado en `$service->resolved_footer`.
  5. `app/Http/Resources/Api/V1/ServiceResource.php`: expone `'footer' => $this->resolved_footer ?? null` en la respuesta de detalle.
  6. `database/seeders/Cliente0ServicesSeeder.php`: busca `footer-principal` y asigna `properties.footer_page_id` en la creación de servicios y retroalimenta servicios existentes si está vacío.
  7. `database/seeders/DatabaseSeeder.php`: ejecuta `Cliente0ContentSeeder` antes que `Cliente0ServicesSeeder` para garantizar que `footer-principal` exista al sembrar los servicios.
  8. `tests/Feature/Api/V1/ServiceApiTest.php`: tests de aislamiento multi-tenant y resolución dinámica de footer en el endpoint de detalle de servicios (4 tests, 16 aserciones, pasando).
  9. `docs/api/v1.md` y `docs/api/openapi.v1.yaml`: documentación actualizada del endpoint `GET /services/{slug}` con el nuevo campo `footer`.
- **Archivos:**
  - `app/Filament/Schemas/PropertiesSchema.php`
  - `app/Filament/Resources/ServiceResource.php`
  - `app/Http/Concerns/ResolvesPublicLinks.php`
  - `app/Http/Controllers/Api/V1/ServiceController.php`
  - `app/Http/Resources/Api/V1/ServiceResource.php`
  - `database/seeders/Cliente0ServicesSeeder.php`
  - `database/seeders/DatabaseSeeder.php`
  - `tests/Feature/Api/V1/ServiceApiTest.php`
  - `docs/api/v1.md`
  - `docs/api/openapi.v1.yaml`
- **Verificación:** Laravel Pint limpio (`passed`), PHPUnit 86/86 pasando (`86 passed, 395 assertions`), seeder ejecutado con éxito en DB local vinculando el footer principal en todos los servicios.

## 2026-09-14 — Frontend: ancho máximo limitado a 1024px y textos en `cicagray-900` en "¿Por qué elegirnos?" y "Soluciones"
- **Pedido del Tech Lead:** "que su maximo de esos. dos partes sean con ancho limitado a 1024px" + captura de Figma (`media_1789448418252.png`) mostrando la caja seleccionada con `1024 x 160 adaptar` + "los textos es de cicagray-900".
- **Causa raíz:**
  1. Anteriormente se había configurado `max-w-3xl` (768px) en cada sección, lo que resultaba más angosto que la spec de diseño de Figma (1024px).
  2. Los párrafos descriptivos usaban `text-cicagray-600` (`#5D5D5D`) en lugar de `text-cicagray-900` (`#3D3D3D`).
- **Fix:**
  1. `cica360/src/pages/servicios/[slug].astro`:
     - Contenedor de ambas secciones actualizado a `max-w-[1024px]`.
     - Tarjeta "¿Por qué elegirnos?": `section` configurada con `w-full max-w-[1024px]`. Texto interior expandido a `max-w-4xl` para fluir holgadamente en 2 líneas exactamente como en Figma, y texto en `text-cicagray-900` (`[&_p]:text-cicagray-900`).
     - Bloque "Soluciones...": `section` configurada con `max-w-[1024px] w-full`. Descripción interior ajustada con `max-w-3xl` y color `text-cicagray-900`.
- **Archivos:** `cica360/src/pages/servicios/[slug].astro`.
- **Verificación:** TypeScript check limpio (`0 errors`).

## 2026-09-14 — Frontend: rediseño de "¿Por qué elegirnos?" y bloque "Soluciones" con foco-idea y flecha dorada
- **Pedido del Tech Lead:** "a continuacion por que elegirnos y el otro bloque soluciones, se necesita corregir acabado o estilos" + SVG del ícono light (foco-idea) y captura de expectativa Figma (`media_1789447536638.png`).
- **Causa raíz:**
  1. El bloque `why_choose_us` carecía de estilos de tarjeta: no tenía fondo `bg-cicaindigo-100` (`#E8E4ED`), esquinas redondeadas `rounded-2xl`, ni alineación centrada.
  2. El bloque `tip` ("Soluciones reales...") utilizaba un `<aside>` con borde gris (`border border-gray-200 bg-gray-50`) en lugar de estar integrado limpiamente sin marco sobre el fondo blanco, con el ícono de foco-idea a la izquierda del título, subtítulo centrado y la flecha dorada `ph:caret-down` animada inferior.
- **Fix:**
  1. `cica360/src/pages/servicios/[slug].astro`:
     - Tarjeta "¿Por qué elegirnos?": `section` con `max-w-3xl rounded-2xl bg-cicaindigo-100 px-6 py-8 sm:px-10 md:py-10 text-center`, título `text-xl sm:text-2xl md:text-3xl font-bold text-cicaindigo-500 mb-3 md:mb-4`, texto `.prose` centrado con `text-sm md:text-base leading-relaxed text-cicagray-600 font-light [&_strong]:font-semibold [&_strong]:text-cicaindigo-500`.
     - Bloque "Soluciones...": se eliminó la caja `<aside>`, implementando cabecera centrada con el SVG del foco-idea (con su contorno de lámpara complementario exacto para reflejar la expectativa) en `text-cicaindigo-500 size-6 sm:size-7`, título `text-base sm:text-lg md:text-xl font-bold text-cicaindigo-500`, descripción centrada en `text-sm md:text-base text-cicagray-600 font-normal`, y flecha `ph:caret-down` dorada (`text-cicagold-500 animate-bounce size-6`) con separación balanceada hacia el footer.
- **Archivos:** `cica360/src/pages/servicios/[slug].astro`.
- **Verificación:** TypeScript check limpio (`0 errors`).

## 2026-09-14 — Frontend: ampliación de padding horizontal de tabs a `3md:px-10` y `2xl:px-14`
- **Pedido del Tech Lead:** "a partir de 3md: (1024px) los tabs aumentar a px-10, a partir de 2xl: px-14 en adelante".
- **Causa raíz:** En pantallas grandes (>=960/1024px y >=1440px), el ancho horizontal `px-8` (32px) resultaba algo estrecho frente a la escala de la tarjeta y el viewport.
- **Fix:**
  1. `cica360/src/pages/servicios/[slug].astro`:
     - Se actualizó el padding horizontal de ambos botones de tabs (`¿Qué ofrecemos?` y `Coberturas`) con `px-8 3md:px-10 2xl:px-14`.
     - Conserva `px-8` (32px) en mobile/tablet, aumenta a `px-10` (40px) desde `3md:` (960/1024px) y a `px-14` (56px) desde `2xl:` (1440px+).
- **Archivos:** `cica360/src/pages/servicios/[slug].astro`.
- **Verificación:** TypeScript check limpio (`0 errors`).

## 2026-09-14 — Frontend: agregado de sombra `shadow-sm` en tarjetas del acordeón de "Coberturas"
- **Pedido del Tech Lead:** "falta el shadow md o sm, la segunda captura es la espectativa" (con capturas comparativas mostrando la ausencia de sombra frente a la expectativa con elevación).
- **Causa raíz:** Las tarjetas `<details>` carecían de clase de elevación/sombra, viéndose planas sobre el contenedor `bg-cicagray-50`.
- **Fix:**
  1. `cica360/src/pages/servicios/[slug].astro`:
     - Se agregó `shadow-sm` a cada `<details name="coverage-accordion" class="... shadow-sm ...">`, aportando el drop shadow sutil de 1px de offset y 3px de blur que coincide con la expectativa tanto en estado cerrado como abierto.
- **Archivos:** `cica360/src/pages/servicios/[slug].astro`.
- **Verificación:** TypeScript check limpio (`0 errors`).

## 2026-09-14 — Frontend: reducción de tamaño de texto en ítems del acordeón (default y activo) en mobile
- **Pedido del Tech Lead:** "noto muy grande los textos active y default , reducir un poco" + "en mobile" (con captura de mobile mostrando los ítems del acordeón con tamaño de texto 16px).
- **Causa raíz:** En `cica360/src/pages/servicios/[slug].astro`, el título de cada ítem del acordeón (`coverage.label`) dentro del `<summary>` carecía de clase de tamaño y heredaba `text-base` (16px), luciendo sobredimensionado en pantallas móviles en comparación con las viñetas y contenidos interiores (que ya usan `text-sm` / 14px).
- **Fix:**
  1. `cica360/src/pages/servicios/[slug].astro`:
     - Se agregó `text-sm md:text-base` al `<span>` del título del ítem (`{coverage.label}`), reduciendo a 14px en mobile (`text-sm`) tanto en estado default como en active (`group-open:`), y manteniendo 16px en desktop (`md:text-base`).
- **Archivos:** `cica360/src/pages/servicios/[slug].astro`.
- **Verificación:** TypeScript check limpio (`0 errors`).

## 2026-09-14 — Frontend: calibración pixel-perfect del acordeón de "Coberturas" (colores default/activo, fondo blanco y decorador dot SVG)
- **Pedido del Tech Lead:** "la realidad esta en la primera captura y la segunda captura tiene la espectativa, si queremos pixel perfect necesitamos los mismos detalles de color default y activo de cada item acordeon y su subcontenido con fondo blanco y si hay viñetas con el decorador de dot: [...] fondo de contenido es cicagray-50, fondo del item accordeon default es cicaindigo-50 y al hover cicaindigo-100 y active cicaindigo-200 y texto de los items de acordeon en active es: cicaindigo-500".
- **Causa raíz:**
  1. El contenedor general de tabs usaba `bg-cicaindigo-50` (`#F5F2F7`) en vez de `bg-cicagray-50` (`#F6F6F6`), lo que eliminaba el contraste natural con las tarjetas cerradas del acordeón.
  2. Cada `<details>` del acordeón usaba `bg-white` plano tanto cerrado como abierto, sin diferenciar el estado activo del default.
  3. No se contemplaba la interacción `hover:bg-cicaindigo-100` ni el cambio de color de texto a `cicaindigo-500` en activo.
  4. El icono de checkmark (`ph:check-circle-fill`) se mantenía siempre en dorado (`text-cicagold-500`), sin pasar a índigo (`text-cicaindigo-400`) al abrirse el ítem.
  5. La lista de subcoberturas usaba viñetas nativas de HTML (`list-disc`), en vez del SVG decorativo oficial de doble círculo proporcionado por el Tech Lead.
- **Fix:**
  1. `cica360/src/pages/servicios/[slug].astro`:
     - El contenedor de contenido de tabs pasa a `bg-cicagray-50` (`#F6F6F6`), coincidiendo 1:1 con el mockup `SERVICE-DETAIL-INCLUDE-TAB.jpg` y la captura de expectativa.
     - Cada `<details>` se configuró con `overflow-hidden rounded-xl bg-cicaindigo-50` y espaciado vertical `gap-2.5` (10px exactos).
     - `<summary>` aplica `bg-cicaindigo-50 hover:bg-cicaindigo-100` en estado default (cerrado) y `group-open:bg-cicaindigo-200 group-open:hover:bg-cicaindigo-200` (`#C6BCD4`) en estado activo (abierto).
     - Texto del ítem con `text-cicagray-700` por defecto y `group-open:text-cicaindigo-500 group-open:font-semibold` en activo.
     - El icono `ph:check-circle-fill` alterna dinámicamente de dorado a índigo con `text-cicagold-500 group-open:text-cicaindigo-400`.
     - El chevron `ph:caret-down` se estandarizó en `text-cicaindigo-400` con `group-open:rotate-180`.
     - El subcontenido interior tiene fondo blanco puro (`bg-white`), padding alineado con el texto del título (`px-4 pt-5 pb-8 pl-12 sm:px-6 sm:pl-14`) y rounded inferior automático por el `overflow-hidden` del padre.
     - Las viñetas de `coverage.items` reemplazan el `list-disc` nativo por el elemento SVG `width="16" height="16"` con doble círculo y `fill="#A298B8"`, alineadas con `gap-2.5 sm:gap-3` y `mt-1`.
- **Archivos:** `cica360/src/pages/servicios/[slug].astro`.
- **Verificación:** TypeScript check ejecutado y limpio (`0 errors`).

## 2026-09-14 — Frontend: reducción de tamaño de texto en viñetas de "¿Qué ofrecemos?" en mobile
- **Pedido del Tech Lead:** "en mobile no necesita los textos de las viñetas, un poquito reducir?" (con captura de mobile mostrando el listado de ofertas con saltos de línea y tamaño grande).
- **Causa raíz:** En `cica360/src/pages/servicios/[slug].astro`, el texto de las viñetas carecía de clase de tamaño y heredaba `text-base` (16px), luciendo sobredimensionado y provocando quiebres excesivos de línea en pantallas móviles.
- **Fix:**
  1. `cica360/src/pages/servicios/[slug].astro`:
     - Se aplicó `text-sm md:text-base leading-relaxed` al párrafo de cada viñeta, reduciendo a 14px en mobile (`text-sm`) con interlineado holgado, y conservando 16px en desktop (`md:text-base`).
- **Archivos:** `cica360/src/pages/servicios/[slug].astro`.
- **Verificación:** TypeScript check ejecutado y verificado.

## 2026-09-14 — Frontend: escala de padding en Y del contenedor de tabs (40px mobile / 60px tablet / 80px desktop)
- **Pedido del Tech Lead:** "el padding de los contenidos de los tabs tiene que tener padding en Y en mobile que sea py-10 (40px) y desde tablet que sea 60px y de desktop 3md: (1024px) a mas que sea 80px".
- **Causa raíz:** El contenedor de contenido (`rounded-2xl`) utilizaba `p-6 md:p-10` (24px mobile / 40px tablet+desktop), dejando una separación vertical insuficiente en pantallas medianas y grandes en comparación con el resto de los bloques de la página.
- **Fix:**
  1. `cica360/src/pages/servicios/[slug].astro`:
     - Se actualizó el padding del card a `px-6 md:px-10 py-10 md:py-[60px] 3md:py-20`, otorgando 40px en mobile (`py-10`), 60px desde tablet (`md:py-[60px]`) y 80px en desktop desde 960/1024px en adelante (`3md:py-20`).
- **Archivos:** `cica360/src/pages/servicios/[slug].astro`.
- **Verificación:** TypeScript check ejecutado y verificado.

## 2026-09-14 — Frontend: ajuste de padding horizontal de tabs a px-8 (32px)
- **Pedido del Tech Lead:** "bajamos a px-8".
- **Causa raíz:** Tras evaluar `px-9` (36px) y `px-6` (24px), se calibró el padding horizontal simétrico en `px-8` (32px / 2rem) para un equilibrio ideal de ancho de pestaña tanto en desktop como en mobile.
- **Fix:**
  1. `cica360/src/pages/servicios/[slug].astro`:
     - Se actualizó el padding horizontal de ambos botones (`¿Qué ofrecemos?` y `Coberturas`) a `px-8` (`inline-flex h-10 cursor-pointer items-center justify-center rounded-t-full px-8 text-sm md:text-[0.95rem]`).
- **Archivos:** `cica360/src/pages/servicios/[slug].astro`.
- **Verificación:** TypeScript check ejecutado y verificado.

## 2026-09-14 — Frontend: reubicación de línea horizontal a border-top del contenedor y ajustes responsivos de tabs
- **Pedido del Tech Lead:** "la linea horizontal que tiene actualmente, no deberia ser del div de tabs, si no el border top del contenedor de contenido, asi mantendra la espectativa con los bordes curvos a los extremos, en mobile si mantener el padding x de los tabs en px-6 y ls textos un poquito menos de tamaño" (con captura de DevTools sobre la barra de tabs).
- **Causa raíz:** La línea divisoria estaba colocada como `border-b-2` en el `div` de la fila de tabs, generando una línea recta plana que cortaba las esquinas curvas `rounded-2xl` del contenedor inferior. Además, en pantallas móviles el padding `px-9` resultaba demasiado ancho y el tamaño de texto requería optimizarse.
- **Fix:**
  1. `cica360/src/pages/servicios/[slug].astro`:
     - Se eliminó `border-b-2 border-cicaindigo-400` del `div` contenedor de los tabs.
     - Se agregó `border-t-2 border-cicaindigo-400` al contenedor de contenido (`rounded-2xl`), haciendo que la línea horizontal pertenezca al card y siga la curvatura natural de las esquinas en ambos extremos.
     - Se configuró padding horizontal responsivo: `px-6` en mobile y `md:px-9` en desktop (`px-6 md:px-9`).
     - Se ajustó el tamaño de texto responsivo: `text-sm` en mobile y `md:text-[0.95rem]` en desktop (`text-sm md:text-[0.95rem]`).
- **Archivos:** `cica360/src/pages/servicios/[slug].astro`.
- **Verificación:** TypeScript check ejecutado y verificado.

## 2026-09-14 — Frontend: ampliación de padding horizontal de tabs a px-9 (36px)
- **Pedido del Tech Lead:** "a cada tab cambiar el padding x de px-7 a px-9".
- **Causa raíz:** Las pestañas tenían `px-7` (28px de padding horizontal), quedando algo compactas para el radio completo superior de las píldoras de 40px de altura.
- **Fix:**
  1. `cica360/src/pages/servicios/[slug].astro`:
     - Se actualizó el padding horizontal de ambos botones (`¿Qué ofrecemos?` y `Coberturas`) de `px-7` a `px-9` (36px / 2.25rem), logrando mayor holgura y proporción con la curvatura de las esquinas.
- **Archivos:** `cica360/src/pages/servicios/[slug].astro`.
- **Verificación:** TypeScript check ejecutado y verificado.

## 2026-09-14 — Frontend: migración de tabs, acordeón e intro de detalle de servicio a Tailwind CSS 100% nativo
- **Pedido del Tech Lead:** "que raro que no se use tailwindcss".
- **Causa raíz:** En `cica360/src/pages/servicios/[slug].astro`, las pestañas (`.tab-row`, `.tab-trigger`), los acordeones (`.coverage-item`, `.coverage-chevron`) y el intro (`.richtext-body`) se habían implementado con reglas CSS tradicionales en el bloque `<style>`, cuando Tailwind v4 soporta de forma nativa variantes `aria-selected:`, `group-open:`, `rounded-t-full` y selectores arbitrarios hijos.
- **Fix:**
  1. `cica360/src/pages/servicios/[slug].astro`:
     - Tabs migrados a clases utilitarias de Tailwind: `inline-flex h-10 cursor-pointer items-center justify-center rounded-t-full px-7 text-[0.95rem] font-semibold transition-colors duration-150 bg-cicaindigo-50 text-cicaindigo-400 hover:bg-cicaindigo-100 aria-selected:bg-cicaindigo-400 aria-selected:text-white aria-selected:hover:bg-cicaindigo-400`.
     - Contenedor de fila de tabs migrado a `flex justify-center border-b-2 border-cicaindigo-400`.
     - Acordeón de coberturas migrado a `group`, `[&::-webkit-details-marker]:hidden` y `group-open:rotate-180 transition-transform duration-150`.
     - Intro rich text migrado a utilidades Tailwind `prose [&_p]:mb-3 [&_p:last-child]:mb-0 [&_strong]:font-bold [&_strong]:text-gray-800 [&_b]:font-bold [&_b]:text-gray-800`.
     - Se removieron todas las reglas CSS personalizadas correspondientes del bloque `<style>`, conservando únicamente la escala dinámica del hero basada en custom properties por breakpoint.
- **Archivos:** `cica360/src/pages/servicios/[slug].astro`.
- **Verificación:** TypeScript check ejecutado y verificado.

## 2026-09-14 — Frontend: tabs de detalle de servicio con altura de 40px y esquinas superiores redondeadas al 100%
- **Pedido del Tech Lead:** "los tabs tiene esquinas redondeadas al 100% esquinas superiores y de altura tiene 40px" (con recorte de Figma de la barra de tabs "¿Qué ofrecemos? / Coberturas").
- **Causa raíz:** `.tab-trigger` en `cica360/src/pages/servicios/[slug].astro` usaba un radio fijo de `1rem` (16px) y `padding-block: 1rem` al estar activo vs. `0.75rem` inactivo, lo que hacía variar la altura de las pestañas (~56px activo / ~44px inactivo) en vez de mantener los 40px exactos y simétricos del diseño oficial con radio superior completo.
- **Fix:**
  1. `cica360/src/pages/servicios/[slug].astro`:
     - Altura fija de 40px (`height: 40px`) con `display: inline-flex`, centrado vertical/horizontal y `padding-inline: 1.75rem` en `.tab-trigger`.
     - Esquinas superiores redondeadas al 100% (`border-radius: 9999px 9999px 0 0`), generando curvas suaves tipo semicírculo/pill en la parte superior y base plana sobre la línea.
     - Remoción de `padding-block: 1rem` en `[aria-selected='true']`, garantizando que tanto la pestaña activa como la inactiva conserven idéntica altura de 40px sin saltos de maquetación al alternar.
     - Contenedor del panel inferior actualizado a `rounded-2xl` simétrico en todas sus esquinas, alineado con la vista de tabs centrados.
- **Archivos:** `cica360/src/pages/servicios/[slug].astro`.
- **Verificación:** TypeScript check ejecutado y verificado.

## 2026-09-14 — Frontend: ajuste de padding inferior a 40px en sección intro de detalle de servicio
- **Pedido del Tech Lead:** "reducir a 40 padding bottom" (con captura de DevTools sobre el contenedor con `pb-10 md:pb-[60px]`).
- **Causa raíz:** En `cica360/src/pages/servicios/[slug].astro`, el contenedor de la introducción mantenía `pb-[60px]` en desktop, dejando un espacio inferior asimétrico respecto a los 40px superiores antes de los tabs.
- **Fix:**
  1. `cica360/src/pages/servicios/[slug].astro`:
     - Se ajustó el contenedor a `py-8 md:py-10` (`px-4 sm:px-6 py-8 md:py-10`), estableciendo un padding inferior simétrico de 40px (`md:py-10`) en desktop y 32px (`py-8`) en mobile.
- **Archivos:** `cica360/src/pages/servicios/[slug].astro`.
- **Verificación:** TypeScript check ejecutado y verificado.

## 2026-09-14 — Frontend: reducción de degradado superior en header de detalle de servicio (55% altura y fade al 20%)
- **Pedido del Tech Lead:** "reducir pero del header de detalle" (en respuesta a la consulta sobre si la capa se reducía a 60% y comenzaba a desvanecerse antes).
- **Causa raíz:** En `cica360/src/pages/servicios/[slug].astro`, el degradado cubría el 70% superior del banner con 35% de color sólido, sumado a un 25% de oscurecimiento general, lo que restaba luminosidad y calidez a la fotografía del hero.
- **Fix:**
  1. `cica360/src/pages/servicios/[slug].astro`:
     - Se redujo la altura del degradado de `h-[70%]` a `h-[55%]`.
     - Se adelantó el inicio del desvanecimiento a transparente del 35% al 20% (`linear-gradient(to bottom, #2D2C4D 0%, #2D2C4D 20%, color-mix(in srgb, #2D2C4D 0%, transparent) 100%)`).
     - Se ajustó la opacidad general a `0.85` y la capa base a `bg-cicaindigo-950/20`, asegurando que la parte central e inferior del header muestre la fotografía mucho más clara y nítida, manteniendo a la vez el contraste necesario detrás del navbar fijo (60px).
- **Archivos:** `cica360/src/pages/servicios/[slug].astro`.
- **Verificación:** `tsc --noEmit` en cica360.

## 2026-09-14 — Plataforma / Base de datos: incorporación del plan Auspicio en seeders y vinculación de suscripción de CICA360
- **Pedido del Tech Lead:** "algo que acabo de darme cuenta, en el seeder con el contenido base en stamless para el cliente0 que es cica360, deberia existir el plan Auspicio y con todo lo que se definio, espero que no esté hardcodeado o en enums, todo tiene que estar en tabla, deberia ser lo msmo que free, pero con un poco mas de recursos" / "tiene que estar en el seeder para poder desplegar como contenido inicial del gestor y del cliente0" (con capturas de las tablas `plans`, `tenants`, `tenant_modules`, `plan_module`, `plan_features` en el gestor de base de datos).
- **Causa raíz:** `PlanSeeder` únicamente sembraba el registro `free` en `plans` y sus características en `plan_features`. En consecuencia, la tabla `plan_module` solo contenía filas para `plan_id = 1` y `Cliente0Seeder::upsertSubscription()` asignaba forzosamente `slug: 'free'` a la suscripción de CICA360 a pesar de que el tenant tenía `plan = 'sponsorship'`.
- **Fix:**
  1. `database/seeders/PlanSeeder.php`:
     - Se añadió la definición y siembra del plan `Auspicio` (`slug: 'sponsorship'`) en la tabla `plans` (`name: 'Auspicio'`, `max_users: 2`, `max_pages: 30`, `max_posts: 20`, `max_storage_mb: 1000`, `is_free: true`, `is_active: true`, `sort_order: 1`).
     - Se registraron en `plan_features` todas las características y límites detallados tanto para `free` como para `sponsorship` (`max_users`, `max_pages`, `max_posts`, `max_services`, `max_testimonials`, `max_sliders`, `max_media`, `max_storage_mb`, `max_menus`, `max_menu_items`, `max_api_tokens`, `modules_vertical`, `custom_copyright`, `brand_personalization`).
  2. `database/seeders/ModuleSeeder.php`:
     - Sincronización de todos los módulos core (`pages`, `posts`, `media`, `menus`, `settings`, `sliders`, `contacts`) en la tabla pivote `plan_module` tanto para `free` como para `sponsorship`.
  3. `database/seeders/Cliente0Seeder.php`:
     - `upsertSubscription()` resuelve dinámicamente el plan por `$tenant->plan` (`sponsorship`), asociando la fila de la tabla `subscriptions` al id correspondiente del plan Auspicio.
  4. `app/Models/Tenant.php`:
     - Helpers `currentSubscription(): ?Subscription` y `planModel(): ?Plan` agregados para consultar el modelo de plan y suscripción en base de datos.
  5. `tests/Feature/PlanSeederTest.php`:
     - Suite con 3 pruebas que validan: existencia de ambos planes en `plans`, poblado completo de `plan_features`, asociación de módulos core en `plan_module` para ambos planes, y suscripción correcta de CICA360 al plan Auspicio.
- **Archivos:** `database/seeders/PlanSeeder.php`, `database/seeders/ModuleSeeder.php`, `database/seeders/Cliente0Seeder.php`, `app/Models/Tenant.php`, `tests/Feature/PlanSeederTest.php`.
- **Verificación:** `php artisan test --compact tests/Feature/PlanSeederTest.php` (3 passed, 16 assertions). Pint ejecutado y verificado.

## 2026-09-14 — Frontend: reducción de padding en sección intro de detalle de servicio (40px arriba / 60px abajo y reducción en X)
- **Pedido del Tech Lead:** "reducir el padding en X aprovechando que es una plantilla statica aislada al componente standar, reducir el padding de 80px a 40px arriba y 60px abajo" (con captura de DevTools sobre `div.mx-auto.max-w-[1280px].px-4.sm:px-6.lg:px-8.py-12.lg:py-20`).
- **Causa raíz:** La sección introductoria había heredado la escala fija del componente estándar `RichText.astro` (`py-12 lg:py-20` = 80px arriba y abajo, y `lg:px-8` = 32px en los laterales), dejando una separación vertical excesiva respecto a la ola del header y hacia los tabs.
- **Fix:**
  1. `cica360/src/pages/servicios/[slug].astro`:
     - Se ajustó el padding vertical del contenedor a `pt-8 md:pt-10 pb-10 md:pb-[60px]`: 40px superior (`md:pt-10`) y 60px inferior (`md:pb-[60px]`), tal como fue solicitado.
     - Se redujo el padding en X eliminando el escalón `lg:px-8` (32px), dejándolo en `px-4 sm:px-6` (16px/24px) para mayor fluidez horizontal.
- **Archivos:** `cica360/src/pages/servicios/[slug].astro`.
- **Verificación:** `tsc --noEmit` en cica360.

## 2026-09-14 — Frontend: gradiente superior y centrado del titular en header de detalle de servicio
- **Pedido del Tech Lead:** "en el heading del detalle la capa con gradiente es en la parte superior y el titular en medio (considerando la altura de navbar 60px que se sobrepone y eso deberia considerarse para definir el punto medio descontando esos 60px como se manejó el heading block) como está la espectativa (segunda captura)".
- **Causa raíz:** En `cica360/src/pages/servicios/[slug].astro`, el gradiente estaba posicionado abajo (`bottom-0 bg-gradient-to-t`) oscureciendo la base de la imagen en vez de la parte superior, y el contenedor del texto usaba `justify-end` con un padding inferior grande (`pb-12 md:pb-14`), empujando el titular y subtítulo contra la onda blanca.
- **Fix:**
  1. `cica360/src/pages/servicios/[slug].astro`:
     - Se reemplazó el gradiente inferior por el gradiente superior idéntico a `Heading.astro`: capa `top-0 h-[70%]` con `linear-gradient(to bottom, #2D2C4D 0%, #2D2C4D 35%, color-mix(in srgb, #2D2C4D 0%, transparent) 100%)` al 90% de opacidad. Esto garantiza contraste detrás del navbar fijo (60px) y legibilidad sobre el titular, dejando el tercio inferior de la fotografía despejado y luminoso sobre la onda blanca.
     - Se reemplazó `justify-end` por `justify-center` con compensación del navbar superior (`pt-[52px] 3md:pt-[60px] pb-0`), situando el bloque de texto en el punto medio geométrico del área visible debajo del navbar (mismo criterio de compensación implementado en `Heading.astro`).
- **Archivos:** `cica360/src/pages/servicios/[slug].astro`.
- **Verificación:** `tsc --noEmit` en cica360.

## 2026-09-14 — Frontend: incremento de altura de header de detalle de servicio (52vh/46vh/38vh/52vh, piso 250px)
- **Pedido del Tech Lead:** "nada falta un poco más aumentar el porcentaje vh".
- **Fix:**
  1. `cica360/src/pages/servicios/[slug].astro`:
     - Escala `vh` incrementada por breakpoint manteniendo piso de 250px:
       - Base (<540px): `52vh`, piso `250px`.
       - `sm` (>=540px): `46vh`, piso `250px`.
       - `md` (>=768px): `38vh`, piso `250px`.
       - `lg` (>=1080px): `52vh`, piso `250px`.
       - En 1366 x 660px, la altura pasa a ~343px (52vh de 660px), otorgando mayor protagonismo fotográfico al hero sin comprimir las cabezas de las personas con el navbar.
- **Archivos:** `cica360/src/pages/servicios/[slug].astro`.
- **Verificación:** `tsc --noEmit` en cica360 (sin errores).
- **Verificación:** `tsc --noEmit` en cica360 (sin errores).

## 2026-09-14 — Frontend: ajuste de altura del header en detalle de servicio (inspirado en Heading.astro +20% / piso 220px)
- **Pedido del Tech Lead:** "corregir o ajustar el header del detalle, cuando esta en 1366 x 660px, vemos que la altura es muy grande, podria ser 50px menos?, inpirate en el componente Heading que ya tiene todos los breakpoints definidos importantes a considerar y a eso aumentar 50px o en porcentaje proporcionalmente tipo un 20% adicional a cada altura definida., pero que no sea menor altura de 220px".
- **Causa raíz:** En `cica360/src/pages/servicios/[slug].astro`, `HEADER_HEIGHT_LG` tenía `vh: 50` y un piso fijo de `floorPx: 480`. En viewports de altura reducida como 1366 x 660px, el piso de 480px obligaba al header a ocupar el 73% de la altura total de la pantalla.
- **Fix:**
  1. `cica360/src/pages/servicios/[slug].astro`:
     - Escala de altura rediseñada a partir de los breakpoints de `Heading.astro` (`34vh` / `30vh` / `25vh` / `34vh`) con un +20% proporcional y un piso estricto de 220px (`floorPx: 220`):
       - Base (<540px): `41vh` (34 * 1.2), piso `220px`.
       - `sm` (>=540px): `36vh` (30 * 1.2), piso `220px`.
       - `md` (>=768px): `30vh` (25 * 1.2), piso `220px`.
       - `lg` (>=1080px): `41vh` (34 * 1.2), piso `220px`.
       - En 1366 x 660px, la altura pasa de 480px a ~271px (reducción de 209px), coincidiendo exactamente con la franja de `Heading + 20%` (269px) / `Heading + 50px` (274px).
     - Agregada media query `@media (min-width: 540px)` en el `<style>` para soportar el escalón `sm`.
     - Ajuste de paddings internos del header a `pt-16 pb-12 md:pb-14` para dar un respiro visual equilibrado tanto a 270px como al piso de 220px, sin colisionar con el navbar (60px) ni con la onda (wave).
- **Archivos:** `cica360/src/pages/servicios/[slug].astro`.
- **Verificación:** `tsc --noEmit` en cica360 (sin errores).

## 2026-09-14 — Frontend: alineación del párrafo introductorio de detalle de servicio con RichText.astro
- **Pedido del Tech Lead:** "mira como fue elaborado el componente para el tipo de bloque de texto enriquecido (captura 1), luego cuando entras a lo que se tiene en detalle de servicio esta mal, y necesito alinear lo mismo que se configuró tamaños y espaciados por cada breakpoints, deberia tener en detalle, por que es como si fuera el bloque de texto enriquecido que ahora estará en esta plantilla".
- **Causa raíz:** En `cica360/src/pages/servicios/[slug].astro`, el bloque introductorio (`content.intro`) utilizaba un contenedor genérico con `max-w-3xl` (768px), `text-cicagray-700`, `text-lg leading-relaxed` y `py-12 md:py-16`, forzando el texto en 5 líneas angostas en vez de respetar la escala granular de `RichText.astro` (que en Sobre CICA envuelve en 3 líneas amplias).
- **Fix:**
  1. `cica360/src/pages/servicios/[slug].astro`:
     - Contenedor exterior: `max-w-[1280px] px-4 sm:px-6 lg:px-8 py-12 lg:py-20` (alineado a `RichText.astro` en modo `boxed` y `padding_y: lg`).
     - Contenedor interior: `max-w-[960px] 3xl:max-w-[1100px] mx-auto flex flex-col gap-6 text-center items-center`.
     - Tipografía responsiva: `richtext-body prose w-full max-w-none font-normal text-base text-gray-600 md:text-sm 3md:text-base`, soportando HTML o texto plano.
     - Scroll indicator: integrado al stack flex con `mt-3` y `Icon name="ph:caret-down"` animado.
     - Estilos CSS: agregadas las reglas de espaciado para párrafos `.richtext-body :global(p)` (`margin-bottom: 0.75em`, `:last-child: 0`) y contraste de negritas `.richtext-body :global(strong), :global(b)` (`font-weight: 700`, `color: var(--color-gray-800)`).
     - Se eliminó el `-mt-10` artificial de la sección de tabs, permitiendo que la separación inferior hacia las tabs respete el ritmo visual del design system.
     - Header: tipografía de título (`text-[2rem] leading-tight font-black sm:text-[2.5rem] md:text-4xl lg:text-5xl`) y subtítulo (`text-base leading-snug font-light opacity-90 sm:text-lg sm:leading-normal`) alineada con `Heading.astro`.
- **Archivos:** `cica360/src/pages/servicios/[slug].astro`.
- **Verificación:** `tsc --noEmit` en cica360 (sin errores de tipos).

## 2026-09-14 — Corrección: desacople del decorador wave y las banderas flotantes en detalle de Service
- **Pedido del Tech Lead:** "en el admin stamless, tengo un propertie que tiene un check Mostrar detalle decorativo, al estar activo debe permitir mostrar el decorativo del header que son las banderitas y si no lo oculta, eso ya esta implementado pero resulta que se condicionó con todo el decorador wave del header, solo deberia ser las banderitas y no el decorador".
- **Causa raíz:** `[slug].astro` envolvía el SVG de la curva (`WAVE_PATH`) y las banderas dentro de un único bloque `{showDecorative && (...)}`. Si el toggle estaba apagado, la curva inferior desaparecía por completo dejando el corte recto contra la sección blanca.
- **Fix:**
  1. `cica360/src/pages/servicios/[slug].astro`: Se desacopló el SVG del decorador wave (ahora es permanente, siempre presente como transición hacia el cuerpo blanco) de las banderas (`service.countries`), las cuales quedan exclusivamente sujetas a `{showDecorative && service.countries.length > 0 && (...)}`. Además, se reforzó el overlay del hero con un tinte uniforme `bg-cicaindigo-950/35` y jerarquía tipográfica `tracking-tight` / `lg:text-[42px]` alineada con el mockup.
  2. `genesis/app/Filament/Schemas/PropertiesSchema.php`: Se actualizó el `helperText` de `show_decorative_detail` para aclarar que el toggle controla exclusivamente las banderas flotantes ("Banderas de país flotando sobre la curva del header. Desactivar para ocultar las banderas.").
- **Archivos:** `app/Filament/Schemas/PropertiesSchema.php` (genesis), `src/pages/servicios/[slug].astro` (cica360).
- **Verificación:** `vendor/bin/pint --dirty --format agent` (código 0), `tsc --noEmit` en cica360 (sin errores).

## 2026-09-14 — `Service` gana una 2da imagen opcional (`image_detail_id`) para el header del detalle
- **Pedido del Tech Lead:** "necesitamos agregar en admin al editar un servicio que no solo tenga una imagen si no dos, una normal como la que ya tiene y otra nueva mas panoramica como para el detalle, aplicar Ux para explicar el uso que le puedan dar de forma general los usuarios. y en el seeder duplicas la misma imagen en los dos atributos... en la website, usar la principal... para el header del detalle" si no hay secundaria. El docblock original de `create_services_table` (2026-08-31) ya dejaba esto anotado como decisión reversible ("separar en 2 campos queda para cuando el Tech Lead decida que catálogo y detalle necesitan crops distintos") — es ese momento.
- **Migración** `2026_09_14_160000_add_image_detail_to_services_table.php`: `image_detail_id`, `foreignId` nullable, FK a `media`, `nullOnDelete()` — mismo patrón que `image_id`. `image_id` NO se renombra (sigue siendo "la Principal"), cero migración de datos para servicios existentes.
- **`Service.php`:** `image_detail_id` sumado a `$fillable`; nueva relación `imageDetail(): BelongsTo`. El fallback "si `imageDetail` es null, usar `image`" NO vive en el modelo — es responsabilidad del frontend consumidor (mismo criterio que la cadena de `ogImage` en `cica360/[slug].astro`).
- **UX en Console (`ServiceResource.php`):** nueva `Section::make('Imágenes del servicio')` con `description()` explicando la relación entre ambas ("La Principal se usa como miniatura en el catálogo... La Secundaria es opcional y se usa en el header del detalle — subí ahí un formato más panorámico/apaisado... Si no cargás una Secundaria, el header del detalle usa la Principal"), más un `helperText` corto por campo (2 niveles de detalle, no 2 explicaciones distintas). `countries` se saca del `Grid::make(2)` que compartía con la imagen vieja y pasa a campo suelto `->columnSpanFull()`.
- **`MediaUpload.php` (helper compartido, 7 call sites):** gana un 4to parámetro opcional `?string $helperText = null`. Necesario porque el closure de `->helperText()` ya existente (aviso de límite de plan alcanzado) se hubiera PISADO por completo si el resource simplemente encadenaba `->helperText('mi texto')` después de `MediaUpload::make(...)` — con el parámetro, el closure combina los dos: el aviso de límite tiene prioridad (bloqueante), el texto propio del campo se muestra el resto del tiempo. Sin pasar el 4to argumento, comportamiento idéntico al de antes en los 6 call sites que no lo usan.
- **API pública:** `image_detail` nuevo en `Http\Resources\Api\V1\ServiceResource` (detalle, `GET /services/{slug}`) — a propósito NO se agregó a `ServiceSummaryResource` (catálogo, `GET /services`): el catálogo siempre usa `image`, nunca la secundaria. `ServiceController::show()` ahora hace `->with(['image', 'imageDetail'])` (antes solo `image`); `index()` sin cambios.
- **Seeder:** `Cliente0ServicesSeeder` — `image_detail_id` nuevo en el `firstOrCreate(...)`, mismo `image_file` que `image_id` (la MISMA imagen duplicada en los dos campos, tal como se pidió) — no es una imagen panorámica real todavía, el Tech Lead sube una propia por servicio desde Studio cuando corresponda.
- **Corrección tras confirmación visual del Tech Lead (misma vuelta):** el copy inicial de la `Section`/`helperText` usaba voseo rioplatense ("subí ahí...", "si no cargás..."), violando ADR-051 (español neutro para TODO copy de Console/Studio — el voseo es exclusivo del contenido de marca de CICA360, y esto es un campo de Console que ve cualquier tenant). Corregido a español neutro/infinitivo, y de paso se sacó una redundancia real: el `helperText` de cada campo repetía el fallback ("si no cargás una Secundaria, se usa la Principal") que la `description()` de la Section YA explica — ahora cada nivel dice algo distinto, no lo mismo 2 veces. Texto final: `description()` = "La imagen principal es la miniatura del catálogo. La secundaria es opcional, pensada para el header del detalle — si no se carga, el header usa la principal."; `helperText` de cada campo = solo el dato puntual de ESE campo ("Miniatura del catálogo de Servicios." / "Formato panorámico recomendado para el header del detalle."). Auditado de paso el resto del copy agregado por este agente en la sesión: encontrado y corregido 1 caso más de voseo, preexistente de la vuelta del header de detalle — `PropertiesSchema.php`, `helperText` de `show_decorative_detail` ("Desactivá" → "Desactivar").
- **2da corrección (captura de Studio mostrando el Toggle "apagado"):** el Tech Lead confirmó el comportamiento esperado ("si está desactivado el mostrar detalle decorativo entonces no mostrar las banderitas") — YA estaba bien implementado del lado del frontend (`showDecorative` en `[slug].astro` envuelve wave Y banderas en el mismo `{showDecorative && (...)}`, sin cambios necesarios ahí). La causa real de la confusión: el seeder nunca sembraba `properties` (quedaba `null` en las 9 filas), así que el `Toggle` de Studio hidrataba "apagado" al editar un servicio ya existente (el `->default(true)` de Filament solo aplica al CREAR desde el form, no a una fila ya sembrada) — mientras el frontend YA trataba "ausente" como "mostrar" (`!== false`). Mismatch puramente visual en Studio, las banderas SÍ se veían bien en el sitio. Fix: `Cliente0ServicesSeeder` ahora siembra `'properties' => ['show_decorative_detail' => true]` explícito en las 9 filas — Studio y el sitio quedan alineados desde el primer render. `header_type` NO se agregó (no fue pedido, y un `Select` vacío no genera el mismo engaño visual que un `Toggle` "apagado").
- **Archivos:** `database/migrations/2026_09_14_160000_add_image_detail_to_services_table.php` (nuevo), `app/Models/Service.php`, `app/Filament/Schemas/MediaUpload.php`, `app/Filament/Resources/ServiceResource.php`, `app/Filament/Schemas/PropertiesSchema.php`, `app/Http/Resources/Api/V1/ServiceResource.php`, `app/Http/Controllers/Api/V1/ServiceController.php`, `database/seeders/Cliente0ServicesSeeder.php`, `docs/api/v1.md`, `docs/api/openapi.v1.yaml`.
- **Cross-repo (cica360):** `src/lib/types.ts` (`Service.image_detail: Media | null`, ausente en `ServiceSummary`), `src/pages/servicios/[slug].astro` (`const headerImage = service.image_detail ?? service.image`, usado en el `<img>` de fondo del header). Ver PROGRESS.md de cica360.
- **Verificación:** sin runtime de PHP en este sandbox — balance de `{}()[]` confirmado por script en los 7 archivos PHP tocados (el único desbalance de paréntesis detectado en `ServiceResource.php` es PREEXISTENTE, confirmado comparando contra `git show HEAD:...` antes de este cambio, no introducido acá). **Falta correr la migración** (`php artisan migrate`) y re-sembrar (`php artisan db:seed --class=Cliente0ServicesSeeder`) en un entorno real — sin la migración, `image_detail_id` no existe todavía en la tabla `services` real.
- **Siguiente:** el Tech Lead corre la migración + reseed, y confirma en Studio que la Sección "Imágenes del servicio" se ve clara (2 campos, su UX explicada) y que el header del detalle en cica360 usa la imagen correcta según haya o no Secundaria cargada.

## 2026-09-14 — FIX real: `Cliente0ServicesSeeder` sobreescribía contenido real de Studio (`updateOrCreate` → `firstOrCreate`)
- **Reportado en vivo por el Tech Lead:** "no veo el texto..." (faltaba el párrafo introductorio real de "Seguro Financiero" en el detalle público). Confirmó que corrió `Cliente0ServicesSeeder` después de que este agente le sumara `offers`/`coverages` al archivo (misma sesión, entrada de arriba).
- **Causa raíz:** el seeder usaba `Service::updateOrCreate(['tenant_id', 'slug'], [...])` — esto UPDATEA incondicionalmente `title`/`subtitle`/`countries`/`content`/`image_id`/`sort_order` de la fila existente con los valores hardcodeados del array `$services`, cada vez que el seeder corre. "Seguro Financiero" ya tenía contenido real cargado a mano en Studio (`subtitle`: "Protegemos tu patrimonio, simplificamos tu gestión.", `countries`: Argentina+Uruguay, `content.intro`: "En CICA ofrecemos coberturas generales...") que DIVERGÍA de los valores viejos que tenía el archivo del seeder (de la "2da vuelta", 2026-09-11) — correr el seeder pisó ese contenido real sin avisar.
- **Fix:**
  1. `Service::updateOrCreate(...)` → `Service::firstOrCreate(...)` — busca solo por `[tenant_id, slug]`; si el servicio YA existe, lo deja intacto (no lo toca); solo aplica los valores del array la PRIMERA vez, cuando el servicio todavía no existe. Aplica a los 9 servicios del dataset, no solo a "Seguro Financiero".
  2. `subtitle`/`countries`/`content.intro` de "Seguro Financiero" en el archivo se restauraron a los valores reales (a partir de las mismas capturas de CICA360 que motivaron la entrada de `offers`/`coverages`), para que el archivo quede como referencia fiel — aunque de acá en más `firstOrCreate` no lo va a volver a tocar en un servicio ya existente.
- **Lo que NO se tocó (riesgo latente, documentado en el docblock de la clase):** el paso de poda al final (`Service::where(...)->whereNotIn('slug', $slugs)->delete()`) sigue corriendo sin importar `firstOrCreate` — si en Studio se crea un servicio real con un slug fuera de esta lista de 9, este paso lo BORRARÍA. No es el bug reportado hoy (que era de sobreescritura, no de borrado), pero es un riesgo equivalente que el Tech Lead debería tener presente antes de volver a correr este seeder.
- **Archivos:** `database/seeders/Cliente0ServicesSeeder.php`.
- **Pendiente — acción inmediata del Tech Lead:** `firstOrCreate` NO repara la fila que YA está mal en la base ahora mismo (solo previene el problema a futuro) — hay que corregir "Seguro Financiero" a mano una sola vez, directo en Studio (`subtitle`, país AR+UY, `content.intro`) o vía `tinker` (ver mensaje de chat de este agente con el comando exacto).
- **Verificación:** balance revisado a mano (sin runtime PHP en este sandbox).

## 2026-09-14 — `Service`: nuevo header de detalle (properties `header_type`/`show_decorative_detail`)
- **Pedido del Tech Lead:** capturas reales del detalle de "Seguros Financiero" en CICA360, spec detallada: "primero el header es mas alto a la mitad de altura (50vh), con esa misma capa en gradiente a la 50% de altura del header, el decorador wave y las banderas flotantes sobre ese lado del wave, la properties son importantes para los cambios de tamaño del header podrian ser de Tipo header: Normal | Destacado... otra property podria ser un check Mostrar detalle decorativo... luego se muestra el parrafo introductorio que tiene el mismo aspecto del componente de texto enriquecido con su flechita de scroll... y a continuacion tiene tabs en el detalle, que se sobreponen al anterior tipo -mt-10... usar los mismos iconos y viñetas fijas".
- **Alcance del lado genesis (backend):** solo 2 decisiones quedaron como `properties` configurables desde Console, el resto del look (imagen, degradado, wave, banderas, tabs) es fijo y vive en el frontend:
  - `properties.header_type`: `normal` (default) | `destacado` — `PropertiesSchema.php`, nuevo `Select`.
  - `properties.show_decorative_detail`: booleano, default `true` — apaga wave + banderas flotantes como conjunto único (no un flag por elemento).
  - `ServiceResource.php`: nueva sección "Header del detalle" (colapsada) en el tab "SEO / Enlaces", con esos 2 campos a 2 columnas.
  - No hizo falta tocar `ServiceController`/`ServiceResource` (API): `properties` ya se expone completo (`self::asObject($this->properties)`), sin cambios de contrato.
- **Archivos:** `app/Filament/Schemas/PropertiesSchema.php`, `app/Filament/Resources/ServiceResource.php`.
- **Cross-repo (cica360):** la parte pesada de este pedido (header full-bleed con gradiente/wave/banderas, párrafo intro estilo RichText, tabs "¿Qué ofrecemos?"/"Coberturas" superpuestas `-mt-10`) se implementó en `src/pages/servicios/[slug].astro` — ver `PROGRESS.md` de cica360 para el detalle completo, incluye la escala de altura por breakpoint diseñada para esta vuelta (sin precedente previo en el sitio) y la verificación real con el compilador de Astro + `tsc` (disponibles en el sandbox de este agente, a diferencia de PHP).
- **Verificación:** balance de llaves/paréntesis revisado a mano (sin runtime PHP). Pendiente que el Tech Lead confirme visualmente el campo "Header del detalle" en Studio y pruebe "Destacado"/"Mostrar detalle decorativo" contra el resultado real en CICA360.
- **Siguiente:** revisión visual fina en un entorno real de los valores exactos de la escala de altura (ver nota en `[slug].astro`) — quedaron como criterio propio razonado, no una spec pixel-perfect del Tech Lead.

## 2026-09-14 — `services.content.why_choose_us.text`: `Textarea` → `RichEditor` (WYSIWYG)
- **Pedido del Tech Lead:** capturas del form de Console vs. el render real en CICA360 ("Porque **integramos en un solo equipo**..." con negrita a mitad de frase) — "creo que el texto de por que elegirnos debe ser wysywyg... tal vez no sea necesario las properties [color de fondo, redondez] a ese nivel pero el soporte de richeditor creo que si". Alcance acotado a un solo campo, a propósito: no se agregan `properties` de estilo a nivel de sección.
- **Qué se hizo:**
  - `ServiceResource.php` (Filament): `content.why_choose_us.text` ahora es `Forms\Components\RichEditor` (antes `Textarea`). Como `Service::$casts()['content'] = 'array'` (jsonb), Filament 5 lo persiste como documento TipTap/JSON — mismo comportamiento ya conocido de `content.body` en los bloques `rich_text`/`split`/`legal_notice` de `PageResource.php`.
  - `ResolvesPublicLinks::renderRichContent()` pasó de `private` a `protected` — un método `private` de un trait no es heredable por una clase que lo consume vía su padre (`ServiceController extends Controller`, y es `Controller` quien hace `use ResolvesPublicLinks`), así que hacía falta ampliar la visibilidad para poder reusarlo fuera del trait. Comportamiento sin cambios (blindado: un `string` plano pasa tal cual, sin reprocesar).
  - `ServiceController::show()`: convierte `content.why_choose_us.text` de JSON TipTap a HTML sanitizado antes de responder — mismo mecanismo ya usado para `content.body` de bloques, evitando el bug ya conocido de `[object Object]` en el front.
  - `docs/api/v1.md` + `docs/api/openapi.v1.yaml`: de paso se corrigió una inexactitud **preexistente** (no introducida en esta vuelta) — `why_choose_us`/`tip` estaban documentados como strings sueltos, cuando en realidad siempre fueron objetos `{ title, text }` (ver `ServiceResource.php` desde que se creó el módulo). Se agregó la nota de la excepción de `why_choose_us.text` como HTML.
- **Archivos (genesis):** `app/Filament/Resources/ServiceResource.php`, `app/Http/Controllers/Api/V1/ServiceController.php`, `app/Http/Concerns/ResolvesPublicLinks.php`, `docs/api/v1.md`, `docs/api/openapi.v1.yaml`.
- **Cross-repo (cica360):** `src/pages/servicios/[slug].astro` (ese `<p>{content.why_choose_us.text}</p>` interpolado escapaba el HTML — se cambió a `set:html` con clases `prose`, mismo patrón que `content.body` en `RichText.astro`/`Split.astro` y `post.content` en `blog/[slug].astro`), `src/lib/types.ts` (doc comment en `ServiceContent.why_choose_us`), `docs/context/api/stamless-api-v1.md`. Ver `PROGRESS.md` de cica360 para el detalle de ese lado.
- **Verificación:** balance de llaves/paréntesis revisado a mano (sin runtime PHP en el sandbox de este agente). Pendiente que el Tech Lead confirme visualmente en Console que el RichEditor guarda/recupera bien el contenido ya existente de "Seguro Financiero" (texto plano legado — `renderRichContent()` lo deja pasar tal cual, no debería romper) y que el front de CICA360 renderiza la negrita correctamente tras el build.
- **Siguiente:** el Tech Lead mencionó que la documentación general del API "no sé si... está actualizada con todo lo último que se implementó" (`/api-documentation` en Studio, fuente `docs/api/v1.md`) — quedó pendiente, a retomar en otra sesión, una auditoría completa (no solo este campo puntual).

## 2026-09-14 — `Cliente0ServicesSeeder`: contenido completo de demo para "Seguro Financiero"
- **Pedido del Tech Lead:** capturas reales del detalle de "Seguros Financiero" en CICA360 (banner, tabs "¿Qué ofrecemos?"/"Coberturas", "¿Por qué elegirnos?", tip) + "generar el seeder... la información completa para Seguro financiero... en el tab coberturas cada item del acordeón podrías rellenar con 3 a 10 sub items de forma aleatoria como demo para ver el funcionamiento de todo el acordeón". Aviso mid-turn ("nos olvidamos del detalle de los servicios") aclaró que el registro base (`title`/`subtitle`/`countries`/`content.intro`, con foto propia en `Cliente0MediaSeeder`) ya existía en el seeder de la 2da vuelta — faltaba solo completar los 4 campos opcionales de `content`.
- **Qué se hizo:** `Cliente0ServicesSeeder.php` — solo la entrada "Seguro Financiero" (no se tocó ningún otro de los 9 servicios) ahora trae:
  - `content.offers`: los 7 puntos reales de la captura (`highlight`+`text`).
  - `content.coverages`: 15 rubros del acordeón. "Automotores" con el detalle real entregado (4 ítems); el resto (Hogar, Vida colectivos, Comercio e Industria, ART, Mala praxis, Transporte, Aeronavegación, Embarcaciones de placer, Riesgo agrícola, Seguro de incendios, Accidentes personales, Consorcios, Seguro de caución, Garantía propietaria) con 3 a 8 sub-ítems inventados como demo, variando la cantidad a propósito para probar el acordeón con listas de distinto largo.
  - `content.why_choose_us` y `content.tip`: texto real de la captura.
  - El loop del seeder ahora arma `content` con `array_merge()` + `array_intersect_key()` sobre 4 keys opcionales (`offers`/`coverages`/`why_choose_us`/`tip`) — si un servicio no las trae (los otros 8, a propósito), `content` queda igual que antes (solo `intro`).
- **Archivos:** `database/seeders/Cliente0ServicesSeeder.php` (docblock de clase actualizado con la nota de esta 3ra vuelta parcial).
- **Verificación:** balance de llaves/paréntesis revisado a mano línea por línea (sin runtime PHP en el sandbox de este agente) — estructura OK. **Pendiente que el Tech Lead corra** `php artisan db:seed --class=Cliente0ServicesSeeder` en un entorno real (es idempotente vía `updateOrCreate`, no duplica ni rompe el resto del catálogo) y confirme visualmente que el acordeón de "Coberturas" y la lista de "¿Qué ofrecemos?" rinden bien en Studio y en el front de CICA360.
- **Siguiente:** el resto de los 8 servicios queda sin `offers`/`coverages`/`why_choose_us`/`tip` a propósito — el Tech Lead los completa manualmente desde Studio cuando tenga el contenido real de cada rubro.

## 2026-09-14 — ADR-067: `MediaResource` oculto para Free/Freemium/Auspicio
- **Pedido del Tech Lead:** "creo que multimedia lo ocultaremos para free y aspicios, que quede el limite de alguna forma avisar pero no tendran acceso, por que actualmente si yo adjunto un archivo en contenido o servicios, no hay forma de reutilizar una foto, poder cambiarla con alguno de la galeria, solo hay opciona a editarla y a eliminarla". Cambio de modelo freemium → nuevo ADR obligatorio (regla de `CLAUDE.md`). Antes de tocar código se confirmaron con el Tech Lead 3 puntos ambiguos vía preguntas: (1) bloqueo real (nav + URL directa, no solo ocultar el nav), (2) el aviso del límite vive SOLO en el widget de uso del Dashboard (no un candado en el nav), (3) cerrar también el hueco de `MediaUpload` (subida inline), que hasta ahora no chequeaba el límite.
- **Qué se hizo:**
  - `Tenant::canAccessMediaLibrary(): bool` — nuevo gate (`! isFreeTier()`), método propio en vez de reusar `isFreeTier()` directo en `MediaResource` (mismo criterio ya documentado en `canPersonalizeStudioBrand()`).
  - `MediaResource::canAccess()` — override que combina `parent::canAccess()` (autorización estándar de Filament) con `canAccessMediaLibrary()`. Filament usa este único método tanto para ocultar el ítem del nav (`HasNavigation`) como para el `abort_unless(..., 403)` al entrar por URL directa a cualquier página del resource — bloqueo real con un solo cambio.
  - `MediaResource::mediaLimitMessage()` bifurcado: quien YA no tiene acceso a la biblioteca ve "mejorá de plan" en vez de "eliminar primero alguno existente" (instrucción que ya no puede seguir).
  - `App\Filament\Schemas\MediaUpload::make()` (el campo de subida inline de Páginas/Posts/Servicios/Sliders/Testimonios) ahora también hace cumplir el límite — `->disabled()`/`->helperText()` reusando `MediaResource::isMediaLimitReached()`/`mediaLimitMessage()`, sin duplicar lógica. Cierra un hueco real: antes de esta vuelta, este campo NO chequeaba el límite en absoluto.
  - `PlanUsageWidget::getRows()`: la fila "Multimedia" pasa `url: null` cuando el tenant no tiene acceso (en vez de apuntar a una página que le daría 403). `plan-usage-widget.blade.php` renderiza esa fila como `<div>` informativo (sin `href` ni hover) en vez de `<a>` cuando `url` es `null` — queda como el ÚNICO lugar donde Free/Freemium/Auspicio sigue viendo su conteo/tope.
- **Trade-off aceptado, documentado explícitamente en el ADR, NO resuelto acá:** un tenant afectado que llega a su tope ya no tiene ningún camino de autoservicio para borrar archivos viejos y liberar cupo (antes podía entrar a `MediaResource` y borrar algo) — su única salida es mejorar de plan. Motivo de fondo de toda la decisión, también documentado: `MediaUpload` no tiene forma de REUTILIZAR un archivo ya subido desde otro campo, solo subir nuevo o editar/quitar el actual — construir ese selector queda pendiente para una vuelta futura.
- **Archivos:** `app/Models/Tenant.php`, `app/Filament/Resources/MediaResource.php`, `app/Filament/Schemas/MediaUpload.php`, `app/Filament/Widgets/PlanUsageWidget.php`, `resources/views/filament/cms/widgets/plan-usage-widget.blade.php`. ADR-067 en `DECISIONS.md`.
- **Verificación:** balance de `(){}[]` OK en los 4 archivos PHP (script Python — nota: `Tenant.php` dio un falso positivo por el `//` dentro del string `'https://'` en `publicUrl()`, método preexistente sin relación; revisado a mano línea por línea, 16/16 bloques `/** */` correctamente emparejados). Balance de tags Blade OK (`@if`/`@else`/`@endif` 1/1/1). Sin runtime PHP en este sandbox — pendiente que el Tech Lead confirme visualmente: (a) el ítem "Multimedia" ya no aparece en el nav para un tenant Free/Auspicio, (b) una URL directa a `/media` da 403 para ese mismo tenant, (c) la fila "Multimedia" del widget de Escritorio ya no es clickeable para ese tenant, (d) un campo de imagen en Páginas/Servicios se deshabilita al llegar al tope de medios.
- **Siguiente:** evaluar si vale la pena construir un selector "elegir de la Biblioteca" dentro de `MediaUpload` — la mejora que, de resolverse, haría sentido reevaluar esta restricción de plan.

## 2026-09-13 — MediaResource: vista de galería con `contentGrid()` nativo de Filament
- **Pedido del Tech Lead:** "no me convence un simple listado CRUD, cuando debería ser una galería más visual, más UX" — trajo 4 plugins de la comunidad Filament como propuesta (UniFileManager, Ardavan File Explorer, mwguerra/filemanager, marcomessa/filament-file-manager).
- **Investigación:** los 4 plugins fueron descartados tras revisar su documentación oficial — cada uno propone su PROPIO inventario de archivos (tabla propia de metadata, o directamente exigen migrar a Spatie Media Library), ninguno lee/escribe sobre la tabla `media` de este proyecto. Adoptar cualquiera hubiera significado mantener 2 sistemas de media en paralelo, o replatear cada FK `media_id` que ya usan Páginas/Posts/Servicios/Slides y el fallback SEO/OG de ADR-065 — un cambio de arquitectura, no de UI. Riesgos adicionales encontrados: 3 de los 4 son proyectos muy chicos/nuevos (3-12 estrellas en GitHub, alguno con el primer commit hace días), y el más simple (marcomessa) deja S3/R2 — el disco que ya usamos, ADR-004 — como función de pago (PRO).
- **Qué se hizo:** en cambio, `MediaResource::table()` pasa a usar `Table::contentGrid()` (100% nativo de Filament, sin dependencias nuevas) — el índice ahora renderiza tarjetas (1 a 5 columnas según viewport) con preview grande (`ImageColumn` a 160px de alto, antes solo la primera columna de una fila angosta) en vez de una tabla de filas. Mismo modelo `Media`, mismo multi-tenancy, mismas relaciones/FKs — ningún dato ni endpoint cambia, solo la presentación del índice. Paginación por página baja de 50 a 24 (más prolijo contra una grilla de 2-5 columnas). El resto queda intacto: límite de plan (`isMediaLimitReached()`), badge de uso en el sidebar, filtro por disco, acciones agrupadas (Editar/Eliminar), bulk delete.
- **Archivos:** `app/Filament/Resources/MediaResource.php`.
- **Verificación:** balance de `(){}[]` OK (script Python). Sin runtime PHP en este sandbox — no se pudo abrir el panel para confirmar visualmente el layout de tarjetas ni que `contentGrid()`/`ImageColumn::height()` rindan como se espera en Filament 5.x (API confirmada por búsqueda en la documentación oficial de Filament, no ejecutada localmente).
- **Siguiente:** el Tech Lead corre `php artisan filament:assets` (por las dudas, aunque `contentGrid()` es puramente PHP/Blade, sin JS/CSS nuevo) y confirma visualmente el grid en `/admin/{tenant}/media`.
- **2da vuelta (mismo día, fix real con captura: "se ve horrible... mejor más pequeño... al editar es peor falta UX, fullwidth al body del modal"):** confirmado el bug — la 1ra vuelta armó `contentGrid()` pero dejó las 3 columnas (imagen/nombre/tamaño) sueltas; sin agruparlas, Filament las sigue acomodando en fila horizontal DENTRO de cada celda de la grilla (el comportamiento por defecto de cualquier tabla), no apiladas como una tarjeta — de ahí la fila angosta gigante que mostró la captura en vez de una foto con texto debajo. Fix: las 3 columnas se envuelven en `Tables\Columns\Layout\Stack::make([...])`, el componente de Filament para apilar columnas verticalmente dentro de una celda de `contentGrid()` (no existe un "modo card" separado — es `contentGrid()` + `Stack` juntos, y la 1ra vuelta se quedó a mitad de camino). Miniatura baja de 160 a 120px y gana `->square()` (antes solo `height()` sin forzar proporción — con imágenes panorámicas tipo 1200×630 quedaban angostas y elongadas). Grid pasa de `default:1/sm:2/md:3/lg:4/xl:5` a `default:2/sm:3/md:4/lg:5/xl:6` (tarjetas más chicas, más por pantalla). Modal de Editar/Crear (`modalWidth`) sube de `md` a `2xl` — el preview del `FileUpload` con una imagen real cargada quedaba cortado/superpuesto en la caja angosta.
- **Archivos:** `app/Filament/Resources/MediaResource.php` (mismo archivo, 2da pasada).
- **Verificación:** balance de `(){}[]` OK. Sigue sin poder confirmarse visualmente en este sandbox (sin runtime PHP) — el patrón `contentGrid()` + `Stack` es el documentado oficialmente por Filament para layouts de tarjetas, pero no se pudo levantar el panel para verlo renderizado.
- **3ra vuelta (mismo día, la galería YA funcionaba — captura confirmó el `Stack` andando — pero pidió pulido: "fullwidth el container de filament y las imágenes que se vean más estéticos como los nombres, de 4 columnas tal vez"):**
  - `ManageMedia::getMaxContentWidth()` nuevo (override de `Filament\Support\Enums\Width::Full`, solo en esta página — el resto del Studio sigue con el ancho estándar de Filament).
  - Grilla: de `default:2/sm:3/md:4/lg:5/xl:6` a `default:1/sm:2/md:3/lg:4/xl:4` — tope de 4 desde `lg`, tarjetas más grandes y prolijas en vez de apretadas.
  - Estética: mime type y tamaño ya no son 2 badges apilados sueltos — se funden en la MISMA línea (`flex gap-1.5`) dentro del `description()` del nombre, con el nombre real del archivo arriba en gris chico. Nombre centrado (`alignCenter()`), padding parejo alrededor (`px-3 pt-2.5 pb-3`, antes solo la imagen tenía su propio padding). Imagen sube de 120 a 190px de alto (con el container fullwidth y tope de 4 columnas, cada tarjeta tiene más aire).
  - **Bug real encontrado y corregido:** la captura mostraba "de 1 a 5 de 38 resultados" pese a que la 1ra vuelta ya había puesto `defaultPaginationPageOption(24)` — causa: 24 nunca estuvo en la lista de opciones del selector "por página" (`paginationPageOptions()`, nunca declarada explícitamente, Filament usa un set default propio que no incluye 24) — al no matchear, Filament cae a la PRIMERA opción de su set default (5). Se agrega `->paginationPageOptions([12, 24, 48])` explícito junto al default.
- **Archivos:** `app/Filament/Resources/MediaResource.php`, `app/Filament/Resources/MediaResource/Pages/ManageMedia.php`.
- **Verificación:** balance de `(){}[]` OK en ambos archivos. Confirmado por búsqueda en la documentación/changelog oficial de Filament que el enum se llama `Width` (no `MaxWidth`) desde Filament 4 en adelante — coincide con el Filament 5 de este proyecto.
- **4ta vuelta (mismo día, 4 pedidos puntuales más: "paginación de 50 y las imágenes centradas, además el botón de acciones a la derecha superior flotante y listar por default los últimos modificados pero que se pueda filtrar por fecha de creación también"):**
  - Paginación: `paginationPageOptions([12, 24, 50, 100])` + `defaultPaginationPageOption(50)` (antes 24 — mismo bug de fondo de la vuelta pasada, el valor default tiene que estar en el set de opciones o Filament lo ignora en silencio).
  - Imágenes: `object-center` explícito + `mx-auto` en el `<img>` (antes confiaba en el default del navegador).
  - Orden por defecto: `->defaultSort('updated_at', 'desc')` — antes no tenía ningún sort explícito, caía al orden implícito de la PK/insert.
  - Filtro nuevo: `Filter::make('created_at')` con 2 `DatePicker` (`created_from`/`created_until`), patrón oficial de Filament para rangos de fecha — a propósito sobre `created_at`, NO sobre `updated_at` (son 2 preguntas distintas: cuándo se subió vs. cuándo se modificó por última vez).
  - Botón de acciones flotante: `ActionGroup` gana `->extraAttributes(['class' => 'absolute top-2 right-2 z-10 rounded-full bg-white/90 shadow-md backdrop-blur-sm dark:bg-gray-900/80'])`. **Incertidumbre real, no solo limitación de sandbox:** posicionar acciones así dentro de `contentGrid()` es un punto flojo documentado de la propia comunidad de Filament (reportes de resultados inconsistentes según versión/contexto de posicionamiento del ancestro) — no hay garantía de que el `position: absolute` encuentre el `position: relative` correcto sin verlo renderizado. Marcado explícitamente para revisión visual.
- **Archivos:** `app/Filament/Resources/MediaResource.php` (import nuevo: `Illuminate\Database\Eloquent\Builder`).
- **Verificación:** balance de `(){}[]` OK. Confirmado que el resto de los resources del proyecto (`ApiTokens`, `PageResource`, `ServiceResource`, etc.) usan `->actions()` (no `->recordActions()`) — se descartó renombrar el método en este archivo para no romper la convención ya establecida en el proyecto, pese a que la documentación de un plugin de terceros mencionaba ese rename como parte de "Filament 5" (no se pudo confirmar si aplica a la versión exacta instalada acá, y el resto del código ya funciona con `->actions()`).
- **Siguiente:** el Tech Lead confirma visualmente, en particular el botón flotante (el punto más incierto de los 4).
- **5ta vuelta (mismo día, fix real con captura): el botón "⋮" flotante SÍ quedó bien posicionado (confirma que el `absolute` de la vuelta anterior encontró un ancestro razonable), pero su dropdown ("Editar"/"Borrar") abría en la esquina inferior-izquierda de la PANTALLA en vez de al lado del botón.** Diagnóstico correcto del Tech Lead: "te faltó por stack el relative". Causa real: el botón de acciones es `position: absolute`, pero nada en el camino tenía `position: relative` — su contenedor de posicionamiento terminaba siendo el `<body>`, y el cálculo de posición del dropdown (que depende del ancestro posicionado más cercano) se rompía. Fix: `Stack::make([...])->extraAttributes(['class' => 'relative'])` — el Stack es el wrapper visual de cada tarjeta individual dentro de `contentGrid()`, el lugar correcto para anclar tanto el botón como su dropdown a ESA tarjeta puntual.
- **Archivos:** `app/Filament/Resources/MediaResource.php` (mismo archivo, 5ta pasada).
- **Verificación:** balance de `(){}[]` OK.
- **6ta vuelta (2026-09-14, ajuste puntual con captura: "mucho gap entre el título y nombre del archivo"):** el `<div>` del `description()` de la columna "Nombre" llevaba `mt-1` propio, ENCIMA del espacio que Filament ya agrega por defecto entre el texto principal de una `TextColumn` y su `description()` — la suma de ambos dejaba un salto visual de más entre "un feliz sabado- tono E" (negrita) y "un feliz sabado- tono E.jpg" (gris chico, debajo). Fix: `mt-1` → `-mt-1` para compensar ese espacio duplicado (se probó `mt-0` primero pero seguía dejando más aire del esperado). El `gap-1.5` interno entre el nombre de archivo y los badges de mime/tamaño no se tocó, no era parte del reclamo.
- **Archivos:** `app/Filament/Resources/MediaResource.php` (mismo archivo, 6ta pasada).
- **Verificación:** balance de `(){}[]` OK.
- **7ma vuelta (2026-09-14, `form()` — 2 pedidos, con capturas):**
  - "en cada preview del upload hay un ícono para editar la foto... en multimedia no lo tiene al editar": el resto de los Resources (Páginas, Posts, Sliders) arman su `FileUpload` vía `App\Filament\Schemas\MediaUpload::make()`, que sí encadena `->imageEditor()` — el de `MediaResource::form()` se arma a mano y nunca lo tuvo. Se agrega `->imageEditor()` al `FileUpload::make('path')`. A propósito SIN `->image()`: ese método restringe los tipos de archivo aceptados solo a imágenes, y este campo es la Biblioteca de Medios completa (acepta también video/otros — no hay `acceptedFileTypes()` explícito, el `mime_type` real se detecta recién al subir) — `->image()` hubiera roto la carga de video. Confirmado en el código fuente del paquete (`vendor/filament/forms/src/Components/FileUpload.php`) que `imageEditor()` e `image()` son 2 flags independientes, no hace falta el segundo para que funcione el primero.
  - "falta ajustar el UX de ese formulario que se ve muy compacto cuando tiene espacio en el body del modal": 1er intento — `Section::make()->schema([Grid::make(['md'=>2])->schema([FileUpload, Group::make([name, alt_text])])])`. 2 problemas reales, ambos reportados por el Tech Lead:
    1. **Crash en producción:** `Class "App\Filament\Resources\Grid" not found` (`livewire/update`, 500) — el `use Filament\Schemas\Components\Grid;` se agregó al archivo, pero PHP resolvió `Grid::make()` contra el namespace local `App\Filament\Resources` igual. No se pudo reproducir la causa exacta en este sandbox (sin runtime PHP para aislar si fue caché de Composer/OPcache del lado del Tech Lead u otro problema); irrelevante para el fix final porque se elimina el uso de `Grid` por completo (ver abajo).
    2. **Ancho real, con captura + inspector del navegador:** aun sin el crash, la `Section` seguía sin ocupar el ancho real del modal (`slideOver`, `modalWidth('2xl')`) — quedaba angosta con espacio vacío a la derecha. **Mismo bug de fondo YA documentado y resuelto una vez en este proyecto** (`TestimonialResource::form()`, 2026-08-31, ver comentario ahí): grids de Filament anidados (`Section` → `Grid`/`Group` → campos) pueden colapsar a un ancho "shrink-to-fit" en vez de estirarse al 100% del contenedor — se repitió el mismo error en `MediaResource` en vez de reusar la solución ya probada.
  - **Fix final (2do intento, mismo patrón ya probado en `TestimonialResource`):** se aplana todo — `FileUpload`, `name`, `alt_text` y los 4 `Hidden` son hijos DIRECTOS de la única `Section`, que usa su PROPIO `->columns(2)` (sin `Grid` ni `Group` intermedios); cada campo controla su posición con `->columnSpan(1)` (archivo, nombre) o `->columnSpanFull()` (alt text, ocupa la fila completa debajo). `->extraAttributes(['class' => 'w-full'])` + `->columnSpanFull()` en la `Section` misma — cinturón y tirantes contra el mismo colapso de ancho. Ya no quedan referencias a `Grid`/`Group` en el archivo (imports retirados).
- **Archivos:** `app/Filament/Resources/MediaResource.php`.
- **Verificación:** balance de `(){}[]` OK. Confirmado por grep que no queda ningún `Grid::make`/`Group::make` en el archivo (solo `Actions\ActionGroup`/`Actions\BulkActionGroup`, sin relación). Sin runtime PHP en este sandbox — pendiente que el Tech Lead recargue en el servidor real para confirmar que el crash no se repite y que la Section ahora sí ocupa el ancho del modal.
- **8va vuelta (2026-09-14, `table()`, con captura: "aplicar truncate o no-wrap a los nombres de archivos, pero mostrar siempre la extensión"):** el `file_name` real se renderizaba en 1 línea sin ningún límite de ancho — dentro del `flex-col items-center` del `description()`, que en el eje horizontal encoge cada hijo a su contenido en vez de estirarlo al 100% de la tarjeta, un nombre largo se salía de los bordes y se montaba visualmente sobre la tarjeta de al lado (confirmado en la captura). Un `truncate` de Tailwind puro no alcanzaba: recorta con "…" al FINAL del texto, así que en nombres largos se hubiera comido justo la extensión — lo opuesto de lo pedido. Fix: nuevo helper `MediaResource::splitFileName()` separa `file_name` en `[base, extensión]`; se renderizan en 2 `<span>` dentro de una fila `flex w-full` (el `w-full` rompe el "encoger al contenido" que imponía el `items-center` del padre) — la base lleva `truncate` + `min-w-0` (un hijo flex necesita `min-w-0` explícito para poder encogerse por debajo de su ancho de contenido; sin eso `truncate` no tiene ningún efecto dentro de un flex container), la extensión lleva `shrink-0` y nunca se recorta, sin importar el ancho real de la tarjeta.
- **Archivos:** `app/Filament/Resources/MediaResource.php` (helper nuevo `splitFileName()`, mismo archivo).
- **Verificación:** balance de `(){}[]` OK. Sin runtime PHP en este sandbox para `php -l` — pendiente confirmación visual del Tech Lead.
- **9na vuelta (2026-09-14, `contentGrid()` — pedido explícito de breakpoints): "en tablet 1 columna, a partir de 1024px 3 columnas, a partir de 1536px 4 columnas, a partir de 1920px 5 columnas".** `1024` y `1536` son, literalmente, los breakpoints `lg` y `2xl` de Tailwind — se usan tal cual (`contentGrid(['default' => 1, 'lg' => 3, '2xl' => 4, ...])`), sin declarar `sm`/`md` (así "tablet", ~768-1024px, se queda en el `default` de 1 columna). `1920` NO es un breakpoint nativo de Tailwind ni de `contentGrid()` — los de fábrica de Filament llegan hasta `2xl` = 1536px (confirmado en `vendor/filament/support/resources/css/components/grid.css`, que trae `sm`/`md`/`lg`/`xl`/`2xl` ya compilados, más variantes de container query `@3xs`.`@7xl` que dependen del ancho del contenedor, NO del viewport — no sirven para este pedido). Se define una clave custom `uw` ("ultra-wide", `contentGrid(['uw' => 5])`) — Filament la procesa con el MISMO mecanismo genérico que cualquier otra clave (clase `uw:fi-grid-cols` + variable CSS `--cols-uw`, ver `ComponentAttributeBag::grid()`), pero al no ser un breakpoint real no existe ningún `@media` que la dispare — se agrega a mano en `resources/css/filament/cms/theme.css` (el theme propio del panel Studio, ya existente desde el fix de `MenuTreeBuilder`), apuntando a la MISMA variable CSS que ya trae el elemento (sin duplicar el número de columnas en 2 lugares).
- **Archivos:** `app/Filament/Resources/MediaResource.php`, `resources/css/filament/cms/theme.css`.
- **Verificación:** balance de `(){}[]` OK (PHP) y de `{}` OK (CSS, script Python). Requiere `npm run build` para que el CSS nuevo tome efecto (mismo requisito que el resto del theme del panel). Sin runtime para confirmar visualmente en este sandbox.
- **10ma vuelta (mismo día): "forzar a partir de 620px debería ser 2 columnas y de 1024px sigue normal lo que ya se configuró".** 620px tampoco es un breakpoint nativo de Tailwind (el más cercano, `sm`, es 640px — pedido explícito de 620) — mismo tratamiento que `uw`: nueva clave custom `w620` (`contentGrid(['default' => 1, 'w620' => 2, 'lg' => 3, '2xl' => 4, 'uw' => 5])`) + su `@media (min-width: 620px)` a mano en `theme.css`. De 620 a 1024px la grilla queda en 2 columnas; de 1024 en adelante sigue exactamente como ya estaba (sin tocar `lg`/`2xl`/`uw`).
- **Archivos:** `app/Filament/Resources/MediaResource.php`, `resources/css/filament/cms/theme.css` (mismos archivos, 10ma pasada).
- **Verificación:** balance de `(){}[]` OK (PHP) y de `{}` OK (CSS). Requiere `npm run build`.
- **11va vuelta (mismo día, BUG real encontrado en vivo, captura a 1920px mostrando la grilla estancada en 2 columnas en vez de 5).** Causa raíz: la regla de `w620` usaba `min-width: 620px` SIN techo (`max-width`) — sigue siendo verdadera en CUALQUIER viewport más ancho, así que a 1920px compite con `lg`/`2xl`/`uw` por la misma propiedad (`grid-template-columns`) con la MISMA especificidad CSS (mismo patrón de selector que usa Filament para sus propios breakpoints). A especificidad igual gana la ÚLTIMA regla del CSS compilado, no la de mayor `min-width` — y `w620` había quedado escrita DESPUÉS de `uw` en el archivo, así que le ganaba a `lg`, `2xl` Y `uw` en cualquier viewport ≥ 620px, de ahí las 2 columnas fijas hasta en 1920px. Fix: acotar `w620` con `max-width: 1023.98px` (`@media (min-width: 620px) and (max-width: 1023.98px)`) — así esta regla solo puede ganar dentro de su rango real (620-1023px), nunca compite con `lg`/`2xl`/`uw` fuera de él, sin importar el orden en que estén escritas las reglas en el archivo (deja de depender del orden del CSS para ser correcta).
- **Archivos:** `resources/css/filament/cms/theme.css` (mismo archivo, 11va pasada). Sin cambios en `MediaResource.php` — el bug era 100% CSS.
- **Verificación:** balance de `{}` OK. Requiere `npm run build`. Sin runtime para confirmar visualmente en este sandbox — pendiente que el Tech Lead confirme 2 columnas entre 620-1023px, 3 desde 1024, 4 desde 1536, y (el punto reportado) 5 columnas reales a 1920px.
- **12va vuelta (mismo día): "a partir de 1080px que sea de 3" (en vez de 1024).** 1080 tampoco es un breakpoint nativo de Tailwind — se reemplaza la clave `lg` (nativa, 1024px) por una clave custom `w1080`, mismo tratamiento que `w620`/`uw` (rango acotado con `max-width`, mismo criterio de la vuelta anterior para no repetir el bug de la 11va). `w620` extiende su techo de `1023.98px` a `1079.98px` (justo antes de que arranque `w1080`); `w1080` cubre `1080px` a `1535.98px` (justo antes de `2xl`). `contentGrid()` final: `default:1, w620:2, w1080:3, 2xl:4, uw:5`.
- **Archivos:** `app/Filament/Resources/MediaResource.php`, `resources/css/filament/cms/theme.css` (mismos archivos, 12va pasada).
- **Verificación:** balance de `(){}[]` OK (PHP) y de `{}` OK (CSS). Requiere `npm run build`. Sin runtime para confirmar visualmente en este sandbox.

## 2026-09-14 — MediaResource: `loading="lazy"` en la galería (perf) + diagnóstico de error de consola ajeno a la app
- **Reporte del Tech Lead:** captura de DevTools con `Uncaught (in promise) Error: Could not establish connection. Receiving end does not exist.` (fuente `media:1`) + "hay un lag que se está presentando en la interfaz".
- **Diagnóstico del error de consola:** es la firma clásica de una EXTENSIÓN del navegador (Chrome), no de este código — ocurre cuando una extensión intenta `chrome.runtime.sendMessage` hacia su propio background script y ese script no está escuchando (extensión recargada, actualizada, o su content script no llegó a inyectarse a tiempo). No es un error de Livewire/Alpine/Filament ni de ningún JS propio de este proyecto — el "media:1" como origen es compatible con un content script de una extensión inyectado sobre la página, no con ningún archivo de este repo (no hay ningún `media.js` ni similar en el proyecto). Para confirmarlo: recargar la misma página en una ventana de incógnito con las extensiones desactivadas — si el error desaparece ahí, es 100% la extensión, no la app.
- **Causa real del lag (independiente del error de consola):** con la paginación por defecto de 50 tarjetas (4ta vuelta, antes en este mismo historial) y sin `loading="lazy"` en el `<img>` de cada tarjeta, el navegador pedía las 50 imágenes reales de una sola vez al cargar la página, aunque la mayoría no esté ni cerca del viewport todavía. Fix: `loading="lazy"` (atributo nativo del navegador, sin JS ni librería nueva) en `extraImgAttributes()` del `ImageColumn` — difiere la descarga de cada imagen hasta que su tarjeta esté por entrar en pantalla.
- **Archivos:** `app/Filament/Resources/MediaResource.php`.
- **Verificación:** balance de `(){}[]` OK. Sin runtime PHP en este sandbox — pendiente que el Tech Lead confirme si el lag mejora, y que descarte el error de consola probando en incógnito sin extensiones.

## 2026-09-14 — MediaResource: checkbox de selección flotante (esquina superior-izquierda)
- **Pedido del Tech Lead:** "así como se puso flotante las acciones, de igual forma el check de selección debería estar en la parte superior-izquierda superpuesto" (con captura mostrando el checkbox actual, suelto en la esquina inferior-izquierda de cada tarjeta, fuera de la imagen).
- **Investigación:** el checkbox de selección por fila NO tiene un modo "flotante" nativo en Filament — `Table::recordCheckboxPosition()` (enum `RecordCheckboxPosition::BeforeCells`/`AfterCells`, confirmado en `vendor/filament/tables/src/Enums/RecordCheckboxPosition.php`) solo controla el ORDEN en el DOM, no la posición visual. Se resuelve con CSS a mano en el theme del panel, mismo criterio que ya se usó para el botón "⋮" flotante (`MediaResource::table()`).
- **Qué se hizo:** `.fi-ta-record` (el wrapper nativo de Filament para cada tarjeta dentro de `contentGrid()`) recibe `position: relative` explícito; el checkbox (`.fi-ta-record-checkbox`) pasa a `position: absolute; top: 0.5rem; left: 0.5rem;` con un halo (`box-shadow` blanco/oscuro según tema) para que se lea sobre cualquier foto, clara u oscura — mismo problema de legibilidad que ya resolvió el fondo semi-opaco del botón de acciones. Selector con `input[type='checkbox']` (no solo la clase) a propósito: por especificidad CSS, un selector de solo-clases hubiera perdido contra la regla nativa de Filament para `.fi-checkbox-input` (fondo/ring/tilde) — con el `input[type='checkbox']` explícito se gana esa pulseada sin pisar el resto del estilo nativo del checkbox.
- **Alcance, documentado a propósito:** la regla está scopeada a `.fi-ta-content-grid` (cualquier tabla en modo `contentGrid()`), no a `MediaResource` puntualmente — hoy es el ÚNICO resource que usa `contentGrid()` en el panel, así que en la práctica solo afecta acá; si otro resource lo adopta en el futuro, heredaría este mismo estilo (aceptado por ahora).
- **Archivos:** `resources/css/filament/cms/theme.css`. Sin cambios en `MediaResource.php` (no hace falta tocar `->recordCheckboxPosition()`, el default `BeforeCells` ya coincide con el orden actual del DOM).
- **Verificación:** balance de `{}` OK (script Python). Requiere `npm run build` para que el CSS tome efecto. Sin runtime para confirmar visualmente en este sandbox.

## 2026-09-14 — Sidebar collapsible en Studio (`PanelCmsProvider`)
- **Pedido del Tech Lead:** "activar el sidebar collapsible".
- **Qué se hizo:** `->sidebarCollapsibleOnDesktop()`, nativo de Filament — colapsa el sidebar a una barra angosta de solo íconos con un botón para expandir/contraer, no lo oculta del todo (`sidebarFullyCollapsibleOnDesktop()` es la otra opción de Filament para eso, no la que se pidió). Puro Alpine/CSS que ya trae Filament — no requiere `npm run build` ni tocar el theme del panel. Aplicado solo al panel `cms` (Studio, el que se ve en la captura) — no se tocó `PanelPlatformProvider` (el panel de super-admins), a definir con el Tech Lead si también lo quiere ahí.
- **Archivos:** `app/Providers/Filament/PanelCmsProvider.php`.
- **Verificación:** balance de `(){}[]` OK. Sin runtime PHP en este sandbox para confirmar visualmente.

## 2026-09-13 — Cache headers para media servida por Apache (`public/.htaccess`)
- **Agente/autor:** Claude (Tech Lead asistido)
- **Qué se hizo:** el Tech Lead pidió mejorar performance tras compartir varias capturas de un audit Lighthouse/PageSpeed del sitio cica360; una de las secciones ("Usa tiempos de almacenamiento en caché eficientes") marcaba ~430 KiB de media (`media/cica360_media_slide*.webp`, `split_*.webp`, servida desde `api.stamless.host`) sin ningún header de cache — `Media::url()` apunta al symlink `public/storage`, Apache la sirve como archivo estático plano, nunca pasó por ningún controller que pudiera setear cache. Se agrega un bloque `mod_expires` en `public/.htaccess` (`image/png`, `image/jpeg`, `image/webp`, `image/svg+xml`, `video/mp4`, `video/webm` → "access plus 1 month"). 1 mes, no 1 año: un archivo de `media` puede reemplazarse in-place con el mismo nombre (ya pasó esta sesión con las 2 imágenes OG), así que no hay cache-busting real por filename — mismo criterio que ya usa el `.htaccess` de cica360 para sus propios assets sin hash.
- **Archivos:** `public/.htaccess`.
- **Verificación:** sintaxis Apache revisada a mano (mismo formato que el bloque `mod_expires` ya existente en cica360). Sin servidor Apache real en este sandbox para probarlo en caliente.
- **Siguiente:** el Tech Lead confirma en un hosting real (o con `curl -I` contra un archivo de `/storage/media/...`) que el header `Expires`/`Cache-Control` aparece.

## 2026-09-13 — Endpoint público de tracking (Meta Pixel / GTM), ver ADR-066
- **Agente/autor:** Claude (Tech Lead asistido)
- **Qué se hizo:** el Tech Lead preguntó si guardar Meta Pixel ID / GTM ID en Preferencias > Integraciones (entrada previa de esta misma sesión) ya los activaba en la website — la respuesta era que no, faltaba el consumidor público. Aprobó implementarlo ("Sí, ambos ahora") y pidió explícitamente que la carga fuera perezosa para no afectar Lighthouse/PageSpeed Insights. Se agregó `App\Http\Controllers\Api\V1\SiteSettingsController::tracking()`, nuevo endpoint `GET /v1/{tenant}/settings/tracking` dentro del grupo `abilities:content:read` ya existente en `routes/api.php`, que devuelve `{ meta_pixel_id, gtm_id }` leídos de `Setting` (`tracking.meta_pixel_id`/`tracking.gtm_id`, ya guardados por `Preferences.php`). El controller es a propósito un whitelist explícito — nunca un dump genérico de `Setting` — por ser el primer endpoint de "config de sitio completo" y no de una página/post/servicio puntual. La inyección real de los scripts vive del lado de `cica360` (ver PROGRESS.md de ese repo).
- **Archivos:** `app/Http/Controllers/Api/V1/SiteSettingsController.php` (nuevo), `routes/api.php` (import + ruta nueva).
- **Verificación:** balance de `(){}[]` OK en ambos archivos. Sin runtime PHP en este sandbox — no se pudo pegarle al endpoint real ni correr `php artisan route:list`.
- **Siguiente:** ver ADR-066 para el detalle completo (backend + frontend). El Tech Lead debe cargar IDs reales en Preferencias > Integraciones y confirmar en el Network tab del sitio (tras interactuar o esperar 5s) que `gtm.js`/`fbevents.js` se disparan.

## 2026-09-13 — Corrección: imágenes OG por defecto con el color de marca real + logo oficial (no navy genérico)
- **Agente/autor:** Claude (Tech Lead asistido)
- **Qué se hizo:** reporte del Tech Lead sobre las 2 imágenes OG recién sembradas (entrada de abajo): "las imagenes OG tiene que tener el fondo del color principal". Verificado con pixel-sampling: el fondo real era `#0B1E3A` (navy genérico, no vinculado a ningún token) y el "logo" era un wordmark "CICA" con gradiente dorado que tampoco es el logo oficial del sitio. Se regeneraron ambos archivos (mismos nombres, mismas dimensiones — `cica360_media_og_horizontal.jpg` 1200x630, `cica360_media_og_square.jpg` 1200x1200, sin tocar el seeder ni la tabla `media`) con: (1) fondo sólido `#2D2C4D` (`--color-cicaindigo-500`, el DEFAULT real de marca de CICA360, ver `cica360/src/styles/global.css`) con un vignette sutil del mismo hue (no una versión desaturada); (2) el logo OFICIAL rasterizado desde `cica360/public/logos/logo-main-white.svg` (el mismo SVG que usa `Header.astro` en el navbar real, alt="CICA360") en vez de un texto aproximado; (3) mismo lenguaje visual que la versión anterior (corner brackets dorados, regla dorada, tagline "SEGUROS · FINANZAS · ASESORÍA LEGAL" tracked-out en Lato Bold, la tipografía real del sitio) para no perder la identidad ya aprobada, solo corrigiendo color/logo.
- **Archivos:** `storage/app/public/media/cica360_media_og_horizontal.jpg`, `storage/app/public/media/cica360_media_og_square.jpg` (binarios, reemplazados in-place). Sin cambios de código — el seeder de la entrada de abajo sigue apuntando a los mismos nombres de archivo.
- **Verificación:** pixel-sampling confirma la esquina de ambos archivos en `#2E2D4D` (≈`#2D2C4D`, diferencia de redondeo JPEG) en vez de `#0B1E3A`.
- **Siguiente:** el Tech Lead corre `db:seed` (o simplemente confirma, si ya lo había corrido, que los archivos en disco cambiaron) y verifica visualmente en Preferencias / al compartir un link del sitio.

## 2026-09-13 — Seeder: SEO/Open Graph por defecto del tenant + 2 imágenes OG de CICA360
- **Agente/autor:** Claude (Tech Lead asistido)
- **Qué se hizo:** pedido explícito del Tech Lead ("considerar en el seeder de contenido inicial como setting general tanto para el SEO como para el OG"), tras confirmar en vivo (viendo "Ver código fuente" del sitio) que sin ningún dato cargado el fallback de ADR-065 cae en cascada hasta el título/descripción base y sin ningún `og:image`. Se agregan 2 archivos ya subidos por el Tech Lead a `storage/app/public/media/` (`cica360_media_og_horizontal.jpg` 1200x630, `cica360_media_og_square.jpg` 600x600) a `Cliente0MediaSeeder::FILES` (keys `og_horizontal`/`og_square`, mismo patrón "archivo commiteado + `firstOrCreate`" del resto del seeder). Nuevo método `Cliente0ContentSeeder::upsertSeoDefaults()`, llamado al final de `run()`, puebla las 7 claves de `Setting` de ADR-065 (`seo.default_title`/`seo.default_keywords`/`seo.default_description`/`og.default_title`/`og.default_description`/`og.default_image_rect_id`/`og.default_image_square_id`) con copy real de CICA360 (no placeholder) + los 2 ids de media recién sembrados, vía `Setting::updateOrCreate(['tenant_id', 'key'], ['value'])` — `tenant_id` explícito porque `HasTenant` no auto-completa fuera de un request HTTP real (sin `TenantManager` resuelto en un seeder).
- **Archivos:** `database/seeders/Cliente0MediaSeeder.php` (2 entradas nuevas en `FILES`), `database/seeders/Cliente0ContentSeeder.php` (import `Setting`, llamada en `run()`, método `upsertSeoDefaults()` nuevo).
- **Verificación:** balance de `(){}[]` OK en ambos archivos. Sin runtime PHP en este sandbox — no se pudo correr `php artisan db:seed` ni confirmar que los 2 archivos `.jpg` existen físicamente en el disco del Tech Lead (asumido por la captura de VS Code que compartió, ruta `storage/app/public/media/`).
- **Siguiente:** el Tech Lead corre `php artisan db:seed --class=Cliente0ContentSeeder` (o `migrate:fresh --seed` completo) y confirma: (1) Preferencias → SEO/Open Graph muestran los valores nuevos al recargar; (2) `GET /v1/cica360/pages/servicios` (o cualquier página sin SEO propio) ya trae `og_image_rect`/`og_image_square` con `url` real; (3) "Ver código fuente" del sitio (`npm run dev`/`build` en cica360) ya muestra `<meta property="og:image">`.

## 2026-09-13 — Preferencias: layout en tarjetas (Grid 2 cols) + nueva Section "Integraciones" (Meta Pixel / GTM)
- **Agente/autor:** Claude (Tech Lead asistido)
- **Qué se hizo:** 2 ajustes en la misma página, mismo día que ADR-065 (ver entrada de abajo). (1) **Layout**: `preferences.blade.php` envolvía TODO el form en un `<div class="gnss-card" style="max-width: 32rem">` — apropiado cuando solo había 2 campos (idioma/zona horaria), pero apretaba las Sections nuevas de SEO/OG en una sola columna angosta en vez de verse como tarjetas independientes. Se sacó el wrapper del blade; `Preferences::form()` ahora usa `->columns(2)` a nivel de página con 4 `Section` (`Cuenta`/`Integraciones` arriba, `Metadata SEO`/`Open Graph` abajo, cada una ocupando 1 columna — responsive, se apilan solas en mobile). (2) **Integraciones nueva**: 2 campos más, mismo mecanismo `Setting`/`setting()` que SEO/OG (`tracking.meta_pixel_id`, `tracking.gtm_id`), pedido explícito del Tech Lead para dejar un lugar donde configurar Meta Pixel y Google Tag Manager. A diferencia de SEO/OG (fallback de contenido por página), estos 2 son config de SITIO completo — no hay noción de "por página" que los pise, así que NO pasan por `attachResolvedSeoMeta()` ni por ningún mecanismo de fallback.
- **Archivos/áreas:** `app/Filament/Pages/Preferences.php`, `resources/views/filament/pages/preferences.blade.php`.
- **Verificación:** balance de paréntesis/llaves/corchetes OK.
- **Fuera de alcance a propósito:** NO se agregó exposición pública de `tracking.meta_pixel_id`/`tracking.gtm_id` (ni un endpoint `GET /v1/{tenant}/site` ni campo en un recurso existente) — solo se guardan en esta vuelta. Sin un consumidor confirmado en `cica360` todavía, agregar el endpoint ahora sería inventar contrato sin pedido concreto.
- **Siguiente:** si/cuando se pida inyectar estos scripts en el frontend público, definir cómo se exponen (endpoint de "site info" nuevo vs. campo embebido) y documentarlo en `docs/context/api/stamless-api-v1.md` de `cica360`.

## 2026-09-13 — SEO/Open Graph por defecto del tenant, expuestos en Preferencias, con fallback en la API pública (ADR-065)
- **Agente/autor:** Claude (Tech Lead asistido)
- **Qué se hizo:** pedido explícito del Tech Lead: las secciones "Metadata SEO" y "Open Graph (Redes Sociales)" (ya existentes en el tab "SEO / Enlaces" de Page/Post/Service) ganan un equivalente TENANT-WIDE en `Preferences.php` (menú del avatar), usado como fallback por la API pública cuando una página/legal/servicio/publicación puntual no define su propio valor. Implementación: (1) 7 claves nuevas de `Setting` (`seo.default_title`/`seo.default_keywords`/`seo.default_description`/`og.default_title`/`og.default_description`/`og.default_image_rect_id`/`og.default_image_square_id`), sin tabla dedicada — `Setting`/`SettingService` ya existían sin consumidor real hasta ahora. (2) `Preferences.php` gana 2 `Section` nuevas (mismos campos/labels que `PageResource`, incluido `MediaUpload` para las imágenes) junto a `locale`/`timezone` — mezcla de scopes (tenant-wide + por-usuario) deliberada, documentada en el docblock de la clase y en el ADR, por instrucción explícita de ubicación del Tech Lead. (3) Nuevo método `ResolvesPublicLinks::attachResolvedSeoMeta()` (+ `mergeSeoDefaults()`/`seoDefaults()`) — mismo patrón que `attachResolvedLinks()`: setea un atributo transitorio `resolved_meta` en el record, sin tocar la columna `meta` real en DB. Fallback campo-por-campo con `blank()` para los 5 campos de texto; las 2 imágenes SIEMPRE se resuelven a objeto Media público (`resolveMediaRef()`), propia primero, default del tenant si no hay. (4) `PageController`/`PostController`/`ServiceController::show()` llaman `attachResolvedSeoMeta([$record])` junto a `attachResolvedLinks()`. (5) `PageResource`/`PostResource`/`ServiceResource`: `'meta' => self::asObject($this->meta)` → `self::asObject($this->resolved_meta ?? $this->meta)`.
- **Bug preexistente cerrado de paso:** `meta.og_image_rect_id`/`meta.og_image_square_id` nunca se resolvían a URL — salían de la API como el id interno crudo de `Media`, inconsistente con el resto del contrato público (ADR-018, todo lo demás vía `resolveMediaRef()`). Ahora salen siempre como `og_image_rect`/`og_image_square` (`{uuid, url, alt_text, mime_type}`), con o sin fallback de por medio.
- **Archivos/áreas:** `app/Http/Concerns/ResolvesPublicLinks.php` (3 métodos nuevos), `app/Http/Controllers/Api/V1/{Page,Post,Service}Controller.php` (1 línea c/u), `app/Http/Resources/Api/V1/{Page,Post,Service}Resource.php` (1 línea c/u), `app/Filament/Pages/Preferences.php` (2 Sections + mount/save actualizados), `docs/context/DECISIONS.md` (ADR-065), `docs/context/ARCHITECTURE.md` (§10.2), `docs/context/CURRENT_STATE.md`.
- **Verificación:** balance de paréntesis/llaves/corchetes (script Python, comentarios/strings excluidos) OK en los 8 archivos PHP tocados. Sin runtime PHP en este sandbox — no se pudo correr `php artisan test`/`pint --dirty`, pendiente del Tech Lead.
- **Fuera de alcance a propósito:** `Slider`/`Slide` no ganan fallback (el pedido fue explícito sobre "página|legal|servicio|publicación", sin tab SEO propio hoy). Los endpoints `index`/summary no se tocan (nunca expusieron `meta`).
- **Siguiente:** el Tech Lead corre `vendor/bin/pint --dirty` + `php artisan test` (o el filtro relevante) y confirma visualmente: guardar valores en Preferencias, dejar vacío el SEO de una página de prueba, y verificar que `GET /v1/{tenant}/pages/{slug}` devuelve el fallback con `og_image_rect`/`og_image_square` como URL real.

## 2026-09-13 — `->columns(2)` faltante en 2 Sections de "Propiedades" (Post, Service)
- **Agente/autor:** Claude (Tech Lead asistido)
- **Contexto/motivación:** pedido puntual ("en el blog... 'Propiedades de la publicación' debería estar en dos columnas, recuerda todas las properties a dos columnas o 3") sobre una captura mostrando esa Section en 1 columna. Auditado con grep: **todos** los `PropertiesSchema::make()` de `PageResource.php` (13 usos) ya tenían `->columns(2)` — el estándar del proyecto se venía respetando ahí. Los 2 huecos reales estaban en `PostResource::table()`'s "Propiedades del post" y `ServiceResource`'s "Personalización de estilos", ambos con la Section pero sin el `->columns(2)`.
- **Qué se hizo:** `->columns(2)` agregado a los 2 `PropertiesSchema::make()` de `PostResource.php` y `ServiceResource.php`. Confirmado por grep que no quedan más usos de `PropertiesSchema::make()` sin `->columns()` en `app/Filament/`.
- **Archivos/áreas:** `app/Filament/Resources/PostResource.php`, `app/Filament/Resources/ServiceResource.php`.
- **Verificado:** balance de paréntesis/llaves/corchetes de ambos en 0.
- **Siguiente:** confirmación visual del Tech Lead en ambos formularios (Editar Publicación → SEO/Enlaces → Propiedades del post; Editar Servicio → SEO → Personalización de estilos).

## 2026-09-13 — Ajustes menores de listados: Blog, miniaturas circulares, "Sliders" sin aclaración
- **Agente/autor:** Claude (Tech Lead asistido)
- **Qué se hizo (4 pedidos puntuales del Tech Lead):**
  1. `PostResource::$navigationLabel` "Publicaciones" → "Blog" (solo el label del menú principal — `$pluralLabel`/`$modelLabel` quedan igual, alimentan botones/breadcrumbs internos no pedidos). Alineado además con la URL pública real (`/blog/{slug}`, ya visible en la `->description()` de la columna Título).
  2. `PostResource::table()`: primera columna nueva, miniatura circular (`ImageColumn::make('featuredImage.path')`, `->circular()`, `->disk()` dinámico) — mismo patrón exacto que `ServiceResource`/`TestimonialResource`.
  3. `SliderResource::$navigationLabel` "Sliders (Carruseles)" → "Sliders".
  4. `ServiceResource::table()`: la miniatura ya existente pasa de `->square()` a `->circular()`.
- **Archivos/áreas:** `app/Filament/Resources/PostResource.php`, `app/Filament/Resources/SliderResource.php`, `app/Filament/Resources/ServiceResource.php`.
- **Verificado:** balance de paréntesis/llaves/corchetes de los 3 archivos en 0.
- **Siguiente:** confirmación visual del Tech Lead en Studio (menú principal + ambos listados).

## 2026-09-13 — Paleta de marca Stamless dual-primary (Studio ámbar / Platform teal), no más `Color::Amber` de fábrica
- **Agente/autor:** Claude (Tech Lead asistido)
- **Contexto/motivación:** en una vuelta anterior de este mismo día se sugirió mantener el primary ámbar (`Color::Amber`) del panel Studio; el Tech Lead lo corrigió con razón: "solo que el amber lo relacionan con Filament basico" — es literalmente el color de referencia/demo que trae Filament sin personalizar, no una decisión de marca. Encargo formal en 2 mensajes: (1) definir la paleta oficial con hex concretos (`--sl-primary` `#D97706` y familia cálida/ink/paper/muted/dark), aplicarla en Studio + Platform + landing + favicon/manifest + revisar mail; (2) ajuste fino — un primary DISTINTO por panel (Studio ámbar `#D97706` "creación/taller", Platform teal `#0F766E` "operación/control B2B", nunca reutilizar uno en el otro) y wordmark/logo SIN recolorear (tinta neutra `--sl-ink` `#171412`).
- **Qué se hizo:**
  1. **`app/Providers/Filament/PanelCmsProvider.php`** (Studio): `Color::Amber` → `Color::hex('#D97706')`. `<meta name="theme-color" content="#D97706">` sumado al `renderHook(HEAD_END)` de favicon ya existente (mismo turno anterior de este día).
  2. **`app/Providers/Filament/PanelPlatformProvider.php`** (Platform): `Color::Indigo` (otro valor de scaffold sin decisión de diseño detrás) → `Color::hex('#0F766E')` (teal). Nuevo `renderHook(HEAD_END)` con el mismo set de favicon de marca que Studio + `<meta name="theme-color" content="#0F766E">` propia — Platform no tenía NINGÚN favicon propio hasta ahora.
  3. **`resources/views/public/home.blade.php`** (landing `stamless.host`, teaser "Pronto"): `:root` con las CSS vars de marca (`--sl-primary`/`-hover`/`-tint`, `--sl-ink`/`--sl-paper`/`--sl-muted`/`--sl-dark` — deliberadamente SIN `--sl-platform-primary`, esta landing no linkea a Platform todavía). Fondo `#0B0C0E` (valor suelto) → `var(--sl-dark)` (`#1C1917`, mismo tono casi-negro, ahora es el token oficial). Accent del label "Pronto" `#C4A574` (dorado suelto) → `var(--sl-primary-tint)` (`#F5A524`). `<meta name="theme-color" content="#D97706">` nueva (usa el de Studio: es el producto que se está por lanzar).
  4. **`public/favicon/site.webmanifest`**: `theme_color` `#f59e0b`→`#D97706`, `background_color` `#ffffff`→`#FAF7F2` (`--sl-paper`), `name` `"Stamless"`→`"Stamless Studio"` (este manifest es específicamente de Studio — Platform no tiene uno propio en esta vuelta).
- **Contraste AA verificado (no solo declarado):** replicado en Python el algoritmo exacto de `Filament\Support\Colors\Color::generatePalette()` (conversión OKLCH↔sRGB, misma fórmula del vendor) para calcular el shade 600 real que Filament deriva de cada hex — texto blanco sobre ese shade: Studio `#D97706`→`#c65f00` da **4.17:1** (Platform `#0F766E`→`#009d8f` da **3.38:1**), ambos superan el mínimo AA de UI/texto grande (3:1); como referencia, el propio `Color::Amber` DEFAULT de Filament da solo **3.20:1** en el mismo shade — el cambio de Studio es una MEJORA de contraste, no una regresión. Hover (700): Studio 5.89:1, Platform 4.78:1, ambos ya sobre AA texto normal (4.5:1).
- **Favicon existente confirmado neutro, sin rediseño necesario:** inspección de píxeles (Python/Pillow) de las 4 imágenes PNG (`favicon-96x96`, `apple-touch-icon`, `web-app-manifest-192/512`) confirma que el color dominante es gris carbón oscuro (`~#242529`), nunca ámbar — el pedido de "si el favicon es amber-500 puro, regenerar" NO aplica, solo hacía falta alinear los metadatos de color (`theme_color`/manifest), ya hecho.
- **Revisado y NO tocado, a propósito:** `app/Filament/Widgets/PlanUsageWidget.php` usa `bg-amber-500` como color SEMÁNTICO de advertencia (barra de uso de plan al 80%), no de marca — tocarlo confundiría "cerca del límite" con "esto es Stamless". `app/Mail/ContactFormSubmitted.php` + `resources/views/emails/contacts/form-submitted.blade.php` no tienen color hardcodeado propio — usan el tema Markdown default de Laravel (vendor, nunca publicado/personalizado en este proyecto); rebrandearlo requeriría `vendor:publish --tag=laravel-mail` + reescribir su CSS, fuera de alcance (sin runtime PHP en este sandbox además). `resources/views/welcome.blade.php` (scaffold default de Laravel, con Tailwind embebido) confirmado sin ninguna ruta que lo sirva (`routes/web.php` no lo referencia) — dead code, no es superficie de marca.
- **Archivos/áreas:** `app/Providers/Filament/PanelCmsProvider.php`, `app/Providers/Filament/PanelPlatformProvider.php`, `resources/views/public/home.blade.php`, `public/favicon/site.webmanifest`, `docs/context/ARCHITECTURE.md` (§10.1 nueva), `docs/context/DECISIONS.md` (ADR-064).
- **Verificado:** balance de paréntesis/llaves/corchetes de los 2 providers PHP en 0; `site.webmanifest` validado como JSON; contraste WCAG calculado programáticamente (no estimado a ojo).
- **Siguiente:** el Tech Lead debe recargar Studio y Platform (cada uno con su propio primary/favicon) y confirmar visualmente; recargar `stamless.host` y confirmar que el fondo/accent no cambiaron perceptiblemente (mismo tono, ahora con nombre de token) y que el ícono del navegador ya no es el genérico. Ningún paso requiere `npm run build` (todo es runtime de Filament o HTML/CSS plano). Pendiente NO resuelto en esta vuelta (fuera de alcance, no pedido): email transaccional con marca propia (requiere publicar y reescribir el tema Markdown de Laravel).

## 2026-09-13 — Favicon de Stamless en el panel de Studio
- **Agente/autor:** Claude (Tech Lead asistido)
- **Contexto/motivación:** pedido del Tech Lead con el set completo de `<link>` (favicon-96x96.png, favicon.svg, favicon.ico, apple-touch-icon.png, site.webmanifest) — los archivos ya estaban publicados en `public/favicon/` y `public/favicon.ico` (generados con un generador de favicons externo), solo faltaba conectarlos al `<head>` del panel.
- **Qué se hizo:**
  1. Segundo `->renderHook(PanelsRenderHook::HEAD_END, ...)` en `PanelCmsProvider::panel()` (Filament acumula closures por hook, no los reemplaza — separado del hook existente de CSS/JS versionado para no mezclar responsabilidades) con los 5 `<link>` tal cual los pidió el Tech Lead, usando `asset()` para las URLs.
  2. `public/favicon/site.webmanifest`: `name`/`short_name` traían el placeholder del generador ("MyWebSite"/"MySite") — actualizado a "Stamless". `theme_color` de `#ffffff` a `#f59e0b` (ámbar del panel, `Color::Amber`).
- **Archivos/áreas:** `app/Providers/Filament/PanelCmsProvider.php`, `public/favicon/site.webmanifest`.
- **Verificado:** balance de paréntesis/llaves/corchetes del provider en 0; `site.webmanifest` validado como JSON válido.
- **Siguiente:** el Tech Lead debe recargar Studio (puede requerir limpiar caché del navegador/favicon cacheado) y confirmar que el ícono aparece en la pestaña del navegador y al instalar como PWA.

## 2026-09-13 — "Copiar a otra página" por bloque (extraItemActions del Builder de `blocks`)
- **Agente/autor:** Claude (Tech Lead asistido)
- **Contexto/motivación:** consulta del Tech Lead: "como podríamos resolver el hecho que el usuario va a querer copiar un bloque de una página a otra... tiene que ser un copiar, a parte de la acción clonar en cada bloque collapsed" — la acción "Duplicar" nativa de Filament (`->cloneable()`) solo clona DENTRO de la misma página; no existía forma de llevar un bloque ya armado a otra página sin rehacerlo a mano.
- **Diseño acordado con el Tech Lead:** clic en "Copiar a otra página" → modal con Select de página destino → al confirmar, el bloque se agrega de inmediato como bloque adicional al final de la página elegida; el usuario después entra ahí a reordenar/ajustar si hace falta. Se evaluó la alternativa de un "portapapeles" diferido (copiar ahora, pegar después en otra página) y se descartó por requerir estado compartido entre páginas distintas — más superficie para bugs sin beneficio real sobre la copia inmediata.
- **Qué se hizo:**
  1. **`self::blockCompatibilityRules()`** (nuevo, `private static`) — centraliza las 3 reglas de exclusión bloque↔tipo de página (`footerOnly`/`legalOnly`/`legalExcluded`/`footerAllowed`) que antes vivían hardcodeadas solo dentro del closure de `->blocks()`. El closure de `->blocks()` se refactorizó para consumir este método (mismo comportamiento, sin lógica duplicada).
  2. **`self::isBlockAllowedForPageType(string $blockName, PageTypeEnum $pageType): bool`** (nuevo, `private static`) — responde "¿este tipo de bloque puede vivir en este tipo de página?", reusando `blockCompatibilityRules()`. Usado para filtrar el Select de páginas destino: no tiene sentido ofrecer copiar un `colophon` a una Página normal, por ejemplo.
  3. **`->extraItemActions([...])`** agregado al `Builder::make('blocks')`, junto a `->cloneable()`: acción `copyToPage` con modal (Select de página destino, excluye la página actual, filtrado por `isBlockAllowedForPageType()`) y `->action()` que lee el item vía `$component->getRawState()[$arguments['item']]` (mismo mecanismo que usa el `cloneAction` nativo de Filament) y hace `$targetPage->blocks()->create([...])` directo a la base de datos — UUID nuevo vía `HasUuid`, `sort_order` al final (`$targetPage->blocks()->count()`), copiando `type`/`lang_iso`/`pretitle`/`title`/`subtitle`/`is_visible`/`links`/`properties`/`content` tal cual. Notificación de éxito con el nombre de la página destino.
- **Trade-off explícito, confirmado con el Tech Lead:** a diferencia de TODAS las demás acciones del Builder (clonar, borrar, reordenar), que quedan pendientes hasta que se apriete "Guardar", esta persiste en la base de datos al instante — la página destino no está cargada en este formulario Livewire, así que no hay forma de dejarlo "en borrador" sin un mecanismo de portapapeles con estado propio (alternativa evaluada y descartada, ver arriba). La página de origen nunca se modifica (es una copia, no un mover).
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (`blockCompatibilityRules()`, `isBlockAllowedForPageType()`, refactor del closure de `->blocks()`, `->extraItemActions()` nuevo).
- **Verificado:** balance de paréntesis/llaves/corchetes del archivo completo, confirmado en 0. Sin tests automatizados nuevos (Livewire/Filament Builder actions — el proyecto no tiene suite de tests para el Builder de `blocks` en general, ver gaps ya anotados en entradas anteriores de "Duplicar"/`ContactResource`/widgets).
- **Siguiente:** el Tech Lead debe recargar Studio, abrir una página con al menos un bloque, y confirmar en el menú de un bloque colapsado la nueva opción "Copiar a otra página": que el modal liste solo páginas compatibles con ese tipo de bloque, que al copiar aparezca de inmediato en la página destino (recargándola), y que la página de origen quede intacta.

## 2026-09-13 — 3 widgets nuevos en el Escritorio: barras de uso del plan, KPIs de leads y últimos contactos
- **Agente/autor:** Claude (Tech Lead asistido)
- **Contexto/motivación:** tras sacar `FilamentInfoWidget` y dejar solo `WelcomeWidget`, el Escritorio quedó prácticamente vacío. El Tech Lead lo notó con una captura: "entonces faltan los widgets para mostrar todo estos indicadores y auxiliares o utilidades con mucho UX" — cierra el pedido original del análisis de Dashboard ("un dashboard con barras de uso... contadores de contactos/leads... KPI's comunes").
- **Qué se hizo — 3 widgets nuevos, todos registrados en `PanelCmsProvider::widgets()` con `$sort` propio (Welcome -3 → Leads -2 → PlanUsage -1 → RecentContacts 0, controla el orden visual sin reordenar el array):**
  1. **`App\Filament\Widgets\PlanUsageWidget`** (`$sort = -1`): barra de progreso por recurso con límite de plan — Contenidos (Páginas+Legales+Secciones, vía `PageResource::navBadgeUsage()` ya existente), Publicaciones, Servicios, Sliders, Testimonios, Menús, Multimedia y API Tokens activos. Cada fila es un link directo al listado del recurso (ahorra clics, mismo espíritu del pedido "que ahorre tiempo ubicar las cosas"). Barra gris normal, ámbar al 80%, roja al 100%+ (mismo umbral que `FormatsUsageBadge::usageBadgeColor()`, sin límite = barra llena gris). Vista propia (`resources/views/filament/cms/widgets/plan-usage-widget.blade.php`) porque una barra de progreso no encaja en `StatsOverviewWidget` (que es "número + descripción", no barra). Para reusar los cálculos ya probados sin duplicar lógica, `PageResource::navBadgeUsage()` y `ApiTokens::activeTokensCountForTenant()` pasaron de `private` a `public static`.
  2. **`App\Filament\Widgets\LeadsOverviewWidget`** (`$sort = -2`, extiende `StatsOverviewWidget`): 3 stat cards — "Leads nuevos" (rojo si hay pendientes), "En proceso" (ámbar), "Total de contactos" (con "N en los últimos 7 días" de descripción) — cada una linkeada a `ContactResource`.
  3. **`App\Filament\Widgets\RecentContactsWidget`** (`$sort = 0`, extiende `TableWidget`): preview de los últimos 5 contactos (nombre+email, estado, fecha recibida vía `FriendlyDate`) sin salir del Escritorio, con acción de cabecera "Ver todos" hacia `ContactResource` completo — no duplica filtros/edición, es solo una vidriera rápida.
- **Decisión de color evitando el error ya conocido de esta sesión:** para el relleno de las barras de `PlanUsageWidget` se usaron clases LITERALES de la paleta default de Tailwind (`bg-gray-400`, `bg-amber-500`, `bg-red-500`), no los nombres semánticos de Filament (`warning`/`danger`) ni sus custom properties — esta app ya tuvo que revertir un intento de adivinar el formato de esas variables en Tailwind v4 (ver la entrada de "Panel Studio" más abajo, `sidebar-project-info.blade.php`). Clases literales del palette default SÍ se compilan de forma segura vía el `@source` que ya escanea `resources/views/filament/**/*`.
- **Archivos/áreas:** `app/Filament/Widgets/{PlanUsageWidget,LeadsOverviewWidget,RecentContactsWidget}.php` (nuevos), `resources/views/filament/cms/widgets/plan-usage-widget.blade.php` (nuevo), `app/Filament/Resources/PageResource.php` (`navBadgeUsage()` → `public`), `app/Filament/Pages/ApiTokens.php` (`activeTokensCountForTenant()` → `public`), `app/Providers/Filament/PanelCmsProvider.php` (registro de los 3 widgets).
- **Verificado:** balance de paréntesis/llaves/corchetes de los 5 archivos PHP nuevos/tocados, confirmado en 0. Sin tests automatizados nuevos (son widgets de solo lectura sobre queries ya cubiertas indirectamente por otros tests).
- **Siguiente:** el Tech Lead debe recargar el Escritorio y confirmar visualmente: las 8 barras de `PlanUsageWidget` con sus colores correctos según el consumo real de CICA360, las 3 stat cards de leads, y la tabla de últimos contactos (vacía hasta que llegue el primer submit real de formulario, si CICA360 todavía no tiene contactos de prueba sembrados). `npm run build`/`dev` no es necesario para estos 3 widgets — solo usan clases Tailwind ya cubiertas por el `@source` existente, no CSS nuevo en `theme.css`.

## 2026-09-13 — Footer propio para la página de Contacto (sin el CTA), `Cliente0ContentSeeder`
- **Agente/autor:** Claude (Tech Lead asistido)
- **Contexto/motivación:** con captura mostrando 2 Secciones ya en Studio ("Footer Contactos" y "Footer principal", el primero creado a mano por el Tech Lead para probar el layout — solo Colophon + Barra inferior, sin CTA), pedido: "actualizar el seeder con contenido inicial, tiene que haber un footer adicional para contactos... en esa sección footer de contactos solo tenga el colophon y la barra inferior con sus propiedades, osea lo mismo que el footer principal pero sin el bloque CTA". El CTA "¿Listo para transformar tu negocio?" invita a ir a la página de Contacto — tiene sentido en el resto del sitio, pero es redundante en la propia página de Contacto (el visitante ya está ahí).
- **Qué se hizo:**
  1. Extraído el Colophon + FooterBottom de `upsertFooterPage()` a un método nuevo `footerColophonAndBottomBlocks()` — mismo contenido exacto (columnas de marca/contacto/redes, copyright), reusado por ambos footers para que nunca queden desincronizados si se edita uno.
  2. Nuevo `upsertFooterContactoPage(Tenant $tenant): Page` — Page tipo `Footer`, slug `footer-contactos`, título "Footer Contactos", con SOLO `footerColophonAndBottomBlocks()` (sin CTA).
  3. `run()`: la página `contacto` ahora recibe su bloque `footer` apuntando a `footer-contactos` (`appendFooterBlock($pages['contacto'], $tenant, $pages['footer-contacto']->id)`), separado del `foreach` que sigue usando `footer-principal` para el resto (`sobre-cica`/`servicios`/`casos-de-exito`/`home`).
- **Archivos/áreas:** `database/seeders/Cliente0ContentSeeder.php`.
- **Verificado:** balance de paréntesis/llaves/corchetes del archivo completo, confirmado en 0.
- **Atención antes de correr el seeder:** el "Footer Contactos" que ya existe en Studio (creado a mano por el Tech Lead) tiene que tener el slug **`footer-contactos`** exacto para que `Page::updateOrCreate` lo reconozca como el mismo registro y solo actualice sus bloques — si el slug quedó distinto (autogenerado de otro título), correr `php artisan db:seed --class=Cliente0ContentSeeder` crearía una TERCERA página de footer en vez de actualizar la existente. Más simple: borrar el "Footer Contactos" creado a mano en Studio y dejar que el seeder lo recree limpio con el slug correcto.
- **Siguiente:** el Tech Lead debe correr `php artisan db:seed --class=Cliente0ContentSeeder` (idempotente) y confirmar en Studio: "Footer Contactos" con Colophon + Barra inferior únicamente (sin CTA), y que la página "Contacto" del sitio público muestre ESE footer (no el principal) tras el próximo build/deploy de cica360.

### Corrección mismo día — límite de Páginas de Auspicio: 7 → 10
Pedido del Tech Lead: "para el plan auspicio cambiar limites, 10 paginas 5 legales y 5 secciones". `Tenant::maxContentsPerType()`, rama `sponsorship`: `Page`/`Landing` de `7` a `10` — Legales y Secciones quedan sin cambios (ya estaban en 5). Mismo archivo/método que la vuelta anterior de este mismo día (ver más abajo) — el badge del sidebar y las tabs de `ManagePages` reflejan el nuevo tope automáticamente, sin tocar nada más (reusan `Tenant::maxContentsPerType()` en runtime, no un valor cacheado).

### Corrección mismo día — auditoría de tono: sin voseo rioplatense en copy de Stamless (ADR-051)
El Tech Lead notó voseo colado en copy nuevo/existente de Studio ("Ya tenés acceso...", "elegí una nueva expiración...") y recordó la regla ya establecida en ADR-051: el producto Stamless (Console/Studio) se escribe en español neutro — el voseo es exclusivo del contenido de marca de CICA360. Auditoría por grep de `app/Filament/**` y `resources/views/filament/**` encontró 6 instancias (algunas preexistentes de sesiones anteriores, no solo de esta vuelta) — todas corregidas a construcciones neutras/infinitivas, mismo criterio que ya usa el resto del proyecto:
- `PlanStatusWidget` (blade): "Ya tenés acceso..." → "Incluye acceso..."; "Desbloqueá más contenido..." → "Desbloquear más contenido...".
- `ApiTokens.php`: helper text de regenerar token ("elegí una nueva expiración" → "elegir una nueva expiración"); mensaje de límite de tokens ("revocá primero... esperá a que expire" → "revocar primero... esperar a que expire").
- `Preferences.php`: subheading ("Cómo querés ver fechas..." → "Cómo se muestran las fechas...", impersonal).
- `api-tokens.blade.php`: banner de token plaintext ("Guardá este token..." → "Guardar este token..."; "Si lo perdés, tenés que revocarlo..." → "Si se pierde, hay que revocarlo...").
- `api-playground.blade.php`: estado vacío del response ("Elegí un ejemplo... completá el token y apretá Send" → "Elegir un ejemplo... completar el token y presionar Send").
- **Archivos:** `app/Filament/Pages/{ApiTokens,Preferences}.php`, `resources/views/filament/{cms/widgets/plan-status-widget,pages/api-tokens,pages/api-playground}.blade.php`.

### Corrección mismo día — nuevo `PlanStatusWidget` (plan actual + botón "Mejorar plan")
Con captura mostrando el widget de bienvenida solo en su fila (columna 1 de 2, la otra vacía), el Tech Lead pidió: "falta un widget en segundo orden que seria para mostrar el plan y con un boton de mejorar plan". Se revirtió el `columnSpan = 'full'` que le acababa de poner a `WelcomeWidget` (tapaba el hueco estirando el saludo, sin agregar información) y en su lugar se creó `App\Filament\Widgets\PlanStatusWidget` (columna 1, comparte la primera fila con `WelcomeWidget` en columna 2):
- Muestra el plan actual (`Tenant::planLabel()`) y un badge "Plan más completo" cuando el tenant ya está en el plan más alto (`Tenant::canPersonalizeStudioBrand()`, mismo gate que ya usa el brand de Studio).
- Botón `mailto:` (NO un flujo de pago real — billing sigue "Fuera de alcance" en `TASK.md`) hacia `config('stamless.contact.sales_email')` (nueva config, `.env`: `STAMLESS_SALES_EMAIL`), con asunto/cuerpo precompletados (nombre del tenant + plan actual). Corrección del Tech Lead el mismo día ("para el auspicio quitar botón contactar con ventas"): en vez de un botón alternativo "Contactar a ventas" para el plan más alto, se oculta por completo con `@unless ($onTopPlan)` — no hay nada que "mejorar" para quien ya está en el plan más completo, así que no tiene sentido ningún CTA ahí.
- Reordenado el `$sort` de los 5 widgets del Escritorio a valores espaciados de a 10 (`Welcome=-50, PlanStatus=-40, Leads=-30, PlanUsage=-20, RecentContacts=-10`) para poder insertar uno nuevo en el medio a futuro sin renumerar todo.
- **Archivos:** `app/Filament/Widgets/PlanStatusWidget.php` (nuevo), `resources/views/filament/cms/widgets/plan-status-widget.blade.php` (nuevo), `app/Filament/Widgets/{Welcome,Leads,PlanUsage,RecentContacts}Widget.php` (`$sort`/`$columnSpan`), `config/stamless.php` (`contact.sales_email`), `.env.example` (`STAMLESS_SALES_EMAIL`), `app/Providers/Filament/PanelCmsProvider.php` (registro).
- **Nota de implementación:** el botón usa `<x-filament::button tag="a" :href="...">` (componente Blade ya probado en `WelcomeWidget`), evitando repetir el error ya corregido antes en esta sesión de adivinar el formato de las CSS custom properties de color de Filament en Tailwind v4.

### Corrección mismo día — layout del Escritorio: 2 columnas parejas, sin huecos
Con captura mostrando "Uso del plan" a todo el ancho y, debajo, "Últimos contactos" ocupando solo la mitad con espacio vacío al lado, el Tech Lead pidió: "en dos columnas estara bien, que ocupen una columna cada una". El Dashboard de Filament usa una grilla fija de 2 columnas (`Filament\Pages\Dashboard::getColumns()`) SIN "dense packing" — un widget que no encuentra lugar en la fila actual salta a la siguiente, dejando el resto de esa fila vacío para siempre si nada más lo ocupa. Ajuste de `$columnSpan` en 3 widgets:
- `PlanUsageWidget`: de `'full'` a `1` (columna, default de `Widget`) — la grilla interna de barras pasa de 2 columnas a 1 sola (ya no comparte fila con `RecentContactsWidget` en un espacio angosto apretado).
- `RecentContactsWidget`: `1` explícito (ya era el default, se documenta para que quede claro que comparte fila a propósito con `PlanUsageWidget`).
- `WelcomeWidget`: de `1` (heredado de `AccountWidget`/`Widget`) a `'full'` — mismo hueco que tenían "Uso del plan"/"Últimos contactos" existía arriba de todo, junto al saludo, sin que el Tech Lead lo mencionara todavía; se corrigió de una vez para no dejar el mismo bug sin resolver en otro lugar del mismo Escritorio.
Resultado: Bienvenida (fila propia, ancho completo) → Leads (fila propia, ancho completo — `StatsOverviewWidget` ya trae `'full'` por default) → Uso del plan + Últimos contactos (comparten la última fila, una columna cada uno, sin huecos).
- **Archivos:** `app/Filament/Widgets/{PlanUsageWidget,RecentContactsWidget,WelcomeWidget}.php`, `resources/views/filament/cms/widgets/plan-usage-widget.blade.php` (grid interno a 1 columna).

### Corrección mismo día — badge de "Contactos" a formato "nuevos/total"
Viendo el widget `LeadsOverviewWidget` ya andando, el Tech Lead pidió: "falta en contactos el contador, Contactos (Contactos nuevos/Total de contactos)". El badge del sidebar pasó de mostrar solo el conteo de pendientes ("Nuevo") a `"{nuevos}/{total}"` (ej. `"0/12"`) — misma idea que ya usa el widget de Leads (pendiente de atender sobre el total), pero NO es un "usado/límite" como el resto de los badges de la app (Contacts no tiene tope de plan). Sigue sin ocultarse en cero. Nuevo `totalCount()` privado junto al ya existente `pendingCount()`.
- **Archivos:** `app/Filament/Resources/ContactResource.php`.

### Corrección mismo día — badge de "Contactos" siempre visible + agrupado bajo "CRM"
Con captura del sidebar mostrando "Contactos" sin ningún contador (a diferencia de "Multimedia 36/60" justo debajo), el Tech Lead pidió: "no te olvides de poner contador de contactos (0) y poner en orden en otro grupo de Contactos u otro termino que sea mejor tener que englobe al futuro modulo de CRM". Dos ajustes en `ContactResource.php`:
1. `getNavigationBadge()`/`getNavigationBadgeColor()` ya no ocultan el badge en cero — antes devolvían `null` cuando no había leads "Nuevo" pendientes (criterio de "solo avisar si hay algo urgente"), ahora siempre muestran el número (`"0"` incluido), gris en 0 y rojo con pendientes — mismo criterio visual que el resto del sidebar, que nunca hace desaparecer su contador.
2. Nuevo `$navigationGroup` (mismo patrón `string|UnitEnum|null` que ya usan `ApiTokens`/`Preferences` para "Desarrolladores"/"Cuenta") — en vez de agrupar como "Contactos" (demasiado acoplado al nombre de este recurso puntual). Primer intento: `'CRM'`; el mismo día el Tech Lead lo bajó un escalón ("por ahora CRM creo que lo mantenemos de forma sutil porque en esta primera etapa se reirán al ver solo un listado de contactos") — se cambió a **`'Clientes'`**, término que describe lo mismo sin sonar a categoría de producto todavía sin sustento, y que sigue englobando con naturalidad lo que se sume después (pipeline de deals, actividades, reportes).

## 2026-09-13 — Dashboard Nivel 1 (Widget de bienvenida con badge de plan) + Nivel 2 (Contacts Resource)
- **Agente/autor:** Claude (Tech Lead asistido)
- **Contexto/motivación:** siguiente paso del plan de Dashboard por niveles (Nivel 1 = económico/sin dependencias/valor inmediato, ya con los badges de uso de la entrada de abajo; Nivel 2 = Contacts Resource; Nivel 3 = analítica de visitas/requests, requiere frontend o 3ros — sigue sin ejecutarse). El Tech Lead pidió explícitamente: "quitar el widget Filament, dejar el de bienvenida (pero agregar en un badge el tipo de plan), empezar por el Nivel 1 ya mismo... aprovechar esto como oportunidad para habilitar el Contacts Resource si el momento te lo permite (Nivel 2)".
- **Qué se hizo — Nivel 1 (widgets del Dashboard):**
  1. `Filament\Widgets\FilamentInfoWidget` (promo/versión del framework, sin valor para el cliente del panel) sacado de `PanelCmsProvider::widgets()`.
  2. Nuevo `App\Filament\Widgets\WelcomeWidget` (extiende `Filament\Widgets\AccountWidget`, vista propia `resources/views/filament/cms/widgets/welcome-widget.blade.php` clonada de la vendor) que agrega un `<x-filament::badge>` con `Tenant::planLabel()` junto al nombre del usuario — verde (`success`) para planes pagos (mismo gate que `canPersonalizeStudioBrand()`), gris para Free/Freemium. Reemplaza a `AccountWidget` nativo en `PanelCmsProvider::widgets()`.
- **Qué se hizo — Nivel 2 (Contacts Resource):** el dominio (`Contact`/`ContactActivity`/`ContactPolicy`/`ContactSubmissionService`/`DataMasker`, ver ADR-015) ya existía completo desde el cierre de la API — solo faltaba la pantalla en Studio. Nuevo `App\Filament\Resources\ContactResource` (patrón `ManageRecords`, sin `getPages` de create):
  - **Sin alta manual** (`canCreate(): false`): un Contact nace de un envío de formulario real, no de un alta editorial — a diferencia de Testimonios/Páginas/etc.
  - Tabla: nombre+email (fusionados vía `->description()`), estado (badge coloreado por `ContactStatusEnum`), origen, formulario de origen, asignado a, fecha de recepción (`FriendlyDate`). Filtros por estado, asignado y formulario. Orden por defecto: más recientes primero.
  - Modal de gestión (`EditAction` en slide-over): datos del contacto editables (nombre/email/teléfono/empresa — el cast `encrypted` de `Contact` descifra/cifra de forma transparente), respuestas dinámicas del formulario (`content.data` vía `KeyValue` deshabilitado), gestión (estado/asignado a/último contacto/notas internas), y una línea de tiempo de solo lectura de `ContactActivity` (mismo patrón de armar el badge a mano con `FilamentColor`/`BadgeComponent` que ya usa `PageResource::renderTypeBadge()` — este proyecto no usa Infolists en ningún otro Resource, no se introduce acá).
  - Acción de fila "Agregar nota" (modal chico, un solo `Textarea`) que crea un `ContactActivity` tipo `note` sin necesidad de abrir el modal completo — pensado para el caso más común ("lo llamé, dejo constancia").
  - Acciones masivas: marcar en proceso/cerrado, eliminar.
  - Badge de nav = cantidad de contactos en estado "Nuevo" (no el total histórico — lo que importa es lo pendiente de atender), color `danger`, oculto en 0.
  - **Decisión explícita de exposición:** `email`/`phone`/`company` se muestran DESCIFRADOS (no vía `DataMasker`) en Studio — el propósito completo del Resource es que el tenant pueda contactar a su propio lead; `#[Hidden(...)]` del modelo sigue intacto y solo afecta la serialización de la API pública, no el acceso a atributos que usa Filament acá.
- **Archivos/áreas:** `app/Filament/Widgets/WelcomeWidget.php` (nuevo), `resources/views/filament/cms/widgets/welcome-widget.blade.php` (nuevo), `app/Providers/Filament/PanelCmsProvider.php` (widgets), `app/Filament/Resources/ContactResource.php` + `ContactResource/Pages/ManageContacts.php` (nuevos).
- **Verificado:** balance de paréntesis/llaves/corchetes en los 4 archivos PHP nuevos/tocados, confirmado en 0 (sin runtime PHP en este sandbox). Sin tests automatizados nuevos — deuda conocida (el dominio de `Contact` ya tiene cobertura en `ContactSubmissionServiceTest`; falta cobertura de Filament sobre `ContactResource` en sí).
- **Siguiente:** el Tech Lead debe recargar Studio y confirmar visualmente: (a) el widget de bienvenida ya no tiene el bloque de "Filament" debajo, y el nombre del usuario muestra el badge de plan; (b) "Contactos" aparece en el sidebar con su ícono y badge de pendientes; (c) al menos un contacto de prueba (via `POST forms/{slug}/submit` o los ya sembrados de CICA360, si los hay) se ve completo, editable, y "Agregar nota" registra la actividad en la línea de tiempo. Actualizado `TASK.md` para sacar "Filament Resource de Contacts" de "Fuera de alcance ahora". El Nivel 3 del plan (contador de visitas por página, analítica de requests/responses) sigue pausado, requiere decisiones de infraestructura (frontend cooperando o 3ros) fuera del alcance de esta vuelta.

## 2026-09-13 — Badges de uso (sidebar + tabs) + límite de Menús + límites de Contenidos por tipo
- **Agente/autor:** Claude (Tech Lead asistido)
- **Contexto/motivación:** el Tech Lead pidió, a partir del análisis de Dashboard (ver más abajo, "Nivel 1" del plan por niveles), que cada opción del sidebar que ya tiene un límite de plan muestre su consumo ("Contenidos 7/10") y que las tabs internas de Contenidos muestren el conteo activo por tipo ("Páginas (5)", "Legales (0)", "Secciones (1)") — "eso si implementa en una, mientras voy leyendo el resto del plan". Sobre la marcha agregó dos ajustes de negocio: un límite de **cantidad de Menús** (antes sin tope) y una revisión de los límites de **Contenidos por tipo** (antes un solo número por plan aplicado por igual a Página/Legal/Footer).
- **Qué se hizo:**
  1. Trait nuevo `App\Filament\Concerns\FormatsUsageBadge` (`formatUsageBadge(count, limit)` → `"7/10"` o `"7"` si el plan no tiene tope; `usageBadgeColor(count, limit)` → gris / `warning` al 80% / `danger` al 100%+) para no repetir esta lógica en cada Resource.
  2. `getNavigationBadge()`/`getNavigationBadgeColor()` agregados a `PageResource`, `PostResource`, `ServiceResource`, `SliderResource`, `TestimonialResource`, `MediaResource`, `MenuResource` y a la página `ApiTokens` (los 3 primeros usan `Tenant::maxX()` ya existentes; `ApiTokens` suma un helper `activeTokensCountForTenant()` porque `getNavigationBadge()` es estático y el conteo original vivía en un método de instancia).
  3. `ManagePages::getTabs()` reescrito: cada tab (Páginas/Legales/Secciones) muestra `->badge()` con el conteo activo de ESE tipo puntual para el tenant actual (sin fracción — la tab ya representa un tipo, repetir el límite 3 veces sería ruido).
  4. **Límite de Menús** (pedido mid-turn: "son 5 menús límite sea free o auspicio"): `Tenant::maxMenus()` nuevo — `5` fijo para Free/Freemium/Auspicio (a diferencia de los demás límites, que sí varían por plan). `MenuResource` gana `isMenuLimitReached()`/`menuLimitMessage()` y el guard completo (`disabled`/`tooltip`/`before` con notificación) tanto en el botón de "Crear Menú" como en el `ReplicateAction` de duplicar.
  5. **Límites de Contenidos por tipo** (pedido mid-turn, con captura de las tabs ya funcionando: "límite máximo de 7 páginas, 5 legales y 5 secciones para auspicio. y para free que sea 3 legales y 3 secciones"): `Tenant::maxContentsPerType()` pasó de `(): ?int` (un solo número por plan) a `(PageTypeEnum $type): ?int` (un número por plan **y** por tipo) — Free: Página/Landing 5 (sin cambio, no pedido)/Legal 3/Footer 3; Auspicio: Página/Landing 7/Legal 5/Footer 5. `PageResource::isContentLimitReached()`/`contentLimitMessage()` ahora reciben y reenvían el `$type`. El badge agregado del sidebar (`PageResource::getNavigationBadge()`) ya no puede multiplicar "un límite" × 3 tipos: nuevo `navBadgeUsage()` privado suma el conteo y el límite propio de cada uno de los 3 tipos (`NAV_BADGE_TYPES`, sin `Landing` — no tiene acción de "Crear" habilitada en el MVP), tratando el agregado como "sin límite" si CUALQUIER tipo individual no tiene tope (evita mostrar una fracción a medias).
- **Archivos/áreas:** `app/Filament/Concerns/FormatsUsageBadge.php` (nuevo), `app/Models/Tenant.php` (`maxMenus()` nuevo, `maxContentsPerType()` reescrito con parámetro), `app/Filament/Resources/{Page,Post,Service,Slider,Testimonial,Media,Menu}Resource.php`, `app/Filament/Resources/PageResource/Pages/ManagePages.php` (tabs con badge), `app/Filament/Pages/ApiTokens.php`.
- **Verificado:** balance de paréntesis/llaves/corchetes de los 3 archivos más tocados (`Tenant.php`, `PageResource.php`, `MenuResource.php`) confirmado en 0 con script Python (sin runtime PHP en este sandbox). Sin tests automatizados nuevos para esta vuelta — deuda conocida, no bloqueante (son badges de solo lectura + límites que ya seguían el patrón probado de `isXLimitReached()`).
- **Siguiente:** el Tech Lead debe recargar Studio y confirmar visualmente: badge del sidebar de "Contenidos" mostrando la suma correcta (ej. Auspicio con 6 Páginas + 1 Legal + 1 Footer → "8/17"), tabs de Páginas mostrando el conteo por tipo, botón "Crear Menú" deshabilitado al llegar a 5, y los nuevos topes de Contenidos por tipo aplicados en la práctica. El resto del plan de Dashboard (Nivel 2: Contacts Resource, Nivel 3: analítica de visitas/requests) sigue en revisión del Tech Lead — ver entradas siguientes para lo ya autorizado a ejecutar.

## 2026-09-13 — Panel Studio: sacar el selector de tenant, brand con el nombre del tenant (gateado por plan) + bloque de plan para Free
- **Agente/autor:** Claude (Tech Lead asistido)
- **Contexto/motivación:** el Tech Lead reportó (captura) que el sidebar de Studio mostraba "Stamless" como marca del panel y, debajo, un bloque "C  CICA360  ⌄" que parece un selector de tenant/proyecto — confuso porque insinúa que la cuenta podría tener acceso a más de un tenant/proyecto, cuando el modelo de datos actual es estrictamente 1 cuenta = 1 tenant (`User::getTenants()` siempre devuelve 0 o 1 elemento, `tenant_id` es columna propia de `User`, no un pivot muchos-a-muchos — confirmado leyendo el modelo). Confirmado con el Tech Lead que esto queda así por ahora ("está bien dejar uno por tenant"); si el negocio pide multi-proyecto en el futuro, es un cambio de arquitectura con su propio ADR. Aclarado además que esto es SOLO para el panel Studio (`PanelCmsProvider`) — el panel Manager/Plataforma (`PanelPlatformProvider`) no tiene `->tenant()` configurado (nunca tuvo tenancy activo, ve todos los tenants), queda intacto.
- **Qué se hizo, versión final:**
  1. `->tenantMenu(false)` en `PanelCmsProvider::panel()` — saca el bloque completo del selector del sidebar (Filament lo gatea entero con `@if (hasTenancy() && hasTenantMenu())` en `sidebar.blade.php`, no queda ni el ícono ni la flecha).
  2. Nuevo gate de plan en el modelo: `Tenant::canPersonalizeStudioBrand()` (Free/Freemium → `false`, Auspicio/Convenio y cualquier plan pago → `true` — mismo criterio que `canEditCopyright()`, pero método propio porque son decisiones de negocio distintas aunque hoy coincidan) y `Tenant::planLabel()` (etiqueta legible: "Free", "Auspicio/Convenio", o `ucfirst($plan)` de fallback).
  3. `->brandName()` en `PanelCmsProvider`: para tenants que pueden personalizar, muestra `"{nombre del tenant} Studio"` (ej. "CICA360 Studio"); para Free/Freemium, muestra el genérico `"Stamless Studio"` (`config('app.name')` + el mismo sufijo); sin tenant resuelto (login), solo `"Stamless"` sin sufijo. El sufijo "Studio" va en `<span class="fi-logo-suffix">` con estilo propio en `resources/css/filament/cms/theme.css` — iterado en vivo con el Tech Lead varias veces (chico+opaco → thin → versión final: `font-size: 1.05em` — un toque más grande que el nombre —, `font-weight: 300` — light — y `opacity: .75`) para que lea como sufijo elegante sin perder legibilidad. `HtmlString` porque `getBrandName()` se imprime con `{{ }}` en el Blade de Filament (`components/logo.blade.php`) y Blade no escapa un `Htmlable`; el nombre real del tenant se escapa con `e()` antes de insertarlo (dato de usuario).
  4. Donde estaba el selector (mismo hook de Filament, `SIDEBAR_LOGO_AFTER`) ahora hay un `renderHook` que, SOLO para tenants Free/Freemium (`! canPersonalizeStudioBrand()`), inyecta un bloque estático (`resources/views/filament/cms/sidebar-project-info.blade.php`, sin dropdown ni flecha) con el nombre real del proyecto arriba y "Plan actual: Free" debajo en letra chica/atenuada — es el único lugar donde un tenant Free ve su nombre de proyecto real, ya que su brand de arriba muestra el genérico "Stamless Studio". Para tenants de pago (Auspicio/Convenio, etc.) el hook no renderiza nada — "los auspicios y otros planes de pago solo mostrarán el titular Studio" (ya ven su nombre real personalizado arriba, este bloque sería redundante).
- **Archivos/áreas:** `app/Models/Tenant.php` (`canPersonalizeStudioBrand()`, `planLabel()`), `app/Providers/Filament/PanelCmsProvider.php` (`tenantMenu(false)`, `brandName()`, nuevo `renderHook(SIDEBAR_LOGO_AFTER, ...)`), `resources/views/filament/cms/sidebar-project-info.blade.php` (nuevo), `resources/css/filament/cms/theme.css` (`.fi-logo-suffix`, `.fi-project-info*`).
- **Siguiente:** el cambio de PHP se ve con solo recargar el panel; el CSS necesita `npm run build` (o `npm run dev`/`composer run dev`). El Tech Lead debe verificar visualmente con al menos un tenant Free/Freemium (para ver el bloque "Plan actual") y uno de pago/Auspicio (para confirmar que NO aparece ese bloque, solo el brand personalizado).

## 2026-09-13 — Fix: `is_home` no se desactivaba en las demás páginas al editar desde el form
- **Agente/autor:** Claude (Tech Lead asistido)
- **Qué se hizo:** bug reportado por el Tech Lead con captura (2 páginas — "Home" y "Home (copia)" — quedaron con el check verde de "Inicio" a la vez, tras duplicar Home y activarle `is_home` a la copia desde el form de edición). Causa raíz: la invariante "solo 1 página Home por tenant" SOLO estaba implementada a mano dentro del ícono clickeable de la columna "Inicio" en `PageResource::table()` — el `Toggle::make('is_home')` del form de editar/crear (`HeadingFieldset`) simplemente guardaba el valor tal cual, sin desactivar la página que antes era Home. Se centralizó la regla en un hook `Page::booted()` → `static::saving()`: cualquier guardado (form, ícono de tabla, factories, etc.) que deje `is_home=true` desactiva automáticamente cualquier OTRA página `is_home=true` del mismo tenant (scope por `tenant_id`, no por `lang_iso` — Home es a nivel de sitio). El ícono de la tabla se simplificó para solo togglear su propio valor (`$record->update(['is_home' => ! $record->is_home])`), ya que el hook del modelo cubre el resto.
- **Archivos/áreas:** `app/Models/Page.php` (nuevo `booted()`), `app/Filament/Resources/PageResource.php` (simplificación de la action `toggleIsHome`).
- **Siguiente:** el dato ya duplicado en la captura (2 páginas con `is_home=true`) se corrige solo, sin migración de datos: basta con hacer clic en el ícono "Inicio" de "Home (copia)" en la tabla para apagarla (ahora sí queda solo "Home" en `true`). El Tech Lead debe correr `php artisan test` (no hay runtime de PHP en este sandbox) y verificar el escenario: duplicar Home → activar Home en la copia desde el form → confirmar que la original se desactiva sola.

## 2026-09-13 — Fix UX: footer del modal de Duplicar pegado al texto de arriba
- **Agente/autor:** Claude (Tech Lead asistido)
- **Qué se hizo:** el Tech Lead reportó (captura) que la barra de botones del modal de confirmación de "Duplicar" se veía pegada a la línea/texto de arriba, sin el padding-top estándar, y sugirió alternativamente centrar los botones. Se aplicaron ambos ajustes: (1) `->modalFooterActionsAlignment('center')` en los 6 `ReplicateAction` (Page/Post/Service/Testimonial/Slider/Menu) para centrar la fila de botones; (2) un override de CSS en el theme del panel (`.fi-modal-footer-actions { padding-top: 1rem !important; }`) que fuerza separación estándar en TODOS los modales de confirmación sin campos propios (Duplicar, Eliminar, etc.) — Filament solo agrega `mt-6` automático cuando detecta que el modal "no tiene contenido", condición que en la práctica no siempre se cumple.
- **Archivos/áreas:** `app/Filament/Resources/{Page,Post,Service,Testimonial,Slider,Menu}Resource.php` (alineación del footer), `resources/css/filament/cms/theme.css` (override de padding).
- **Siguiente:** requiere `npm run build` (o `npm run dev`/`composer run dev`) para que el cambio de CSS se vea reflejado — el Tech Lead debe correrlo y verificar visualmente el modal de Duplicar en al menos un resource.

## 2026-09-13 — Duplicar extendido a Menús/Sliders/Testimonios + modal de confirmación más amigable (ADR-061, addendum)
- **Agente/autor:** Claude (Tech Lead asistido)
- **Qué se hizo:** a pedido del Tech Lead ("no hay en menus, selider y testimonios no hay duplicar"), se agregó `Actions\ReplicateAction` a `TestimonialResource` (sin slug ni hijos: copia oculta), `SliderResource` (slug único + clona `Slide`s hijos conservando sus FKs a `Media`) y `MenuResource` (slug único + clona recursivamente el árbol completo de `MenuItem`s hasta 3 niveles vía nuevo helper `duplicateMenuItemsRecursive()`, reasignando `parent_id` a los IDs ya guardados de las copias). Además, tras ver el modal de confirmación por defecto ("Replicar :label" / botón "Replicar" — inconsistente con el label "Duplicar" del botón y sin explicar qué pasa), el Tech Lead pidió "hacerlo mas amigables, mas UX": se agregó `->modalHeading()`, `->modalDescription()` y `->modalSubmitActionLabel('Sí, duplicar')` con copy propio en los 6 resources con Duplicar (Page, Post, Service, Testimonial, Slider, Menu), explicando en una frase qué se clona y en qué estado queda la copia.
- **Archivos/áreas:** `app/Filament/Resources/TestimonialResource.php`, `SliderResource.php`, `MenuResource.php` (ReplicateAction + helpers nuevos), `PageResource.php`, `PostResource.php`, `ServiceResource.php` (solo el copy del modal, sin tocar la lógica de duplicado ya existente), `docs/context/DECISIONS.md` (addendum a ADR-061).
- **Siguiente:** escribir tests automatizados para la feature Duplicar en los 6 resources (slug único, estado inicial de la copia, clonado correcto de blocks/slides/árbol de menu_items) — sigue pendiente desde el ADR original, no se pudo correr `php artisan test` en este sandbox por falta de runtime de PHP. El Tech Lead debe verificar visualmente el nuevo copy del modal y correr la suite de tests en su entorno.

## 2026-09-13 — Acción "Duplicar" en Páginas, Publicaciones y Servicios (ADR-061)

- **Pedido del Tech Lead**: "deberia tener duplicar, de esa forma, facilitar a los usuarios o clientes que puedan replicar paginas o publicaciones o servicios para editarlos con sus properties definidos y asi heredar lo configurado anteriormente".
- **Qué se hizo**: `Actions\ReplicateAction` agregada al menú de acciones de fila (dentro del `ActionGroup` de la vuelta anterior) en `PageResource`, `PostResource`, `ServiceResource`. El duplicado nace como Borrador (nunca hereda `status`/`published_at` del original), con título "{título} (copia)" y un slug único generado (`{slug}-copia`, `-copia-2`, etc. si ya existe). `uuid` se excluye explícitamente para que `HasUuid` le genere uno nuevo al guardar (si se copiara, dos filas terminarían compartiendo el mismo UUID público). En `PageResource`, además: `is_home` se fuerza a `false` (solo puede haber una página Home por tenant) y un `->after()` clona cada `Block` hijo de la página original apuntándolo al nuevo `page_id` — sin esto, duplicar una página daría una página vacía sin el contenido real (que vive en los bloques, no en columnas de `Page`). Duplicar respeta el mismo límite de plan que crear un registro nuevo (`isContentLimitReached()`/`isPostLimitReached()`/`isServiceLimitReached()`, mismo trío `->disabled()`/`->tooltip()`/`->before(...$action->halt())` ya usado en los `CreateAction` de estos resources) — no debe ser una forma de esquivar el tope.
- **Archivos**: `app/Filament/Resources/PageResource.php`, `app/Filament/Resources/PostResource.php`, `app/Filament/Resources/ServiceResource.php`. Ver ADR-061 en `DECISIONS.md` para el detalle completo y las alternativas descartadas.
- **Verificación**: balance de llaves/paréntesis/corchetes (script que ignora strings/comentarios) en los 3 archivos, todo en 0. Se confirmó leyendo el código fuente de Filament (`Actions/ReplicateAction.php`, `Support/Concerns/EvaluatesClosures.php`) que los closures `beforeReplicaSaved()`/`after()`/`before()`/`disabled()`/`tooltip()` resuelven `$record` (registro original) y `$replica`/`$action` (según el nombre del parámetro) automáticamente, sin necesidad de pasarlos a mano. **Sin runtime de PHP en este sandbox** — no se pudieron escribir/correr tests para esta feature todavía.
- **Siguiente**: escribir al menos un test por resource (slug único al duplicar, `status` = Draft, `is_home` = false en Page, blocks clonados) y correrlos. Confirmar visualmente que la acción "Duplicar" aparece en el menú de cada fila y que el duplicado abre editable con todo lo esperado. Si el Tech Lead quiere lo mismo en Menús/Sliders/Testimonios, extender el mismo patrón (no se incluyó en esta vuelta porque el pedido nombraba explícitamente solo Páginas/Publicaciones/Servicios).

## 2026-09-13 — Fecha pub. debajo de Estado también en Publicaciones (addendum ADR-059)

- **Pedido del Tech Lead**: "lo mismo en publicaciones" (screenshot de `PostResource`).
- **Qué se hizo**: mismo cambio que en `PageResource` — `description()` en la columna `status` mostrando la fecha de publicación, columna suelta `published_at` eliminada.
- **Archivos**: `app/Filament/Resources/PostResource.php`.
- **Verificación**: balance de llaves/paréntesis/corchetes (script que ignora strings/comentarios). Sin runtime de PHP en este sandbox.
- **Siguiente**: confirmación visual del Tech Lead.

## 2026-09-13 — Agrupar acciones de fila en todos los módulos + Fecha pub. debajo de Estado en Páginas (addendum ADR-059)

- **Pedidos del Tech Lead**: (1) "los listados de cada apartado o modulo, agrupar las acciones" (con screenshot del menú lateral: Menús, Contenidos, Publicaciones, Servicios, Sliders, Testimonios); (2) sobre la tabla de Páginas: "la fecha de publicacion pasar debajo del estado como segunda linea".
- **Qué se hizo**:
  1. Mismo patrón `Actions\ActionGroup::make([...])` aplicado en `ApiTokens::table()` (vuelta anterior), ahora en el `->actions([...])` de las 7 tablas de Resources: `MenuResource`, `PageResource`, `PostResource`, `ServiceResource`, `SliderResource`, `TestimonialResource`, `MediaResource` (esta última no aparece en el menú del screenshot pero tenía el mismo problema de acciones sueltas, así que se incluyó por consistencia). En cada caso se envuelven las acciones existentes (Editar/Eliminar, y en `PageResource` también Restaurar/Borrado permanente) sin tocar su lógica interna.
  2. En `PageResource::table()`, la columna `status` (badge "Estado") suma `->description(fn (Page $record) => FriendlyDate::format($record->published_at))` — misma técnica que ApiTokens. La columna suelta `published_at` ("Fecha pub.") se saca de la tabla por quedar duplicada.
- **Archivos**: `app/Filament/Resources/{MenuResource,PageResource,PostResource,ServiceResource,SliderResource,TestimonialResource,MediaResource}.php`.
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python — esta vez con una versión mejorada que primero saca strings/comentarios antes de contar (la versión naive daba falsos positivos por paréntesis dentro de texto en español). Los 7 archivos dan balance 0. Sin runtime de PHP en este sandbox para correr los tests de Filament de cada resource.
- **Siguiente**: correr la suite de tests de Filament (`ApiTokensRegenerateTest`, y los tests de cada Resource si existen) y confirmar visualmente el menú agrupado en cada listado + la fecha bajo Estado en Páginas.

## 2026-09-13 — Agrupar acciones de fila en un menú (16va vuelta, addendum ADR-059)

- **Pedido del Tech Lead**: "ahora agrupar las acciones" — Regenerar/Editar plataforma-origen/Revocar ocupaban bastante ancho como 3 enlaces sueltos en cada fila.
- **Qué se hizo**: las 3 se envuelven en `Actions\ActionGroup::make([...])` — patrón estándar de Filament para acciones de fila, un solo botón con menú desplegable en vez de 3 enlaces.
- **Archivos**: `app/Filament/Pages/ApiTokens.php`.
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff). Se confirmó en el código fuente de Filament (`Table/Concerns/HasActions.php::getFlatActions()`) que las acciones agrupadas se siguen registrando de forma PLANA por nombre — los tests existentes (`ApiTokensRegenerateTest`, `ApiTokensEditOriginTest`) que llaman `callTableAction('regenerate', ...)`/`('editOrigin', ...)` por nombre simple siguen funcionando sin cambios, no hace falta ajustar el path a `['grupo', 'accion']`. Sin runtime de PHP en este sandbox para confirmarlo corriendo la suite.
- **Siguiente**: correr los tests de Filament una vez más y confirmar visualmente el menú.

## 2026-09-13 — Fix: leading excesivo en "Expira" bajo Token (15va vuelta, addendum ADR-059)

- **Reporte del Tech Lead**: "tiene mucho leading o altura la fecha de expiracion" — confirmado con el inspector del navegador (screenshot): el div `.fi-ta-text.fi-ta-text-has-descriptions` mostraba mucho aire vertical alrededor de "Expira: nunca".
- **Causa raíz**: la 14va vuelta envolvía el texto en `<span class="fi-ta-text fi-ta-text-item fi-size-sm ...">` para poder pintarlo de rojo si el token venció. Esas clases son las de un ITEM de la lista interna de `TextColumn` (pensadas para alinearse con badges/íconos) — `.fi-ta-text-item.fi-size-sm` trae `leading-6` (24px de interlineado) en `text.css`, muy por encima de lo que corresponde a texto secundario chico. Ese span quedaba anidado DENTRO del `<p class="fi-ta-text-description">` que Filament ya pone automáticamente alrededor de `description()` y que ya trae `text-sm text-gray-500` correctos (`text.css`, selector `.fi-ta-text > .fi-ta-text-description`).
- **Qué se hizo**: si el token no venció, ahora es texto plano sin ningún `<span>` extra (hereda el tamaño/color correctos del `<p>` padre). Si venció, se envuelve en un `<span>` con SOLO las clases de color (`FilamentColor::getComponentClasses(ItemComponent::class, 'danger')`), sin las clases `fi-ta-text-item`/`fi-size-sm` que causaban el interlineado de más.
- **Archivos**: `app/Filament/Pages/ApiTokens.php`.
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff). Sin runtime de PHP en este sandbox.
- **Siguiente**: confirmación visual del Tech Lead.

## 2026-09-13 — Limpieza de columnas duplicadas: quitar Plataforma/Dominio/Creado, ajustar la descripción, mover Expira bajo Token (13va-14va vuelta, addendum ADR-059)

- **Pedidos del Tech Lead** (en orden): "quitar 'Dominio:'", "quitar 'Creado:'", "pero por que sigue existiendo las columnas plataforma y dominio permitido", "la columna creado tambien", "poner la fecha de expiración debajo del token".
- **Qué se hizo**:
  1. La descripción bajo "Nombre" quedó como `{fecha} · [badge Plataforma] · {dominio}`, sin los prefijos "Creado:"/"Dominio:" (solo texto plano + badge).
  2. Se sacan las columnas sueltas `platform` y `allowed_origin` de la tabla — quedaban duplicadas con esa descripción. Editar plataforma/origen de un token sigue disponible vía la acción de fila "Editar plataforma/origen" (sin cambios). El bridge `triggerEditOriginAction()` (que solo servía para la columna clickeable que ya no existe) se borra por quedar sin ningún llamador.
  3. Se saca la columna suelta `created_at` — duplicada con la misma descripción. `->defaultSort('created_at', 'desc')` sigue andando igual (ordena contra la query, no depende de que exista un `TextColumn` visible con ese nombre).
  4. `expires_at` se fusiona como `description()` de la columna `last_four` (Token): "Expira: {fecha}", en rojo si ya expiró — mismo mecanismo que el badge de Plataforma pero usando `FilamentColor::getComponentClasses(ItemComponent::class, ...)` (la utilidad que usa Filament para texto NO-badge, en vez de `BadgeComponent::class`). La columna `expires_at` independiente se saca de la tabla.
- **Archivos**: `app/Filament/Pages/ApiTokens.php` (nuevo import `Filament\Tables\View\Components\Columns\TextColumnComponent\ItemComponent`), `tests/Feature/Filament/ApiTokensEditOriginTest.php` (el test que verificaba el click en la columna `allowed_origin` ya no aplica — se reemplaza por `test_the_edit_origin_row_action_mounts_for_an_existing_token`, que monta la acción de fila directo en vez de simular un click de columna que ya no existe).
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff) en ambos archivos. Sin runtime de PHP en este sandbox.
- **Siguiente**: correr `php artisan test --compact tests/Feature/Filament/ApiTokensEditOriginTest.php tests/Feature/Filament/ApiTokensLimitTest.php tests/Feature/Filament/ApiTokensPlatformFieldVisibilityTest.php` y confirmar visualmente que la tabla quedó como se pidió: Nombre (con fecha + badge + dominio debajo), Permisos, Token (con fecha de expiración debajo), Último acceso (oculta por defecto).

## 2026-09-13 — Segunda fila bajo Nombre con badge real de Plataforma (12va vuelta, addendum ADR-059)

- **Pedido del Tech Lead**: "ahora poner una segunda fila debajo de nombre conformado por: Creado + Plataforma (como badge) + Dominio (dominio solo si plataforma es web, si no dejará de mostrase)".
- **Qué se hizo**: `TextColumn::description()` en la columna `name` — funciona dentro de una celda de tabla normal (a diferencia de `Layout\Stack`/`Split`, abandonados en la 11va vuelta). Muestra "Creado: {fecha} · [badge Plataforma] · Dominio: {dominio}", con el segmento de Dominio omitido por completo cuando `platform !== 'web'` o no hay `allowed_origin` cargado. El badge de Plataforma es HTML real (no texto), armado con `FilamentColor::getComponentClasses(BadgeComponent::class, $color)` — la misma utilidad que usa Filament internamente para pintar sus propios badges (`TextColumn::toOptimizedHtml()`), para no depender de clases Tailwind inventadas a mano y respetar el tema claro/oscuro. El resultado se envuelve en `Illuminate\Support\HtmlString`: el render de `description()` usa el helper `e()` de Laravel, que devuelve el HTML tal cual (sin escapar) cuando el valor implementa `Htmlable`.
- **Archivos**: `app/Filament/Pages/ApiTokens.php` (nuevos imports: `Filament\Support\Facades\FilamentColor`, `Filament\Support\View\Components\BadgeComponent`, `Illuminate\Support\HtmlString`). Las columnas `platform`/`allowed_origin` de la tabla NO se tocaron — siguen existiendo como columnas propias (con su header), esta fila es información adicional a simple vista, no un reemplazo.
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff), y se confirmó en el código fuente de Filament (`Colors/ColorManager.php`, `View/Components/BadgeComponent.php`) que ambas clases/métodos usados existen tal cual. Sin runtime de PHP en este sandbox.
- **Siguiente**: confirmación visual del Tech Lead — es la primera vez en esta serie de vueltas que se inyecta HTML manual dentro de una tabla de Filament (antes solo se usó texto plano en `description()`), así que vale la pena que confirme que el badge se ve bien (colores, tamaño, alineación) antes de dar esto por cerrado.

## 2026-09-13 — Vuelta definitiva a tabla clásica (11va vuelta, addendum ADR-059)

- **Pedido del Tech Lead**: "nada, no sabes resolverlo aun, volvamos a table" — después de 5 vueltas intentando un layout de tarjetas con `Layout\Stack`/`Layout\Split` (7ma a 10ma vuelta) sin lograr un resultado visual correcto y sin forma de renderizar/depurar la UI real de Filament en este sandbox, se abandona el enfoque de tarjetas por completo.
- **Qué se hizo**: se sacan TODOS los `Stack`/`Split` de `->columns([...])` — vuelve a ser una tabla `<table>` clásica con columnas sueltas: Nombre, Permisos (badges múltiples), Plataforma (badge de color, se mantiene igual que se pidió desde el principio), Dominio permitido (clickeable a `editOrigin`, sin cambios), Token, Expira, Creado, Último acceso (esta última con `->toggleable(isToggledHiddenByDefault: true)` para no hacer la tabla demasiado ancha por default). Como la tabla clásica SÍ muestra `<thead>` con el `->label()` de cada columna, ya no hace falta el truco de meter el label dentro del texto (`formatStateUsing` con "Etiqueta: valor") ni depender de tooltips — el header de la columna ya lo deja claro sin ambigüedad.
- **Archivos**: `app/Filament/Pages/ApiTokens.php` (se sacan también los imports ya no usados: `Layout\Split`, `Layout\Stack`, `Support\Enums\TextSize`, `Support\Icons\Heroicon`).
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff). Sin runtime de PHP en este sandbox — los tests de `ApiTokensEditOriginTest`/`ApiTokensLimitTest`/`ApiTokensPlatformFieldVisibilityTest` no dependen de la estructura de columnas visual (solo de estado en BD y montaje de actions), así que no deberían verse afectados por este cambio, pero falta confirmar corriendo la suite.
- **Siguiente**: pedir al Tech Lead correr los tests de Filament una vez más y confirmar visualmente. Se cierra por ahora la exploración de layout tipo "tarjeta" para esta pantalla — si más adelante se quiere retomar, evaluar hacerlo con HTML a mano dentro de una sola columna (Blade custom) en vez de `Layout\Stack`/`Split`, ya que este sandbox no permite verificar visualmente esos componentes antes de que el Tech Lead los vea.

## 2026-09-13 — Fix: sacar el `Stack` intermedio que rompía Plataforma/Dominio/Token/Expira (10ma vuelta, addendum ADR-059)

- **Reporte del Tech Lead**: "no ha quedado asi" sobre el screenshot de la 9na vuelta — el badge "Web" quedaba a la izquierda pero "Dominio: cica360.com" aparecía flotando lejos, y "Expira" directamente no se veía en ningún lado de la tarjeta.
- **Causa probable**: la 9na vuelta envolvió los dos `Split` (platform+dominio, token+expira) dentro de un `Stack` intermedio — 2 niveles de anidado (`->columns([ Stack::make([ Split::make([...]), Split::make([...]) ]) ])`). Ese nivel extra de `Stack` alrededor de dos `Split` hermanos parece no renderizar como se espera (a diferencia de `Stack` envolviendo `TextColumn`s simples, que sí funcionó bien para las fechas). No se pudo confirmar la causa exacta a nivel de CSS/Blade sin poder renderizar la UI en este sandbox.
- **Qué se hizo**: se saca el `Stack` intermedio. `Split(platform, allowed_origin)` y `Split(last_four, expires_at)` vuelven a ser entradas de PRIMER NIVEL en `->columns([...])` (mismo nivel que el `Stack` de Nombre+fechas y que `abilities`), no anidadas dos niveles adentro. Confirmado en el CSS de Filament (`fi-ta-record-content-ctn { flex-col }`) que las entradas de primer nivel ya se apilan verticalmente solas, así que 2 `Split` de primer nivel alcanzan para las 2 líneas pedidas sin necesitar el `Stack` que se sacó.
- **Archivos**: `app/Filament/Pages/ApiTokens.php`.
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff). Sin runtime de PHP en este sandbox — y sin forma de renderizar la UI para confirmar visualmente antes de que el Tech Lead recargue.
- **Siguiente**: confirmación visual del Tech Lead. Si el problema persiste con esta estructura más simple, evaluar reemplazar `Split` por HTML manual dentro de una única columna (mayor control, más riesgo de romper con el tema claro/oscuro).

## 2026-09-13 — Fechas apiladas + Plataforma/Dominio/Token en 2 líneas, no 4 (9na vuelta, addendum ADR-059)

- **Pedido del Tech Lead** (sobre screenshot de la 8va vuelta): "en desktop no formes 4 filas, mantenla en 2: debajo del titulo dejar dos filas de Creado y ultimo acceso, luego deberia visualizarse el badge (web o api) y el dominio a lado y debajo como segunda fila el token".
- **Qué se hizo**: Creado/Último acceso dejan de ir lado a lado en un `Split` — ahora es un `Stack` (cada fecha en su propia línea, apiladas). El bloque de Plataforma/Dominio/Token/Expira pasa de un único `Split` de 4 items a un `Stack` de 2 líneas: línea 1 = `Split[platform, allowed_origin]` (badge + dominio lado a lado), línea 2 = `Split[last_four, expires_at]` (Token junto con Expira, ya que el pedido no aclaraba dónde iba Expira y ambos son datos de seguridad del token). Permisos no se tocó — el pedido no lo mencionaba, sigue en su fila propia de la 8va vuelta.
- **Archivos**: `app/Filament/Pages/ApiTokens.php`.
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff). Sin runtime de PHP en este sandbox.
- **Siguiente**: confirmación visual del Tech Lead. Si "mantenla en 2" contaba Permisos como una de las filas (en vez de las 2 filas del bloque Plataforma/Token), avisar para reacomodar.

## 2026-09-13 — Permisos a su propia fila (multi-badge) + doble fecha limpia bajo el título (8va vuelta, addendum ADR-059)

- **Pedido del Tech Lead**: "permisos que permita cambiar y uede ser varios por eso deberia mantenerse en una columna y cambiar por ultimo acceso, asi quedaran doble fecha debajo del titulo".
- **Interpretación** (el pedido no especifica layout exacto, se avisa al Tech Lead para que corrija si no es lo que quiso decir): Permisos puede tener más de una ability (array) y a futuro podría ser editable — por eso se le da su PROPIA fila en vez de compartir el `Split` con Dominio/Token/Expira. Plataforma se retira de la fila de las fechas para que Creado + Último acceso queden "doble fecha" limpia debajo del nombre, sin nada en el medio.
- **Qué se hizo**: Fila 2 ahora es `Split->from('sm')` con solo `created_at` + `last_used_at`. Fila 3 nueva: `abilities` sola, con `->badge()` — confirmado en el código fuente de `TextColumn::toEmbeddedHtml()` que un estado array con `->badge()` renderiza cada ability como su propio badge (el colapso a un string separado por comas solo pasa cuando `isBadge()` es `false`). Fila 4: `platform` (reubicado acá, sigue siendo badge de color) + `allowed_origin` + `last_four` + `expires_at`, mismo `Split->from('sm')` de antes.
- **Archivos**: `app/Filament/Pages/ApiTokens.php`.
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff). Sin runtime de PHP en este sandbox — no debería romper ningún test existente (ninguno de los tests de Filament actuales verifica el string renderizado de una celda, solo estado de BD y montaje de actions), pero falta confirmar corriendo la suite.
- **Siguiente**: el Tech Lead confirma si el layout resultante (Permisos como fila de badges independiente, Plataforma junto a Dominio/Token/Expira) es lo que pidió — la frase original era ambigua sobre dónde exactamente debía ir Plataforma.

## 2026-09-13 — Fix real de test + labels visibles en vez de tooltip-only (7ma vuelta, addendum ADR-059)

- **Reporte del Tech Lead (2 partes)**: (1) corrió los tests por primera vez y `ApiTokensEditOriginTest::test_clicking_the_allowed_origin_column_opens_the_edit_origin_action` falló; (2) sobre la tarjeta ya renderizada: "pero confunde, que es? no lo entenderan 'nunca' y arriba 2h antes" — un ícono + tooltip-al-hover no comunica qué campo es cada valor.
- **Causa raíz del test**: no era un bug de la 6ta vuelta — `triggerEditOriginAction()` monta la action con contexto `['table' => true, 'recordKey' => ...]`, pero el test llamaba `assertTableActionMounted('editOrigin')` SIN pasar el record, que internamente solo espera `['table' => true]` (ver `Filament\Tables\Testing\TestsActions::parseNestedTableActions()`). Nunca se había corrido este test hasta ahora (sin runtime de PHP en el sandbox del agente).
- **Fix del test**: `assertTableActionMounted('editOrigin', $record->getKey())` — así el contexto esperado también incluye `recordKey`, igual que lo que se monta de verdad.
- **Fix de UI**: se abandona depender de ícono+tooltip como única pista. Cada columna secundaria de la tarjeta (`created_at`, `last_used_at`, `abilities`, `allowed_origin`, `last_four`, `expires_at`) arma su texto con el label DENTRO del string vía `formatStateUsing` (ej. `"Creado: hace 3 días"`, `"Último acceso: nunca"`, `"Token: ••••6694"`, `"Dominio: — click para agregar"`, `"Expira: nunca"`), visible siempre sin necesitar hover. El ícono se mantiene como refuerzo visual, no como única fuente de significado. `expires_at` también pierde su `->placeholder('Nunca')` suelto (la fuente exacta de la confusión reportada) a favor del mismo patrón "Etiqueta: valor".
- **Archivos**: `app/Filament/Pages/ApiTokens.php`, `tests/Feature/Filament/ApiTokensEditOriginTest.php`.
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff) en ambos archivos. Sin runtime de PHP en este sandbox — el Tech Lead ya corrió los 16 tests una vez (15 passed / 1 failed, el que se corrigió acá); falta confirmar que ahora los 16 pasan.
- **Siguiente**: pedir al Tech Lead correr de nuevo `php artisan test --compact tests/Feature/Filament/ApiTokensEditOriginTest.php tests/Feature/Filament/ApiTokensLimitTest.php tests/Feature/Filament/ApiTokensPlatformFieldVisibilityTest.php` y confirmar visualmente que las tarjetas ahora se entienden sin ambigüedad.

## 2026-09-13 — Card layout con `Stack`/`Split` A PROPÓSITO — Plataforma vuelve a ser badge (6ta vuelta, addendum ADR-059)

- **Pedido del Tech Lead**: "no quiero necesariamente una table, quiero algo mas moderno y responsive, pero me presentas eso que se ve muy mal estructurado y estilado, por ejemplo en modo tabla la plataforma tiene que mantenerse en un badge colorido, pero agrega a la segunda fila la fecha de creado al inicio luego plataforma y por ultimo ultimo acceso" — a diferencia de la 4ta vuelta, acá el modo "tarjeta" (que antes era el bug) es justo lo que se pidió.
- **Qué se hizo**: `Tables\Columns\Layout\Stack` y `Tables\Columns\Layout\Split` vuelven a `->columns([...])`, esta vez deliberadamente. Estructura por tarjeta: fila 1 = `name` (negrita, `TextSize::Large`); fila 2 = `Split->from('sm')` con `created_at` → `platform` (otra vez columna real, `->badge()->color()`: web=success/app=info/null=gray) → `last_used_at`; fila 3 = otro `Split->from('sm')` con `abilities`, `allowed_origin` (sigue siendo la entrada clickeable a `editOrigin`, sin cambios), `last_four`, `expires_at` — todas con ícono, gris/chico y `->tooltip()` (el modo tarjeta no muestra `<label>` de columna). `TextColumn::description()` de la 5ta vuelta ya no se usa: Plataforma y Último uso vuelven a ser columnas de pleno derecho.
- **Archivos**: `app/Filament/Pages/ApiTokens.php`.
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff). **PHPUnit no se corrió — sin runtime de PHP en este sandbox.** Pedir al Tech Lead correr `php artisan test --compact tests/Feature/Filament/ApiTokensEditOriginTest.php tests/Feature/Filament/ApiTokensLimitTest.php tests/Feature/Filament/ApiTokensPlatformFieldVisibilityTest.php` para confirmar que nada se rompió.
- **Siguiente**: ver addendum de ADR-059 en `DECISIONS.md`. El resultado visual (tarjetas, colores del badge, orden de la segunda fila) todavía necesita confirmación directa del Tech Lead — no hay forma de renderizar/capturar la UI de Filament en este sandbox.

## 2026-09-13 — Revertido: `Stack` rompía la tabla entera, vuelta a `description()` (addendum ADR-059)

- **Reporte del Tech Lead**: "pasaste todo debajo de nombre, no pedi eso" — la vuelta anterior (usando `Tables\Columns\Layout\Stack`) hizo que TODA la tabla (Permisos, Dominio, Token, Expira, Creado, no solo Nombre/Plataforma/Último uso) se apilara verticalmente por fila, sin headers.
- **Causa raíz**: mal entendido de `Stack` — en Filament 5, con solo UN componente de layout en `->columns([...])`, la tabla ENTERA pasa a modo "lista de tarjetas" (confirmado en el blade fuente de Filament), no solo la celda donde se usó. No era el tool correcto para "agrupar 2-3 campos, dejar el resto igual".
- **Qué se hizo**: revertido el `Stack` — vuelta a columnas sueltas en modo tabla normal (headers intactos). "Plataforma" y "Último uso" ahora viven como `TextColumn::description()` de la columna `name` (funciona dentro de una celda normal sin romper el resto de la tabla), como texto plano "Web · Último uso: hace 2h" en gris automático. La columna `platform` independiente se eliminó (su info vive en la descripción de Nombre); "Dominio permitido" sigue siendo la entrada clickeable para editar.
- **Archivos**: `app/Filament/Pages/ApiTokens.php`, `tests/Feature/Filament/ApiTokensEditOriginTest.php` (ajustado: ya no verifica click en la columna `platform`, que dejó de existir).
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff). **Sin runtime de PHP en este sandbox.**
- **Siguiente**: ver addendum de ADR-059 en `DECISIONS.md`. Recargar Console. Si el Tech Lead sigue queriendo la "píldora" visual de Plataforma específicamente bajo Nombre (no solo texto plano), evaluar una vuelta aparte con HTML a mano o un componente Blade — se dejó fuera de esta vuelta por riesgo (no se puede probar visualmente en este sandbox).

## 2026-09-13 — Agrupar Plataforma + Último uso debajo de Nombre con `Stack` (addendum ADR-059)

- **Corrección del Tech Lead** sobre la entrada anterior: "No, Plataforma y ultimo uso, ambos debajo de nombre" — no era fusionar "Último uso" dentro de la columna Plataforma, era agrupar AMBAS (Plataforma y Último uso) debajo de Nombre.
- **Qué se hizo**: `Tables\Columns\Layout\Stack::make([...])` reemplaza las columnas sueltas `name`/`platform` — agrupa Nombre (negrita) + badge de Plataforma (sigue clickeable) + "Último uso" (gris, chico) en una sola celda apilada. Verificado en el código fuente de Filament que las columnas anidadas en un `Stack` se siguen registrando igual en el mapa de columnas de la tabla, así que el click para editar plataforma/dominio sigue funcionando sin cambios.
- **Archivos**: `app/Filament/Pages/ApiTokens.php`.
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff). **Sin runtime de PHP en este sandbox.**
- **Siguiente**: ver addendum de ADR-059 en `DECISIONS.md`. Recargar Console para ver el cambio.

## 2026-09-13 — Limpieza de UI en API Tokens: español, placeholder genérico, badge corto, tabla más angosta (addendum ADR-059)

- **Pedido del Tech Lead** (4 ajustes en un mismo mensaje, sobre `App\Filament\Pages\ApiTokens`): (1) placeholder de "Dominio permitido" genérico — "el place holder tiene que ser generico recuerda que será multi tenant" — `cica360.com` → `tudominio.com`. (2) columna "Abilities" → "Permisos" — "todo deberia ser en español... pero Abilities deberia ser en español" (los códigos entre paréntesis tipo `content:read` quedan en inglés a propósito). (3) badge de "Plataforma" más corto — "me parece muy extenso, deberia ser solo web o app" — el label descriptivo largo del enum queda solo para los `<select>` de los forms. (4) "Último uso" fusionado como segunda línea (tono gris tenue) debajo del badge de Plataforma, en vez de columna aparte — "pongas plataforma ultimo acceso debajo con los tonos de color adecuados de segunda linea para la facil lectura" — de paso angosta la tabla.
- **Archivos**: `app/Filament/Pages/ApiTokens.php`.
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff). **Sin runtime de PHP en este sandbox.**
- **Siguiente**: ver addendum de ADR-059 en `DECISIONS.md`. Recargar Console para ver los cambios.

## 2026-09-13 — Bug real: "Dominio permitido" nunca aparecía al elegir "Web" (addendum ADR-059)

- **Reporte del Tech Lead**: primero sobre `editOrigin` — "pero solo me pide seleccionar si es WEB o api pero si eso ya lo lleno en 'Editar plataforma/origen'" — y después, sobre `createToken` — "al crear uno nuevo solo tengo esto tambien no hay donde registrar dominios". En ambos forms, elegir "Web" nunca hacía aparecer el campo de dominio.
- **Causa raíz**: `Select::options(ApiTokenPlatformEnum::class)` registra automáticamente un `EnumStateCast` (confirmado en el código fuente de Filament) — el estado del campo pasa a ser la INSTANCIA del enum, no el string `'web'`. La comparación `$get('platform') === ApiTokenPlatformEnum::Web->value` (objeto vs. string, con `===`) daba `false` siempre. Mismo bug en 4 lugares: `visible()`/`required()` de `allowed_origin` en `createToken` Y en `editOrigin`, más el cálculo de qué guardar en el `->action()` de ambas. Esto también explica por qué los primeros tokens de prueba quedaron con `platform=Web` pero dominio vacío.
- **Qué se hizo**: comparar contra el CASE del enum (`=== ApiTokenPlatformEnum::Web`, sin `->value`) en las 4 condicionales; extraer `->value` recién al persistir. Nuevo `tests/Feature/Filament/ApiTokensPlatformFieldVisibilityTest.php` (4 tests con `assertFormFieldIsVisible()`/`assertFormFieldIsHidden()`) que reproduce el bug exacto reportado.
- **Archivos**: `app/Filament/Pages/ApiTokens.php`, `tests/Feature/Filament/ApiTokensPlatformFieldVisibilityTest.php` (nuevo).
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff). **Sin runtime de PHP en este sandbox** — ningún test se pudo ejecutar (correr `php artisan test --compact tests/Feature/Filament/ApiTokensPlatformFieldVisibilityTest.php` para confirmar).
- **Siguiente**: recargar la página en Console (los cambios de PHP no se reflejan sin recargar el componente Livewire) y reintentar: elegir "Web" ahora debería mostrar el campo "Dominio permitido" tanto al crear como al editar un token existente.

## 2026-09-13 — Columnas de Plataforma/Dominio clickeables — la acción de editar quedaba fuera de vista (addendum ADR-059)

- **Reporte del Tech Lead**, viendo la tabla real: "pero no entiendo donde se pueden adicionar los dominios o el dominio, no hay en ningun lugar en el menu". La acción `editOrigin` (entrada anterior de este mismo día) SÍ existía, como ícono de fila — pero en una tabla de 8 columnas + 3 acciones, queda fuera del viewport sin scroll horizontal. Era un problema de descubribilidad, no de funcionalidad faltante.
- **Qué se hizo**: columnas `platform`/`allowed_origin` ahora clickeables (`->action('triggerEditOriginAction')`), con placeholders que invitan a hacer click ("— click para agregar", "Sin restricción — click para configurar") y tooltip explícito. Se agregó el método puente `ApiTokens::triggerEditOriginAction()` porque `TextColumn::action(string)` llama a un método del componente Livewire, no reutiliza una action de tabla por nombre — el puente remonta la action real `editOrigin` vía `mountAction()`. También: "Expiración" y "Plataforma" ahora van en la misma fila (`Grid::make(2)`) en el form de crear token, pedido aparte sobre cómo se veía ese formulario.
- **Archivos**: `app/Filament/Pages/ApiTokens.php`, `tests/Feature/Filament/ApiTokensEditOriginTest.php` (1 test nuevo).
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff). **Sin runtime de PHP en este sandbox** — ningún test se pudo ejecutar.
- **Siguiente**: ver addendum de ADR-059 en `DECISIONS.md`. Correr `php artisan test --compact tests/Feature/Filament/ApiTokensEditOriginTest.php`. Para los 2 tokens reales de CICA360 que ya muestran `platform=Web` con `allowed_origin` vacío (screenshot compartido por el Tech Lead): hacer click directo en la celda "Dominio permitido" de cada uno y completar el dominio ahí — no hace falta revocar ni recrear nada.

## 2026-09-12 — Editar plataforma/dominio de un token existente, sin regenerarlo (addendum ADR-059)

- **Gap detectado por el Tech Lead**: "no es necesario registrar los dominios? no veo eso" — seguido de "dominios desde donde se usara el cliente o detectara como refer o algo asi". El form de `createToken` solo dejaba setear `platform`/`allowed_origin` al MOMENTO de crear el token — para un token ya existente no había ninguna forma de agregarlo o cambiarlo sin revocar y crear uno nuevo (perdiendo el secreto, con el costo de actualizar el cliente en producción).
- **Qué se hizo**: nueva acción de tabla `editOrigin` en `App\Filament\Pages\ApiTokens` — mismo form (Select `platform` + TextInput condicional `allowed_origin`) precargado con los valores actuales, pero solo actualiza esos dos campos (`forceFill()->save()`), nunca el secreto del token — sin el modal de advertencia de `regenerate`, porque no invalida nada. Mismo `abort_unless()` de ownership que `regenerate`/`revoke`. Se agregaron también dos columnas nuevas en la tabla (`platform` como badge, `allowed_origin` como texto) para que la configuración de cada token sea visible sin abrir ningún modal — antes no se podía ver desde la lista.
- **Archivos**: `app/Filament/Pages/ApiTokens.php`, `tests/Feature/Filament/ApiTokensEditOriginTest.php` (nuevo, 4 tests).
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff). **Sin runtime de PHP en este sandbox** — ningún test se pudo ejecutar.
- **Siguiente**: ver addendum de ADR-059 en `DECISIONS.md`. Correr `php artisan test --compact tests/Feature/Filament/ApiTokensEditOriginTest.php`. Con esto, para activar la protección en el token real de CICA360 ya no hace falta revocar/recrear: alcanza con usar "Editar plataforma/origen" desde la tabla de tokens en Console.

## 2026-09-12 — Límite de API Tokens activos por plan + confirmación de aislamiento cross-tenant (ADR-060)

- **Pedido del Tech Lead**: "pero tambien deberia validarse por tenant, si no imaginate que otro se conecte a tenant diferente, ademas me falto ver para plan free / auspiciador deberia permitir un limite de tokens, para free 5 y para asupicio 10" — dos pedidos en un mismo mensaje.
- **Parte 1 (aislamiento por tenant)**: ya existía, sin cambios de código necesarios. `App\Http\Concerns\ResolvesTenant::resolveTenant()` (usado por los 8 controllers de `Api\V1`) rechaza con 403 cualquier token cuyo tenant no coincida con el `{tenant_slug}` de la URL — corre DESPUÉS de `ValidateTokenOrigin` en la cadena, así que pasar la validación de origen nunca alcanza para saltarse el chequeo de tenant. Ya cubierto por `ApiAuthTest::test_token_from_a_different_tenant_is_forbidden` y `ApiTokensRegenerateTest::test_a_user_cannot_regenerate_a_token_from_a_different_tenant`; se agregó un test adicional combinando ambos mecanismos (`ApiAuthTest::test_a_web_token_with_a_matching_origin_is_still_forbidden_for_a_different_tenant`) y una nota explícita en el docblock de `ValidateTokenOrigin`.
- **Parte 2 (límite de tokens)**: nuevo `Tenant::maxApiTokens()` (mismo patrón que `maxPosts()`/`maxServices()`/etc.: free/freemium = 5, sponsorship = 10, otros planes = sin límite). `App\Filament\Pages\ApiTokens`: `isTokenLimitReached()`/`tokenLimitMessage()`/`activeTokensCount()` nuevos, aplicados a la acción `createToken` con `->disabled()`/`->tooltip()`/`->before(...halt())` (mismo trío que `PostResource`, etc.). El conteo es por TENANT (todos sus users), solo tokens activos (`expires_at IS NULL OR expires_at > now()` — uno ya vencido no ocupa cupo).
- **Archivos**: `app/Models/Tenant.php`, `app/Filament/Pages/ApiTokens.php`, `app/Http/Middleware/ValidateTokenOrigin.php` (docblock), `tests/Feature/Api/V1/ApiAuthTest.php` (1 test nuevo), `tests/Feature/Filament/ApiTokensLimitTest.php` (nuevo, 7 tests).
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff) en todos los archivos PHP tocados/nuevos. **Sin runtime de PHP en este sandbox** — ningún test se pudo ejecutar.
- **Siguiente**: ver ADR-060 en `DECISIONS.md`. Correr `php artisan test --compact --filter=ApiTokensLimitTest` y `--filter=ApiAuthTest`.

## 2026-09-12 — Rediseño: protección de origen POR TOKEN (platform web/app), no por tenant (ADR-059, supersede ADR-058)

- **Corrección del Tech Lead** sobre la entrada anterior (ADR-058, más abajo): "me refiero a stamless, la proteccion es para stamless, no para el sitio web, porque lo pueden usar solo con node o react o app y es importante a nivel de refer se pueda validar internamente como api, por eso tambien un select si es una web o es app desde donde se usará el api, si es app mobile ya no se valida porque es diferente la comunicacion, pero desde desktop via web creo que si por que el cliente estara alojado en un server identificado por un dominio, de que forma se puede solucionar entonces?".
- **Por qué el diseño anterior estaba mal**: `Tenant::domains()` asume un único "dominio del sitio" por tenant — pero un mismo tenant puede tener tokens consumidos por clientes completamente distintos (sitio web con dominio propio, backend Node/React sin dominio público, app mobile sin `Origin` significativo). La protección tiene que vivir en el TOKEN, no en el tenant.
- **Qué se hizo**: dos columnas nuevas en `personal_access_tokens` (`platform`: `web`/`app`, `allowed_origin`: host) vía nueva migración; `App\Enums\ApiTokenPlatformEnum`; nuevo `App\Http\Middleware\ValidateTokenOrigin` (alias `validate-origin`, registrado en `bootstrap/app.php`, aplicado al grupo padre `v1/{tenant_slug}` en `routes/api.php` — cubre `content:read` Y `forms:submit` por igual). Sin `platform` seteado (default) = sin restricción; `platform=app` = nunca se valida; `platform=web` con `allowed_origin` = exige que `Origin`/`Referer`/`X-Forwarded-Host` matcheen, con el mismo bypass de desarrollo (`stamless.security.strict_origin_check`) ya existente. `App\Filament\Pages\ApiTokens`: nuevos campos `platform`/`allowed_origin` en el form de creación (visibles/requeridos condicionalmente vía `Get $get`), persistidos con `forceFill()` (mismo patrón que `last_four`); `regenerate` los preserva del token anterior.
- **Revertido**: `FormSubmissionController::assertOriginIsAllowed()`/`resolveClaimedOriginHost()` (eliminados), `Cliente0Seeder::upsertDomain()` (vuelto a su forma original, sin `PUBLIC_DOMAIN`).
- **Archivos**: `database/migrations/2026_09_12_150000_add_platform_fields_to_personal_access_tokens_table.php`, `app/Enums/ApiTokenPlatformEnum.php`, `app/Http/Middleware/ValidateTokenOrigin.php`, `bootstrap/app.php`, `routes/api.php`, `app/Filament/Pages/ApiTokens.php`, `config/stamless.php` (docblock actualizado), `app/Http/Controllers/Api/V1/FormSubmissionController.php` (revertido), `database/seeders/Cliente0Seeder.php` (revertido), `tests/Feature/Api/V1/FormSubmissionApiTest.php` (8 tests de ADR-058 reemplazados por 8 equivalentes con tokens reales), `tests/Feature/Api/V1/ApiAuthTest.php` (1 test nuevo confirmando cobertura de `content:read`).
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff) en todos los archivos PHP tocados/nuevos. **Sin runtime de PHP en este sandbox** — ningún test se pudo ejecutar.
- **Siguiente**: ver ADR-059 en `DECISIONS.md` (ADR-058 marcado `Superseded by ADR-059`). Correr `php artisan migrate` + `php artisan test --compact --filter=FormSubmissionApiTest` y `--filter=ApiAuthTest`. Pendiente del Tech Lead: decidir si activar `platform=web` + `allowed_origin=cica360.com` en el token real de CICA360 desde Filament (opt-in, no automático). Actualizar también `cica360/docs/context/DECISIONS.md` (addendum de ADR-004) y `cica360/docs/context/PROGRESS.md` para que dejen de referenciar el diseño por `Tenant::domains()`.

## 2026-09-12 — Validación de dominio de origen en `POST forms/{slug}/submit` (ADR-058)

- **Pedido del Tech Lead**: "también validar que el formulario solo reciba de un dominio de la app (website cliente) que fue configurada al crear un token, por seguridad, creo que amerita que opinas?".
- **Opinión dada antes de implementar**: vale la pena como defensa en profundidad, pero no es una barrera dura — el submit real es server-to-server (proxy PHP de cica360), y `Origin`/`Referer`/`X-Forwarded-Host` son headers que arma el propio llamador, no una garantía criptográfica; alguien con el token robado y un cliente HTTP directo puede escribirlos con cualquier valor. Donde SÍ es una barrera real es en el fallback de token expuesto en el navegador (ADR-002 de cica360): ahí un navegador real no deja que un script de otro dominio falsee `Origin`. El valor principal acá es protección contra error de configuración (token de un tenant usado, por accidente, desde el proxy de otro).
- **Qué se hizo**: nuevo `FormSubmissionController::assertOriginIsAllowed()` — reutiliza `Tenant::domains()` (tabla ya existente, antes solo usada para links "ver en vivo" en Filament). Si el tenant no tiene ningún `Domain` registrado, no se aplica nada (opcional, "sera opcion de cada cliente si desea usar"). Si tiene dominios registrados, exige que `Origin`/`Referer`/`X-Forwarded-Host` matcheen alguno — salvo que `config('stamless.security.strict_origin_check')` sea `false` (default fuera de producción), en cuyo caso `localhost`/`127.0.0.1`/sin header quedan permitidos sin registrarlos, para no frenar el desarrollo local (pedido explícito).
- **Archivos**: `app/Http/Controllers/Api/V1/FormSubmissionController.php`, `config/stamless.php`, `database/seeders/Cliente0Seeder.php` (nueva constante `PUBLIC_DOMAIN`, se siembra `cica360.com` como `Domain` adicional del tenant), `tests/Feature/Api/V1/FormSubmissionApiTest.php` (8 tests nuevos).
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff) en los 4 archivos PHP. **Sin runtime de PHP en este sandbox** — los tests nuevos tampoco se pudieron ejecutar.
- **Siguiente**: ver ADR-058 en `DECISIONS.md`. Correr `php artisan test --compact --filter=FormSubmissionApiTest` (18 tests en total ahora) y re-seedear (`Cliente0Seeder`) para que `cica360.com` quede registrado como `Domain`. Ver también `cica360/docs/context/DECISIONS.md`, addendum de ADR-004, mismo día (header `X-Forwarded-Host` en `contacto.php`).

## 2026-09-12 — `geo_country_code` (por IP) llega siempre al submit, independiente del `<select>` de País (addendum ADR-057)

- **Pedido del Tech Lead**: "cabe la posibilidad abierta de que el cliente cambie de pais en el formulario pero siempre el api debe recibir el IP y country_code de origen, por favor asegurarse eso" — aclarando que no debía ser obligatorio: "no requeridos de lado de stamless, sera opcion de cada cliente si desea usar".
- **Qué se hizo**: nuevo `FormSubmissionController::resolveOriginCountry()` — lee el header `X-Origin-Country` (2 letras, validado), que CICA360 ahora resuelve DE NUEVO en cada submit desde la IP real (no del lookup de UX al cargar la página, que puede no haber corrido o estar desactualizado — ver `cica360/docs/context/PROGRESS.md`, mismo día). `ContactSubmissionService::submit()` lo guarda en `Contact::data['geo_country_code']` SOLO si viene — no es una columna dedicada, no participa de ninguna validación de `FormField`, y es independiente de un eventual campo `country` de negocio que el visitante elija a mano (puede cambiarlo libremente sin afectar esto). Opcional para todo tenant: si su proxy no manda el header, simplemente no aparece la clave. Nuevos tests `test_geo_country_code_from_header_is_stored_independently_of_the_declared_country_field` y `test_submission_succeeds_without_x_origin_country_header`.
- **Archivos**: `app/Http/Controllers/Api/V1/FormSubmissionController.php`, `app/Services/ContactSubmissionService.php`, `tests/Feature/Api/V1/FormSubmissionApiTest.php`.
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff). **Sin runtime de PHP en este sandbox** — los tests nuevos tampoco se pudieron ejecutar.
- **Siguiente**: ver addendum de ADR-057 en `DECISIONS.md`. Correr `php artisan test --compact --filter=FormSubmissionApiTest` (11 tests en total ahora).

## 2026-09-12 — `FormSubmissionController` prioriza `X-Forwarded-For` para `Contact::ip_address` (ADR-057)

- **Pedido del Tech Lead**: junto con la preselección de país por IP del lado cica360 (ver `cica360/docs/context/PROGRESS.md`, mismo día), pidió "de paso enviamos al api el IP para seguimiento tambien" — pero CICA360 pega contra este endpoint vía un proxy PHP server-to-server (`contacto.php`), así que `$request->ip()` sin más veía SIEMPRE la IP saliente del hosting del proxy, nunca la del visitante real.
- **Qué se hizo**: nuevo `FormSubmissionController::resolveClientIp()` — prioriza el header `X-Forwarded-For` (primer valor, validado como IP) sobre `$request->ip()`. Es informativo únicamente (`Contact::ip_address` no participa en rate-limiting ni en ninguna decisión de seguridad), así que confiar en un header en teoría spoofeable no abre ninguna puerta nueva. `contacto.php` (cica360) se actualiza en paralelo para reenviar la IP real en ese header — sin ese cambio del otro lado, este no tiene efecto. Nuevo test `test_ip_address_is_taken_from_x_forwarded_for_when_present`.
- **Archivos**: `app/Http/Controllers/Api/V1/FormSubmissionController.php`, `tests/Feature/Api/V1/FormSubmissionApiTest.php`.
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff). **Sin runtime de PHP en este sandbox** — el test nuevo tampoco se pudo ejecutar.
- **Siguiente**: ver ADR-057 en `DECISIONS.md`. Correr `php artisan test --compact --filter=FormSubmissionApiTest` (8 tests en total ahora).

## 2026-09-12 — Validación de "Consulta" a texto plano + puntuación (sin HTML/código)

- **Pedido del Tech Lead**: "la valicacion en campo de consulta, ese textarea debe tener una validacion coherente al tipo de info que recibirá, nada de html, solo texto, signos de puntuacion o cualquier otro pero solo texto plano".
- **Qué se hizo**: `NoHtmlTags` (ya aplicada a todo `Textarea` desde la 1ra vuelta de ADR-056) solo bloquea markup bien formado, no caracteres sueltos de código (`{ } \` ~ ^ |`). Se siembra un `validation_rules` propio para el campo `message` en `Cliente0ContentSeeder::upsertContactForm()`: allow-list `/^[\p{L}\p{N}\s.,;:!?\'"()\-_¿¡%\/@#&*+=$°]*$/u` (letras Unicode, dígitos, espacios/saltos de línea, puntuación común en español), deliberadamente sin `< > { } [ ] \ \` ~ ^ |`. Nuevo test `test_a_message_field_with_its_own_plain_text_validation_rules_rejects_code_like_characters`. Espejado en cica360 (ver PROGRESS.md de ese repo, mismo día).
- **Archivos**: `database/seeders/Cliente0ContentSeeder.php`, `tests/Feature/Api/V1/FormSubmissionApiTest.php`.
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python (sin diff). **Sin runtime de PHP en este sandbox** — el test nuevo tampoco se pudo ejecutar, sigue pendiente junto con los anteriores de esta serie.
- **Siguiente**: ver addendum "Actualización 2026-09-12 (3ra vuelta)" en ADR-056. Correr `php artisan test --compact --filter=FormSubmissionApiTest` (7 tests en total) y re-seedear (`Cliente0ContentSeeder`) para que el `validation_rules` de `message` tome efecto en la DB.

## 2026-09-12 — WhatsApp con código de país automático (frontend) + regex de teléfono endurecido en la API

- **Pedido del Tech Lead**: al elegir el país, armar el número de WhatsApp con el código de llamada correspondiente (bandera + "+51"/"+54"/etc.), autoformato en grupos de 3 dígitos ("espacios solo 2"), input que solo permite dígitos, y "al final se envia concatenado el codigo pais y el numero" — con el pedido explícito de que la API "tiene que soportar el unico simbolo '+' de forma opcional + el numero (sólo y unicamente digitos)".
- **Fix (API)**: `ContactSubmissionService::rulesForField()` — la regla BASE para `FormFieldTypeEnum::Tel` pasa de `/^[0-9+\-\s()]{6,20}$/` (toleraba espacios/guiones/paréntesis, porque antes el visitante los tipeaba directo) a `/^\+?[0-9]{6,20}$/` ("+" opcional, solo dígitos) — calza con el nuevo formato que arma el frontend. Se retira el `validation_rules` de `phone` sembrado minutos antes en `Cliente0ContentSeeder` (quedaba idéntico a la regla base nueva, redundante). Nuevo test `test_a_phone_value_must_be_an_optional_plus_followed_only_by_digits`.
- **Archivos/áreas**: `app/Services/ContactSubmissionService.php`, `database/seeders/Cliente0ContentSeeder.php`, `tests/Feature/Api/V1/FormSubmissionApiTest.php`.
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python. **Sin runtime de PHP en este sandbox** — el test nuevo tampoco se pudo ejecutar, sigue pendiente junto con los 4 de la entrada anterior.
- **Siguiente**: ver addendum "Actualización 2026-09-12 (2da vuelta)" en ADR-056. Ver también `cica360/docs/context/PROGRESS.md` (mismo día) para el detalle completo del lado frontend (bandera/código de país/autoformato).

## 2026-09-12 — Validación real de FORMATO en `POST forms/{slug}/submit` (antes solo presencia) + seguridad anti-XSS/injection/uploads

- **Pedido del Tech Lead**: "añadimos validaciones y seguridad en el post del api para el submit del formulario y seguridad y validacion en el formulario de la pagina, quiero evitar XSS, injection, uploads y cualquier forma de acceder al stamless" — con el detalle exacto de nombre (solo letras, un espacio entre palabras, 3–40 caracteres), correo (charset estándar + TLD válido) y ciudad (mismo criterio que nombre), más un pedido de reorden de campos: "nombre, correo, pais, whatsapp, cuidad, area interes, caja de consulta".
- **Investigación previa** (solo lectura) confirmó: `ContactSubmissionService` solo validaba PRESENCIA (`filled()`), nunca formato; `FormField.validation_rules`/`FormFieldDefinition.validation_rules` existían en el esquema desde el inicio del proyecto pero jamás se leían (columna muerta); el tipo `File` del enum no tenía ninguna implementación real de subida (sin riesgo práctico hoy porque el endpoint solo acepta JSON, pero tampoco estaba explícitamente bloqueado); sin sanitización de contenido (XSS) en ningún punto del pipeline; rate limiting (10/min/IP) y auth (`Bearer` + ability `forms:submit`) ya estaban bien — sin cambios ahí.
- **Fix**: nuevo `ContactSubmissionService::assertFieldsAreValid()` (corre después de `assertRequiredFieldsPresent()`) — arma un `Validator` dinámico por `Form`/tenant: reglas base por `FormFieldTypeEnum` (email real, teléfono con charset esperado, `select`/`radio` limitados a su propio catálogo de `options`, tipo Archivo → `prohibited` explícito) + reglas ADICIONALES desde `FormField::validation_rules` (recién puesto a funcionar — antes muerto) + `App\Rules\NoHtmlTags` nuevo en todo campo de texto (rechaza cualquier valor con markup, defensa contra XSS almacenado). Nueva excepción `InvalidFieldFormatException` (mismo shape `{campo:[mensajes]}` que la de campos faltantes), capturada junto a ella en `FormSubmissionController`.
- **CICA360 (`Cliente0ContentSeeder::upsertContactForm()`)**: reglas de nombre/ciudad (letras Unicode + un espacio, 3-40 caracteres) y correo (regex con TLD) sembradas como `validation_rules` de esos `FormField` — NO hardcodeadas por nombre de campo en el servicio genérico, para no romper el diseño 100% dinámico/multi-tenant de formularios. Reorden de `$fieldsConfig` según el pedido (nombre, correo, país, whatsapp, ciudad, área de interés, consulta).
- **Espejo en cica360** (`ContactForm.tsx`): mismo reorden de campos + sanitización en vivo al tipear (impide directamente escribir caracteres fuera de rango) + validación de formato en `onBlur`/antes de enviar, con los MISMOS patterns que el backend — capa de UX únicamente, la autoridad real sigue siendo 100% del servidor. Ver entrada en `cica360/docs/context/PROGRESS.md`.
- **reCAPTCHA/Turnstile**: NO implementado esta vuelta — requiere credenciales de un servicio externo que el Tech Lead todavía no proveyó. Recomendación dada en el chat: Cloudflare Turnstile por sobre Google reCAPTCHA v3 (mejor privacidad, sin depender de Google) — `Form::enable_recaptcha` ya existe en el esquema (inerte), quedaría como el flag a activar el día que haya site key/secret reales.
- **Archivos/áreas**: `app/Rules/NoHtmlTags.php` (nuevo), `app/Exceptions/Api/InvalidFieldFormatException.php` (nuevo), `app/Services/ContactSubmissionService.php`, `app/Http/Controllers/Api/V1/FormSubmissionController.php`, `database/seeders/Cliente0ContentSeeder.php`, `tests/Feature/Api/V1/FormSubmissionApiTest.php` (4 tests nuevos).
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python en los 5 archivos PHP tocados/nuevos. **Sin runtime de PHP en este sandbox** — los tests nuevos NO se pudieron ejecutar; pendiente que el Tech Lead corra `php artisan test --compact --filter=FormSubmissionApiTest` antes de dar esto por cerrado. Lado cica360 verificado con `tsc --noEmit` (0 errores) y `astro-check` (0 errores, mismos 2 warnings preexistentes no relacionados).
- **Siguiente**: ver ADR-056 (decisión completa, alternativas descartadas). Correr los tests nuevos del lado genesis; re-seedear CICA360 para que `validation_rules`/el nuevo orden de campos tomen efecto; decidir si se avanza con reCAPTCHA/Turnstile (necesita credenciales del Tech Lead).

## 2026-09-11 — Límite por plan extendido a Posts/Services/Testimonials/Sliders/Media/Items de menú

- **Pedido del Tech Lead**, inmediatamente después de confirmar el límite de contenidos (ADR-054): "para el free 10 publicaciones activas y para auspicio 20 publicaciones / para free con 10 servicios y auspicios con 20 servicios / para free con 6 testimonios o casos de exito y para auspicio con 20 testimonios / para free con 7 items de menu y para auspicio con 12 items / para free con 2 sliders y para auspicio con 5 sliders / pra free con 40 multimedia y para asupicio 60 multimedia".
- **Fix (5 recursos simples — Posts/Services/Testimonials/Sliders/Media)**: mismo patrón que `PageResource`. `Tenant`: 5 métodos nuevos (`maxPosts()`, `maxServices()`, `maxTestimonials()`, `maxSliders()`, `maxMedia()`), cada uno `match($this->plan)` con su propio par Free/Sponsorship. Cada resource (`PostResource`, `ServiceResource`, `TestimonialResource`, `SliderResource`, `MediaResource`) suma `isXLimitReached()`/`xLimitMessage()` públicos; se aplica `->disabled()`/`->tooltip()`/`->before()` tanto al `CreateAction` del header (`ManageX::getHeaderActions()`) como al de `emptyStateActions()` del propio `XResource::table()` — los 2 puntos de creación de cada recurso, confirmados leyendo cada archivo antes de tocarlo. Ninguno de estos 5 modelos usa `SoftDeletes`, así que "activo" es simplemente `count()` de filas del tenant.
- **Fix (items de menú — caso distinto)**: `MenuResource` no tiene un botón "Crear item" individual — el árbol completo (`itemsTree`) se edita client-side en `MenuTreeBuilder` y se sincroniza TODO junto recién al guardar el `Menu` (`syncMenuTree()`), así que no hay botón que deshabilitar por adelantado. Se agregó `Tenant::maxMenuItems()` + `MenuResource::exceedsMenuItemLimit(array $itemsTree)`/`menuItemLimitMessage()` (privados), aplicados con `->before()` en `createAction()` (compartido entre el header de `ManageMenus` y el estado vacío de la tabla) y en el `EditAction` de `table()` — cuenta el array `itemsTree` completo (todos los niveles de profundidad) que llega en `$data` contra el límite.
- **Archivos/áreas**: `app/Models/Tenant.php`; `app/Filament/Resources/PostResource.php` + `PostResource/Pages/ManagePosts.php`; `ServiceResource.php` + `ServiceResource/Pages/ManageServices.php`; `TestimonialResource.php` + `TestimonialResource/Pages/ManageTestimonials.php`; `SliderResource.php` + `SliderResource/Pages/ManageSliders.php`; `MediaResource.php` + `MediaResource/Pages/ManageMedia.php`; `MenuResource.php`.
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python en los 11 archivos tocados. `ServiceResource.php` y `TestimonialResource.php` muestran un delta preexistente de +1 paréntesis (texto de comentarios en prosa, no código real) — mismo patrón ya documentado para `PageResource.php` en ADR-054, no originado por esta edición (confirmado aislando cada bloque agregado: todos cierran en 0). Sin runtime PHP disponible en este sandbox para un `php -l` real.
- **Siguiente**: ver ADR-055 (decisión completa, alternativas descartadas). Confirmar visualmente en Studio los 6 topes nuevos, en particular el caso de items de menú (guardar un árbol que supere el límite debe rechazar el guardado con notificación, no solo dejar de mostrar un botón).

## 2026-09-11 — Límite real de contenidos por tipo/plan: 5 (Free/Freemium) o 7 (Sponsorship) por cada tipo de `PageTypeEnum`

- **Pedido del Tech Lead**: "dentro del plan free y aspicio hay limites de cantidades de paginas o podrian tenerlo? asi ya no se permite si limitamos hasta 10 contenidos activos (sin contar los eliminados softdelete)" — precisado luego vía preguntas: 5 por cada tipo de contenido (Página/Landing/Legal/Footer) para Free, 7 por tipo para Sponsorship.
- **Hallazgo previo**: `plans.max_pages` ya existía (sembrado en 20 para Free) pero NUNCA se aplicaba en ningún lado del código — límite fantasma, mismo patrón de "declarado pero no enforced" ya visto varias veces esta sesión.
- **Fix**: `Tenant::maxContentsPerType()` nuevo (`free`/`freemium` → 5, `sponsorship` → 7, cualquier otro plan → sin límite). `PageResource::isContentLimitReached()`/`contentLimitMessage()` nuevos, consultados desde los 6 puntos de creación de contenido (3 botones de `ManagePages::getHeaderActions()` + 3 acciones de estado vacío en `PageResource::table()`): cada botón se deshabilita con tooltip explicativo al llegar al tope de SU tipo, más un `->before()` server-side como red de seguridad.
- **Archivos/áreas**: `app/Models/Tenant.php`, `app/Filament/Resources/PageResource.php`, `app/Filament/Resources/PageResource/Pages/ManagePages.php`.
- **Verificación**: balance de llaves/paréntesis/corchetes en los 3 archivos, sin cambios de delta respecto al baseline.
- **Siguiente**: ver ADR-054 (decisión completa, alternativas descartadas). Confirmar visualmente que el botón se deshabilita al llegar al tope de cada tipo, para el tenant CICA360 (plan `sponsorship`, tope 7).

## 2026-09-11 — Fix real: 500 al escribir una respuesta de FAQ (`Livewire\Exceptions\MaxNestingDepthExceededException`)

- **Reportado por el Tech Lead**, con captura del error real en Studio al editar el campo "Respuesta" de un item de FAQ: `Property path [mountedActions.0.data.blocks.{uuid}.data.content.items.{uuid}.answer.content.0] exceeds the maximum nesting depth of 10 levels`.
- **Causa**: el campo `answer` de cada item del Repeater de FAQ usaba `Forms\Components\RichEditor::make('answer')`. El documento JSON en vivo que arma ese campo (Tiptap, `{content: [...]}`) sumado a la profundidad YA alta de Builder→Block→Repeater→item→campo supera el límite de anidamiento de Livewire (10 niveles) con solo escribir un párrafo simple — ni hace falta guardar, el 500 salta apenas Livewire sincroniza el estado del formulario. Además, aunque no hubiera crasheado: `Faq.astro` (cica360) renderiza esta respuesta como texto plano (`{item.answer}`, sin `set:html`) — el HTML que produce un rich editor nunca se hubiera visto formateado en el sitio, era el campo equivocado para lo que el frontend realmente consume.
- **Fix**: `RichEditor` reemplazado por `Textarea` (3 filas) — mismo tipo de dato (texto plano) que ya espera `Faq.astro`, sin el árbol JSON profundo que rompía a Livewire.
- **Archivos/áreas**: `app/Filament/Resources/PageResource.php` (bloque `faq`, campo `answer`).
- **Verificación**: balance de llaves/paréntesis/corchetes sin cambios de delta respecto al baseline. Se revisaron los otros 3 usos de `RichEditor` en el archivo (`rich_text`, `split` ×2) — todos son campos de bloque de primer nivel (`content.body`), sin el nivel extra de Repeater que causó este crash específico; se dejan sin tocar por no tener evidencia de problema.
- **Siguiente**: confirmar visualmente que ahora se puede escribir y guardar una respuesta de FAQ sin error 500.

## 2026-09-11 — Bloque `image` (Filament): quitado el heading fantasma, agregado copy/agrupamiento, corregido el degradado fantasma

- **Pedido del Tech Lead**: "el bloque imagen unica falta alinear UX y con buenos copys y no deberia tener heading este bloque".
- **2 problemas reales encontrados** (mismo patrón que el rediseño de `contact_form` esta sesión): (1) traía `HeadingFieldset::make()` pero el frontend (`ImageBlock.astro`) nunca lo usaba como encabezado — en cambio, usaba `block.title` como un caption improvisado, aunque el bloque YA tiene su propio campo `content.caption` sin usar. (2) todos los campos estaban sueltos, sin agrupar ni copy que explique su efecto. (3), encontrado de paso: el Select de "Tipo de fondo" ya ofrecía "Degradado" sin los campos `background_color_secondary`/`gradient_direction` que lo hacen funcionar — mismo "degradado fantasma" ya corregido en otros 6 bloques (ver ADR-052).
- **Fix (Filament)**: se quita `HeadingFieldset::make()`. Los campos pasan a `Section::make('Imagen')` (archivo + caption + aspecto/alineación en grid de 2, con `helperText`) y `Section::make('Personalización de estilos')` (colapsada, con los 2 campos de degradado sumados).
- **Fix (cica360, `ImageBlock.astro`)**: reescrito para consumir de verdad `content.caption` (ya no `block.title`), `content.aspect` (4 variantes de `aspect-ratio`), `content.align` (posición del `<figure>` dentro de la sección) y fondo sólido/degradado vía `resolveBackgroundStyle()` + `padding_y` en 4 pasos. `properties.animation` queda sin consumir a propósito (dead en TODO el proyecto, no específico de este bloque).
- **Archivos/áreas**: `app/Filament/Resources/PageResource.php` (bloque `image`), `cica360/src/components/blocks/ImageBlock.astro`.
- **Verificación**: balance de llaves/paréntesis/corchetes en el lado PHP (sin cambios de delta); `astro-check` en cica360 — 0 errores.
- **Siguiente**: confirmar visualmente en Studio que ya no aparece el fieldset de encabezado en este bloque, y que el caption/aspecto/alineación/fondo se ven reflejados en el sitio.

## 2026-09-11 — Selector de bloques de `Legal`: se saca `rich_text` (redundante con `legal_notice`) y se reordena `legal_notice` justo debajo de `heading`

- **Pedido del Tech Lead**, con captura del selector ya filtrado a 7 bloques para `Legal`: "quita texto enriquecido" + "mueve aviso legal en segundo orden, debajo de heading".
- **Fix**: `rich_text` se suma a `$legalExcludedBlocks` (mismo motivo que el resto de exclusiones de esa lista — redundante, `legal_notice` ya es el bloque de texto largo para este tipo). El orden del selector seguía el orden de declaración en `$allBlocks` (heading, rich_text, cta, ...), dejando `legal_notice` lejos del tope — se agrega un `usort` (estable desde PHP 8.0) con un ranking explícito solo para `heading` (0) y `legal_notice` (1), todo lo demás en rango 2 conserva su orden relativo original.
- **Archivos/áreas**: `app/Filament/Resources/PageResource.php` (`Builder::make('blocks')->blocks(...)`, rama `Legal`).
- **Verificación**: balance de llaves/paréntesis/corchetes sin cambios de delta respecto al baseline.
- **Siguiente**: confirmar visualmente que el selector para `Legal` queda: Heading, Aviso Legal, Llamado a la Acción, Formulario de Contacto, Logos/Socios, Footer.

## 2026-09-11 — Selector de bloques de un contenido tipo `Legal`: se excluyen 8 bloques de landing/marketing

- **Pedido del Tech Lead**, con captura del selector "Añadir bloque": "cuando sea tipo de contenido legales quitar de las opciones de bloques a: hero, imagen unica, caracteristicas/grid, preguntas frecuentes, split imagen y texto, testimonios, grid servicios, grid casos de uso — pero dejar el bloque de legales".
- **Fix**: nueva rama dedicada `if ($typeVal === PageTypeEnum::Legal->value)` en el `->blocks(function (Get $get) ...)` del Builder de `blocks` — excluye `hero`, `image`, `features`, `faq`, `split`, `testimonials`, `services_grid`, `testimonials_grid` (más `colophon`/`footer_bottom`, ya excluidos para todo lo que no sea `Footer`). Quedan disponibles para `Legal`: `heading`, `rich_text`, `cta`, `contact_form`, `legal_notice`, `logos` — un Aviso Legal simple puede necesitar un banner, texto, un CTA o un formulario de contacto al pie, sin necesitar grids de marketing.
- **Archivos/áreas**: `app/Filament/Resources/PageResource.php` (`Builder::make('blocks')->blocks(...)`).
- **Verificación**: balance de llaves/paréntesis/corchetes sin cambios de delta respecto al baseline.
- **Aclarado**: el comentario "[el bloque de legales] no está mostrándose" fue una confusión del Tech Lead mirando el selector de un contenido tipo `Página` (no `Legal`) — `legal_notice` nunca debió aparecer ahí (ver entrada de ADR-053/exclusión anterior en este mismo documento). Sin acción adicional.

## 2026-09-11 — `Cliente0HomeSlidesSeeder`: slide duplicada ("El socio que necesitas" ×2) — faltaba podar filas sobrantes por `sort_order`

- **Pedido del Tech Lead**, con captura del editor de Slider mostrando 4 slides en vez de 3 (la última repetida): "en slider estas generando contenido inicial duplicado".
- **Causa**: `run()` solo hace `updateOrCreate(['tenant_id', 'sort_order' => 0|1|2], ...)` para las 3 slides definidas en `SLIDES` — nunca borra una fila que haya quedado de una corrida anterior con más slides o de antes de que existiera este `updateOrCreate` por `sort_order` (a diferencia de `Cliente0ContentSeeder`, que desde su creación sí "poda filas sobrantes por sort_order" para los bloques). Cada `db:seed` sucesivo puede ir dejando huérfanos en vez de converger siempre a las 3 slides reales.
- **Fix**: al final de `run()`, se borran las slides del slider `home` con `sort_order >= count(SLIDES)` — mismo criterio de convergencia ya usado en otros seeders del proyecto.
- **Archivos/áreas**: `database/seeders/Cliente0HomeSlidesSeeder.php`.
- **Siguiente**: correr `php artisan db:seed` y confirmar que "Editar Slider" muestra exactamente 3 slides, sin la duplicada.

## 2026-09-11 — Bloque `legal_notice` ya no aparece en el selector de bloques de una Página normal — exclusivo de contenidos tipo `Legal`

- **Pedido del Tech Lead**, con captura del selector "Añadir bloque" mostrando "Aviso Legal / Contenido..." disponible en una Página común: "cuando sea tipo de contenido: Pagina, no mostrar bloque Aviso legal, que solo lo tenga el tipo de pagina: Legales".
- **Fix**: en el `->blocks(function (Get $get) ...)` del Builder de `blocks`, se agrega `$legalOnlyBlocks = ['legal_notice']` y se excluye de la lista salvo cuando `type === PageTypeEnum::Legal` — mismo mecanismo ya usado para `colophon`/`footer_bottom` (exclusivos de `Footer`), pero al revés (exclusivo de `Legal`, oculto en el resto). No afecta la rama `Footer` (que ya usa una whitelist propia sin `legal_notice`).
- **Archivos/áreas**: `app/Filament/Resources/PageResource.php` (`Builder::make('blocks')->blocks(...)`).
- **Verificación**: balance de llaves/paréntesis/corchetes sin cambios de delta respecto al baseline.
- **Siguiente**: confirmar visualmente que "Aviso Legal / Contenido..." desaparece del selector al editar una Página/Landing/Footer, y que sigue disponible al editar un contenido tipo Legal.

## 2026-09-11 — Se descarta el tipo de contenido `header` (Cabecera): eliminado de `PageTypeEnum` y de todo el código que lo trataba igual que `footer`

- **Pedido del Tech Lead**, con captura del menú "+ Crear Contenido" (4 opciones: Página/Cabecera/Pie de página/Aviso Legal): "creo que descartaremos en stamless el tipo de contenido (pages): Crear Cabecera (header)".
- **Verificación antes de tocar código**: a diferencia de `Footer` (consumido de verdad por el bloque `footer` vía `content.footer_page_id`, resuelto en `ResolvesPublicLinks`), `Header` no tenía NINGÚN mecanismo de consumo — ni bloque, ni setting, ni endpoint lo referenciaba. El navbar real de CICA360 se arma con Menús + Settings, no con una Página tipo Header. Cero páginas `Header` sembradas en ningún seeder — nada que migrar.
- **Fix**: `case Header` eliminado de `PageTypeEnum`. Se quita el botón "Crear Cabecera (Header)" (`ManagePages::getHeaderActions()`); la tab "Secciones" (antes Header+Footer) filtra ahora solo `Footer`, y su acción de estado vacío crea un Footer en vez de un Header. Se simplifican 5 chequeos en `HeadingFieldset.php` y 3 en `PageResource.php` que trataban a `Header` igual que `Footer` (prefijo de slug, visibilidad de tabs/campos, color de badge, descripción de fila) — quedan comparando solo contra `Footer`. `Page::scopePubliclyLinkable()` ya no excluye `Header` (no hace falta, no existe más).
- **Archivos/áreas**: `app/Enums/PageTypeEnum.php`, `app/Filament/Resources/PageResource/Pages/ManagePages.php`, `app/Filament/Resources/PageResource.php`, `app/Filament/Schemas/HeadingFieldset.php`, `app/Models/Page.php`.
- **Verificación**: balance de llaves/paréntesis/corchetes vía script Python en los 5 archivos, sin cambios de delta respecto al baseline. Ver ADR-053 y la entrada equivalente en `cica360/docs/context/PROGRESS.md` (`PageType` union en `types.ts`).
- **Siguiente**: `vendor/bin/pint --dirty --format agent`, confirmar visualmente en Studio que el menú "+ Crear Contenido" ya solo ofrece Página/Pie de página/Aviso Legal.

## 2026-09-11 — 8 bloques nuevos suman los 3 tipos de fondo (sólido/degradado/imagen), mismo patrón ya usado por `cta`/`features`/`colophon`/`heading`

- **Pedido del Tech Lead**: "asi deberia poder cambiarse de la misma forma los demas bloques existentes, todos deberian permitir personal el fondo en 3 tipos, no se por que solo 4 bloques tiene eso" — tras la corrección del punto de "Imágenes del Encabezado" en `heading` (entrada de abajo), pidió generalizar el mismo esquema. Confirmó explícitamente 3 exclusiones en el camino: "en el hero obviar", "en el footer_bottom obviar", "split tambien obviar". Elegido el alcance final vía pregunta directa: los 8 candidatos claros — `rich_text`, `faq`, `contact_form`, `testimonials` (teaser), `logos`, `services_grid`, `testimonials_grid`, `legal_notice`. Quedan deliberadamente afuera, además de las 3 exclusiones del Tech Lead: `image`/galería (no tiene noción de "fondo" propia) y `footer` (ya resuelto aparte).
- **Qué se hizo (cada uno de los 8 bloques)**: la `Section` "Personalización de estilos" pasa su selector de `background_type` (2 opciones: sólido/degradado) a `background_type_image` (3 opciones, mismo campo subyacente `properties.background_type`, ver `PropertiesSchema.php`), se agrega `MediaUpload::make('content.background_image_id', 'Imagen de fondo')` visible solo con `background_type: image`, y un `Grid::make(2)` de filtros (`media_blend_mode`, `overlay_opacity`, `media_brightness`, `media_opacity`, `media_filter_saturate/grayscale/sepia/contrast/hue_rotate/blur`) con la misma visibilidad condicional — ambos campos nuevos anidados DENTRO de la misma sección que el selector (mismo criterio recién corregido en `heading`, no un acordeón aparte).
- **Bug dormido encontrado y corregido de paso**: 6 de los 8 bloques (todos menos `legal_notice`, que no tenía fondo en absoluto) ya ofrecían "Degradado" como opción de `background_type` en el Select, pero NUNCA tuvieron los campos `background_color_secondary`/`gradient_direction` — la opción existía en el admin pero no hacía nada, un degradado "fantasma" desde que se creó cada uno de esos bloques. Se agregan esos 2 campos a los 6 (`rich_text`, `faq`, `contact_form`, `testimonials`, `logos`, `services_grid`, `testimonials_grid`), mismo criterio de "no dejar opciones muertas en el admin" ya aplicado esta sesión al bloque `contact_form`.
- **Caso especial — `logos`**: este bloque ya reutilizaba `media_opacity`/`media_filter_grayscale` para OTRA cosa (el efecto hover de "gris a color" de cada logo, en `Logos.astro`). El nuevo grid de filtros de fondo-imagen para este bloque puntual excluye esos 2 campos (quedan reservados para el efecto existente) — limitación documentada inline, no un patrón a repetir en otro bloque.
- **`legal_notice`**: no tenía NINGÚN campo de fondo (solo `padding_y`/`content_width`) — se construye la `Section` de estilos completa desde cero.
- **Registro de medios (`ResolvesPublicLinks::BLOCK_MEDIA_FIELDS`)**: agregar el `MediaUpload` en el schema de Filament no alcanza — sin una entrada en este registro, el API público nunca resuelve `background_image_id` a un objeto `Media` (`content.background_image`), y el campo interno queda expuesto crudo (violación de ADR-018, "el API nunca expone ids internos"). Se agregan las 8 entradas nuevas (`'{bloque}' => ['background_image_id' => 'background_image']`).
- **Archivos/áreas**: `app/Filament/Resources/PageResource.php` (los 8 bloques), `app/Http/Concerns/ResolvesPublicLinks.php` (`BLOCK_MEDIA_FIELDS`).
- **Verificación**: sin PHP en este sandbox — balance de llaves/paréntesis/corchetes verificado vía script Python en ambos archivos, delta sin cambios respecto al baseline preexistente (`+2`). Pendiente que el Tech Lead corra `vendor/bin/pint --dirty --format agent` y confirme visualmente en Studio.
- **Siguiente**: ver ADR-052 (decisión formal del alcance/exclusiones) y la entrada equivalente en `cica360/docs/context/PROGRESS.md` (lado frontend, mismo día) — de nada sirve el campo en Filament si el componente Astro correspondiente no lo consume.

## 2026-09-11 — Bloque `heading` (Filament): "Imágenes del Encabezado" ya no aparece separada/desubicada al elegir Tipo de fondo = Imagen

- **Pedido del Tech Lead**, con captura: "en el admin en el bloque heading, creo que esta mal que cuando se cambien dentro de propiedades visuales, en el campo Tipo de fondo: imagen, ahi recien se muestre la seccion de Imagenes del Encabezado. se ve raro eso" — pidió elegir entre (1) mostrar siempre esa sección y cambiar property según la opción, o (2) mover la sección debajo de "Propiedades Visuales".
- **Recomendación aplicada (ninguna de las 2 tal cual, la que ya usa el resto del código)**: `cta`, `features` y `colophon` ya resuelven el mismo problema (fondo sólido/degradado/imagen) poniendo el selector "Tipo de fondo" y su campo de imagen dependiente EN LA MISMA sección — `heading` era el único bloque que los tenía en 2 secciones separadas (`Section` "Imágenes del Encabezado", ubicada ANTES de "Propiedades Visuales", con el selector escondido DENTRO de esta última, colapsada). Se mueve la `Section::make('Imágenes del Encabezado')` para que viva anidada dentro de `Section::make('Propiedades Visuales')`, justo después de la grilla de campos que incluye el selector — se abre la sección una sola vez y todo lo de fondo aparece junto, sin saltos ni contenido apareciendo fuera de foco. Se descartó mostrarla siempre (opción 1 literal): dejaría 3 campos de imagen vacíos visibles en la inmensa mayoría de páginas que usan fondo sólido/degradado.
- **Archivos/áreas**: `app/Filament/Resources/PageResource.php` (bloque `heading`).
- **Siguiente**: confirmar visualmente que al elegir "Imagen" en Tipo de fondo (dentro de Propiedades Visuales) las 3 imágenes aparecen ahí mismo, sin necesidad de scrollear a otra sección.

## 2026-09-11 — Sección "Personalización de estilos": faltaba `->columns(2)` en 8 bloques (Filament, tablet/desktop quedaba a 1 columna)

- **Pedido del Tech Lead**, con captura de "Personalización de estilos" en 1 sola columna en desktop: "recuedas las alturas..." — corrección puntual: "en tablet y desktop debe siempre ser a 2 columnas las properties".
- **Causa**: al agregar cada bloque nuevo (`services_grid`, `testimonials_grid`, `contact_form`, FAQ, aviso legal, galería) se usó `PropertiesSchema::make([...])` sin encadenar `->columns(2)` — Filament cae a 1 columna por defecto en cualquier viewport si no se especifica.
- **Fix**: se agrega `->columns(2)` a los 8 `PropertiesSchema::make([...])` que no lo tenían (bloques: galería/media, `faq`, `contact_form`, `legal_notice`, `services_grid`, `testimonials_grid` — 2 secciones "Personalización de estilos" cada uno). Se dejó sin tocar el único caso de un solo campo (`show_scroll_indicator`, no necesita columnas).
- **Archivos/áreas**: `app/Filament/Resources/PageResource.php`.
- **Siguiente**: confirmar visualmente en tablet/desktop que las 2 columnas quedan parejas en todos los bloques tocados.

## 2026-09-11 — Bloque `contact_form` (Filament): quitado el "Encabezado" fantasma, agregado copy y agrupamiento

- **Pedido del Tech Lead**, con captura del formulario de edición: "corrijamos o alineamos el bloque de formulario de contactos se ve super mal el acabado, tiene ser mas UX y con buen copy".
- **2 problemas reales encontrados**: (1) el bloque traía `HeadingFieldset::make()` (Pre título/Título/Subtítulo) igual que todos los demás bloques, pero desde la corrección de esta misma sesión ("el formulario no debe tener nada en el heading", ver `ContactFormBlock.astro` en cica360) el frontend NUNCA lee ese heading para este bloque — quedaban 3 campos que no hacían nada, confusos para cualquier editor. (2) a diferencia del resto de bloques, ni el selector de Formulario/texto de introducción ni las properties de fondo/estilo estaban agrupados en un `Section` con título/descripción — quedaban sueltos, sin jerarquía visual ni copy que explique qué hace cada campo.
- **Fix**: se elimina `HeadingFieldset::make()` del bloque. El selector de Formulario + texto de introducción pasan a vivir dentro de `Section::make('Formulario')` con descripción; se agrega `helperText` a ambos campos explicando su efecto real (dónde se crean los formularios, dónde aparece el texto de introducción). Las properties de fondo/estilo pasan a `Section::make('Personalización de estilos')` con descripción y `->collapsed()`, mismo patrón que el resto de bloques (y ya con `->columns(2)`, ver entrada anterior). Todo el copy nuevo en español neutro sin voseo (ADR-051) — se corrigió una redacción propia con voseo ("Elegí"/"buscás"/"creá") detectada al revisar el propio diff antes de cerrar el cambio.
- **Archivos/áreas**: `app/Filament/Resources/PageResource.php` (bloque `contact_form`).
- **Siguiente**: confirmar visualmente en el admin que ya no aparece "Encabezado (Opcional)" en este bloque y que las 2 secciones nuevas se leen bien.

## 2026-09-11 — `testimonials_grid` (página Casos de Éxito): `content.limit` explícito (9 de 12 testimonios)

- **Pedido del Tech Lead**: "para el seeder del bloque de casos de exito grid se necesita dejar la cantidad a mostrar y orden que este seteado e integrado con el frontsite" — `content.limit` pasa de `null` (catálogo completo, sin tope) a `9` en `upsertCasosDeExitoPage()`. `content.order: 'asc'` ya estaba seteado desde la primera vuelta (ADR-050).
- **"Integrado con el frontsite" — verificado, sin cambios de código adicionales**: `ResolvesPublicLinks::transformBlockContent()` (rama `testimonials_grid`) ya recorta con `$ordered->take((int) $content['limit'])` cuando el valor es numérico — funciona igual con `9` que con `null` (antes tomaba el catálogo completo por ser `empty()`). `TestimonialsGrid.astro` (cica360) solo lee `content.items[]` ya resuelto/recortado por el backend, sin lógica de límite propia — con 9 items servidos (de los 12 sembrados en `Cliente0TestimonialsSeeder`) y su propio `INITIAL_VISIBLE`/`STEP` de 6, el botón "Más casos" revela los 3 restantes de esos 9; los 3 testimonios fuera del límite (10º-12º por `sort_order`) no llegan nunca a esta página — comportamiento esperado de un límite real, a diferencia de `services_grid` (9 de 9, sin recorte visible).
- **Archivos/áreas**: `database/seeders/Cliente0ContentSeeder.php` (`upsertCasosDeExitoPage()`, bloque `testimonials_grid`).
- **Siguiente**: correr `php artisan db:seed` y confirmar visualmente que `/casos-de-exito` muestra 9 testimonios (6 al cargar + 3 tras "Más casos"), no los 12 sembrados.

## 2026-09-11 — Formulario "Contactame": select de País ampliado a 16 (Centroamérica + resto de Sudamérica hispanohablante)

- **Pedido del Tech Lead**: "aumentar mas paises del continente latinoamericano centro-sur" — los 8 países originales (`$countryOptions` en `upsertContactForm()`) tenían bandera real sembrada para Servicios, pero este `<select>` de Contacto es texto plano sin ícono, así que sumar países no depende de ningún asset nuevo. Se agregan Colombia, Venezuela (resto de Sudamérica hispanohablante) y Panamá, Costa Rica, Nicaragua, Honduras, El Salvador, Guatemala (Centroamérica hispanohablante) — deliberadamente sin México (Norteamérica), Caribe, ni Guyana/Surinam/Belice (no hispanohablantes, no pedidos).
- **Archivos/áreas**: `database/seeders/Cliente0ContentSeeder.php` (`upsertContactForm()`, `$countryOptions`).
- **Siguiente**: correr `php artisan db:seed`. Ver entrada equivalente en `cica360/docs/context/PROGRESS.md` (mismo día) — `ContactForm.tsx`'s `COUNTRY_OPTIONS` actualizado en paralelo, misma lista.

## 2026-09-11 — Bloque `contact_form`: fondo `cicagray-50` (`#F6F6F6`), mismo criterio que `services_grid`/`testimonials_grid`

- **Pedido del Tech Lead**: "mira l espectativa tambien indica que el fondo es cicagray-50" — el bloque `contact_form` de `upsertContactoPage()` ya tenía `background_type`/`background_color` disponibles en su schema de Filament (`PropertiesSchema::make([...])`), pero sin sembrar. Se agrega `properties.background_type: 'solid'`/`background_color: '#F6F6F6'`, mismo tono ya usado en `services_grid` (página Servicios) y `testimonials_grid` (página Casos de Éxito).
- **Archivos/áreas**: `database/seeders/Cliente0ContentSeeder.php` (`upsertContactoPage()`, bloque `contact_form`).
- **Siguiente**: correr `php artisan db:seed`. Ver entrada equivalente en `cica360/docs/context/PROGRESS.md` (mismo día) — `ContactFormBlock.astro` ahora consume `block.properties` vía `resolveBackgroundStyle()` (antes lo ignoraba por completo).

## 2026-09-11 — Página Contacto: bloque `rich_text` "Hablemos" eliminado, `contact_form` sin heading propio

- **Pedido del Tech Lead**, con captura mostrando el bloque "Hablemos" (título + párrafo) renderizado justo encima del formulario: "no necesitamos este bloque, y el formulario no debe tener nada en el heading".
- **Qué se hizo**: se elimina por completo el bloque `rich_text` ("Hablemos", con su párrafo introductorio) de `upsertContactoPage()` — antes vivía entre el banner `heading` ("Contactame") y el bloque `contact_form`. El bloque `contact_form` pierde `title` ("Envíanos tu consulta") y `content.intro` — queda solo con `content.form_id`. La página va ahora directo del banner al formulario, sin ningún texto intermedio.
- **Archivos/áreas**: `database/seeders/Cliente0ContentSeeder.php` (`upsertContactoPage()`).
- **Siguiente**: correr `php artisan db:seed`. Ver entrada equivalente en `cica360/docs/context/PROGRESS.md` (mismo día) — `ContactFormBlock.astro` ya no renderiza heading propio bajo ninguna circunstancia (no solo por falta de dato sembrado).

## 2026-09-11 — "Filtro de testimonios" (bloque `testimonials`, teaser): copy sin la palabra "trae"

- **Pedido del Tech Lead**: "cuando expresas 'acá solo se elige cuáles trae este bloque' tal vez no se entienda... por que ese traer no creo que lo entiendan... la idea es que muchos usuarios no solo desarrolladores lo usen, con la IA hasta profesionales y gente comun crea sus webs y necesita de una CMS headless" — sugirió reemplazar por "cuáles se comparten públicamente en el API", pidiendo corrección si estaba equivocado.
- **Corrección aplicada** (el Tech Lead invitó a corregirlo si hacía falta): la frase sugerida ("...se comparten públicamente en el API") describe algo que este campo NO controla — la visibilidad pública de un testimonio la define el toggle "Visible" del propio módulo Testimonios, no este filtro. Este campo (`content.limit`/`content.order`) solo decide CUÁNTOS testimonios y en qué orden se muestran EN ESTE BLOQUE en particular. Se optó por lenguaje llano fiel a lo que el campo realmente hace, sin introducir el concepto técnico de "API" (innecesario para un usuario no técnico): "Los testimonios se administran en el módulo Testimonios, disponible en el menú lateral. Aquí solo se define cuántos se muestran en este bloque y en qué orden. Se muestran únicamente los marcados como visibles en ese módulo."
- **Archivos/áreas**: `app/Filament/Resources/PageResource.php` (Section "Filtro de testimonios" del bloque `testimonials`).
- **Siguiente**: confirmar que se entiende bien para un usuario sin perfil técnico.

## 2026-09-11 — Corrección urgente: sacado el voseo del copy de Stamless — regla de tono formalizada (ADR-051)

- **Corrección del Tech Lead**, inmediatamente después de la vuelta de copywriting anterior (ver entrada de abajo): "nooooo, con mucho cuidado todo lo de stamless tiene que estar en un tono hipano neutral y amigable para gente comun no necesariamente tecnica o profesional, nada de argento o jergas" — la reescritura anterior había usado voseo rioplatense ("Dejalo vacío...", "indicá un número...", "Agregá un botón...") por analogía incorrecta con la voz de marca de CICA360 (que SÍ usa voseo, pero es un tenant particular, no el producto Stamless).
- **Qué se hizo**: los 6 strings reescritos en la vuelta anterior (2 descriptions + 1 helper "Cantidad a mostrar" por bloque, en `services_grid`/`testimonials_grid`) se corrigen a construcciones neutras/infinitivas ("Dejar vacío...", "Agregar un botón...", "Este bloque solo permite elegir..."). Auditoría de paso encontró 2 instancias PREEXISTENTES del mismo problema, sin relación con esta vuelta: `->description('Elegí si el fondo...')` (campo `background_type`, Section "Fondo", reusada por varios bloques) — corregidas a `Elegir si el fondo...`.
- **Regla formalizada** — ver ADR-051 en `DECISIONS.md`: Stamless (Console/Studio, cualquier copy de Filament) siempre en español neutro; el voseo rioplatense es exclusivo del contenido de marca de CICA360 (seeders de contenido público + componentes `.astro`), no del producto.
- **Archivos/áreas**: `app/Filament/Resources/PageResource.php` (6 strings + 2 preexistentes).
- **Siguiente**: auditoría más amplia (opcional, no bloqueante) de otras secciones de Filament por si quedó más voseo suelto de rondas anteriores a esta regla — no se encontró nada más en `PageResource.php` en esta pasada rápida.

## 2026-09-11 — Copywriting/UX de los bloques `services_grid`/`testimonials_grid` en Console (Studio)

- **Pedido del Tech Lead**, con captura de "Catálogo de casos de éxito" en el admin: "las descripciones de las secciones y campos necesita mejor copywriter y mejor UX por que parece las conversaciones que hemos tenido, corregir en grid de servicio y en grid de casos de exito" — las descripciones/helper texts de estas 2 secciones habían quedado redactadas como notas internas de desarrollo (paréntesis tipo "(ej. el catálogo completo de 'Servicios')", referencias cruzadas a otros botones del sitio, frases largas con doble explicación) en vez de copy claro para quien administra el contenido.
- **Qué se hizo**: reescritas las 4 descripciones + 2 helper texts de "Cantidad a mostrar"/"Orden" en AMBOS bloques (`services_grid` y `testimonials_grid`), pasando a voz imperativa/2da persona con el mismo voseo rioplatense ya usado en el resto de la marca ("Dejalo vacío...", "indicá un número...", "Agregá un botón..."), sin jerga de desarrollo ni ejemplos entre paréntesis. Las 2 secciones "Filtro de/Catálogo de" quedan más cortas y accionables; las 2 secciones "Enlace 'Ver más' (opcional)" ya no mencionan el nombre exacto de otro botón del sitio ("Ver más servicios"/"Más casos") — alcanza con explicar que no hace falta si la página ya muestra el catálogo completo.
- **No tocado a propósito**: la sección "Filtro de testimonios" del bloque `testimonials` (teaser, no `testimonials_grid`) — el pedido fue explícito solo sobre los 2 bloques "grid".
- **Archivos/áreas**: `app/Filament/Resources/PageResource.php` (secciones de `services_grid` y `testimonials_grid`).
- **Siguiente**: confirmar visualmente en Studio que las nuevas descripciones se leen claras y sin jerga técnica.

## 2026-09-11 — Formulario "Contactame": 3 campos nuevos (`city`/`country`/`area_of_interest`) sembrados en el form `contacto`

- **Pedido del Tech Lead**, con captura del mockup real de "Contactame" (Nombre y Apellido/Correo electrónico/Ciudad/WhatsApp/País [select]/Área de interés [select]/Consulta/botón "CONTACTAME AHORA"): "la espectativa de contactame, quiero que se genere el formulario basico y que envie al endpoint correcto".
- **Backend (esta vuelta)**: `FormFieldDefinitionSeeder.php` gana 3 entradas nuevas al catálogo GLOBAL reutilizable (`city`: Text/required; `country`: Select/required; `area_of_interest`: Select/required) — el catálogo global solo fija el TIPO de campo, sin `options` propias (ese modelo nunca las persiste). `Cliente0ContentSeeder::upsertContactForm()` reescrito por completo: pasa de un loop simple sobre 4 keys fijas (`name`/`email`/`phone`/`message`) a un `$fieldsConfig` explícito de 7 campos en el orden del mockup (`name`/`email`/`city`/`phone`/`country`/`area_of_interest`/`message`), cada uno con su label/required propios vía `FormField` (no el default de la `FormFieldDefinition`). `country`/`area_of_interest` llevan `options` concretas por-`FormField` (no en el catálogo global): 8 países LatAm con bandera real ya sembrada en el frontend (AR/UY/BR/BO/CL/PY/PE/EC) y los 9 títulos reales del catálogo de Servicios (`Cliente0ServicesSeeder`), respectivamente.
- **No se tocó** `ContactSubmissionService`/`Contact`/`FormField` — arquitectura ya genérica (`CORE_CONTACT_FIELDS` fijo, cualquier otra key mapeada a `data` jsonb si tiene un `FormField.name` activo) soporta campos nuevos sin cambios de código, solo sembrando el catálogo/form correctos.
- **Archivos**: `database/seeders/FormFieldDefinitionSeeder.php`, `database/seeders/Cliente0ContentSeeder.php` (`upsertContactForm()`).
- **Siguiente**: correr `php artisan db:seed`, `vendor/bin/pint --dirty --format agent`. Ver entrada equivalente en `cica360/docs/context/PROGRESS.md` (mismo día) para el lado frontend (`ContactForm.tsx` reescrito, `ContactFormPayload` en `types.ts`).

## 2026-09-11 — Bloque `testimonials_grid` nuevo — catálogo completo de "Casos de éxito" (mismo tratamiento que `services_grid`, ver ADR-050)

- **Pedido del Tech Lead**, con captura de mockup real de la página "Casos de éxito" (grid 3×3, avatar circular + frase + firma, botón "MÁS CASOS"): "la espectativa esta en la primera captura y la realidad, es un bloque de testimonios que creamos a modo preview o resumen solo para home u otras paginas, pero este tiene que ser un bloque nuevo especial como el de servicios, donde va el heading y luego la configuracion todo igual al de servicios en el admin. luego la integracion en astro tiene que ser con ese mismo aspecto de la espectativa de diseño. no olvides preparar el seeder".
- **Implementado** (espejo punto por punto de `services_grid`/ADR-049, ver ADR-050 para el detalle completo): `BlockTypeEnum::TestimonialsGrid` nuevo; `PageResource.php` gana `Builder\Block::make('testimonials_grid')` (copia 1:1 del schema de `services_grid` — heading + `content.limit`/`content.order` + link único opcional + estilos) y `$testimonialsGridLinkFields`; `ResolvesPublicLinks.php` — `testimonials_grid` comparte la MISMA query batched que ya arma el bloque `testimonials` (teaser), sin duplicar el `SELECT`, pero ordena por `sort_order` (curaduría manual) en vez de `created_at` (recencia), mismo criterio que distingue `services_grid` de un teaser; el item resuelto sale con `uuid`/`name`/`role`/`quote`/`avatar` (sin `slug`/`href`, un testimonio no tiene página de detalle propia). `Cliente0ContentSeeder::upsertCasosDeExitoPage()`: el bloque `testimonials` (teaser, colores `cicagreen-*`) se reemplaza por `testimonials_grid` (`limit: null, order: asc`, fondo `#F6F6F6`, sin `title` propio); `decorator_bottom_color` del `heading` de esa misma página pasa de `#ffffff` a `#F6F6F6` (mismo criterio que "Servicios"). El bloque `testimonials` (teaser) de Home/"Sobre CICA" queda SIN CAMBIOS.
- **Frontend (cica360, misma vuelta)**: `TestimonialsGrid.astro` nuevo — misma estructura de sección que `ServicesGrid.astro` (heading + grid estático 1/2/3 columnas, sin carousel, "Más casos" de a 6 100% client-side), tarjeta reusando el diseño ya validado de `Testimonials.astro` (avatar circular, frase itálica, firma "— Nombre"), sin chips de filtro. `BlockRenderer.astro`/`types.ts` actualizados.
- **Archivos**: `app/Enums/BlockTypeEnum.php`, `app/Filament/Resources/PageResource.php`, `app/Http/Concerns/ResolvesPublicLinks.php`, `database/seeders/Cliente0ContentSeeder.php` (genesis); `src/components/blocks/TestimonialsGrid.astro`, `src/components/blocks/BlockRenderer.astro`, `src/lib/types.ts` (cica360).
- **Siguiente**: correr `php artisan db:seed`, `vendor/bin/pint --dirty --format agent`, y confirmar visualmente `/casos-de-exito` contra el mockup. Actualizar `docs/context/api/stamless-api-v1.md` (ambos repos) con el contrato de `testimonials_grid`.

## 2026-09-11 — Página Servicios: `content.limit`/`content.order` explícitos en el bloque `services_grid` del seeder

- **Pedido del Tech Lead**: "el catalogo de servicios cuando se configura en el seeder falta especificar la cantidad a mostrar de forma dinamica como 9 y el orden manual del catalogo" — el contenido semilla de `services_grid` en `upsertServiciosPage()` traía `content.limit: null` (sin tope, "todos los publicados") sin ejemplificar el campo admin-editable (`content.limit`/`content.order`, sección "Catálogo de servicios" en `PageResource.php`). Pasa a `content.limit: 9` (cantidad a mostrar) + `content.order: 'asc'` (orden MANUAL — el `sort_order` curado a mano en `ServiceResource`, no recencia, ver ADR-049).
- **Archivos/áreas:** `database/seeders/Cliente0ContentSeeder.php` (`upsertServiciosPage()`, bloque `services_grid`).
- **Siguiente:** correr `php artisan db:seed` y confirmar que `/servicios` sigue mostrando el catálogo completo (9 de 9, dado que hoy el total coincide con el límite).

## 2026-09-11 — Fix real: CTA duplicado en la página Servicios ("¿Listo para transformar tu negocio?" apilado 2 veces)

- **Bug reportado con captura**: "doble bloque en el contenido inicial" — la página `/servicios` mostraba el mismo banner CTA dos veces seguidas. Causa: al agregar (2026-09-10) "los demás bloques tienen que estar en el contenido inicial del seeder", se sembró un bloque `cta` explícito dentro de `upsertServiciosPage()` — sin recordar que ESE MISMO CTA ya se agrega automáticamente a TODAS las páginas públicas vía el bloque `footer` compartido (`appendFooterBlock()` → `upsertFooterPage()`, que lo tiene desde 2026-09-01). Este precedente ya estaba documentado en el propio archivo (comentario idéntico en `upsertHomePage()`/`upsertSobreCicaPage()`: "el CTA final... NO se agrega acá — ya viene incluido automáticamente vía el bloque `footer` compartido... agregarlo de nuevo acá lo duplicaría") — se pasó por alto al escribir la página Servicios.
- **Fix**: se saca el bloque `cta` de `upsertServiciosPage()`. Como ya no necesitaba el link hacia "Contacto", se revierte también el parámetro `Page $contactoPage` que se le había agregado (y el reordenamiento de `$pages` en `run()` que eso forzó) — `upsertServiciosPage()` vuelve a su firma original (`Tenant $tenant` solo) y `servicios` vuelve a crearse junto a `casos-de-exito` en el mismo array literal, sin dependencias.
- **Archivos/áreas:** `database/seeders/Cliente0ContentSeeder.php` (`run()`, `upsertServiciosPage()`).
- **Siguiente:** correr `php artisan db:seed` y confirmar que la página `/servicios` ya muestra un solo banner CTA al pie.

## 2026-09-11 — Página Servicios: fondo `cicagray-50` en el bloque `services_grid`, mismo color en el decorador del banner

- **Pedido del Tech Lead**: "cicagray-50 es el background del bloque servicio y el mismo del decorador en el header" — `cicagray-50` = `#F6F6F6` (`--color-cicagray-50`, cica360/global.css). Se agrega `properties.background_type: 'solid'`/`background_color: '#F6F6F6'` al bloque `services_grid` de `upsertServiciosPage()`, y se cambia `decorator_bottom_color` del bloque `heading` de la misma página de `#ffffff` a `#F6F6F6` — el mismo color en ambos hace que la ola del banner se funda con el fondo del bloque de abajo en vez de cortar contra blanco.
- **Archivos/áreas:** `database/seeders/Cliente0ContentSeeder.php` (`upsertServiciosPage()`: `properties` del bloque `heading` y del bloque `services_grid`).
- **Siguiente:** correr `php artisan db:seed`. Confirmar visualmente que la transición banner→grid ya no muestra ninguna costura de color.

## 2026-09-11 — Catálogo de Servicios reemplazado por el dataset REAL (9 servicios, imágenes dedicadas) — descarta el dataset de ejemplo de la 1ra vuelta

- **Pedido del Tech Lead**, con 2 capturas: el árbol de `storage/app/public/media/` mostrando 9 archivos ya subidos (`cica360_media_service_*.webp`, uno por servicio) y una tabla Título/Descripción con el catálogo real de 9 servicios: "en el demo de contenidos el primero es Seguro financiero el titular correcto de todo ese contenido de ejemplo, pero el resto de servicios que deberian haber son estos... cambiar la data con 9 servicios... con sus respectivos contenidos segun el primer contenido de ejemplo".
- **Qué se hizo**: `Cliente0ServicesSeeder.php` reescrito por completo — los 12 servicios de ejemplo de la 1ra vuelta (8 migrados + 4 inventados, con 6 imágenes genéricas reutilizadas cíclicamente) se reemplazan por los 9 reales: Seguridad Financiera, Seguro Financiero, Asesoría y Consultoría Estratégica, Asesoría Contable y Financiera, Asesoría Editorial Integral, Turismo y Asesoría Vacacional, Bienes Raíces e Inversión, Asesoramiento Legal Integral, Asesoría Notarial — título/subtítulo exactos de la tabla entregada, cada uno con SU PROPIA foto dedicada (ya no genéricas cíclicas). "Seguro Financiero" hereda el `content.intro` que en la 1ra vuelta vivía bajo "Seguros generales" (pólizas patrimoniales/RC) — el Tech Lead confirmó que ese contenido correspondía a este título, no al anterior. El resto de `intro` reutiliza el tono de los rubros que ya existían cuando el tema coincide, y agrega intro propio corto para los 3 rubros nuevos (editorial, turismo, notarial) extendiendo la descripción dada, sin inventar detalles no verificables. Se agrega un paso de PODA al final del seeder (`Service::whereNotIn('slug', $slugs)->delete()`) para que los 12 registros de la 1ra vuelta no queden huérfanos junto a los 9 reales.
- **`Cliente0MediaSeeder.php`**: 9 entradas nuevas (`service_*`) registrando los archivos ya subidos por el Tech Lead a `storage/app/public/media/`.
- **Efecto colateral esperado**: con exactamente 9 servicios (antes 12), el botón "Ver más servicios" de `ServicesGrid.astro` (cica360) no se muestra por ahora (`items.length > 9` deja de cumplirse) — comportamiento correcto y ya contemplado en el componente, no un bug; el catálogo real puede superar las 9 más adelante y el botón reaparece solo.
- **Archivos/áreas:** `database/seeders/Cliente0ServicesSeeder.php` (reescrito), `database/seeders/Cliente0MediaSeeder.php` (9 entradas nuevas).
- **Siguiente:** correr `php artisan db:seed` (o `db:seed --class=Cliente0MediaSeeder` seguido de `--class=Cliente0ServicesSeeder`) para que el catálogo real reemplace al de ejemplo. Confirmar que los 9 archivos `.webp` realmente están commiteados en `storage/app/public/media/` (no solo visibles en el árbol del editor) antes de sembrar. Pendiente confirmación visual del Tech Lead contra la tabla entregada.

## 2026-09-10 — Corrección al seeder de la página Servicios: sin bloque `rich_text` intro, `services_grid` sin título

- **Agente/autor:** Claude (genesis).
- **Qué se hizo:** pedido del Tech Lead revisando el contenido inicial recién sembrado: "en seeder, en el contenido inicial el servicio no tienen el bloque de texto enriquecido y el bloque de servicios pero sin titulo de contenido" — el mockup real de "Servicios" no tiene ningún párrafo introductorio entre el banner y el grid (va directo del `heading` al catálogo), y el grid tampoco tiene un heading propio arriba (el banner superior ya cumple ese rol). Se saca por completo el bloque `rich_text` ("Qué ofrecemos") de `upsertServiciosPage()` y se quita `title: 'Nuestros servicios'` del bloque `services_grid` (queda solo `type`/`content`, sin `pretitle`/`title`/`subtitle` — `syncBlocks()` los default a `null` si no vienen seteados). `BlockHeading.astro` (cica360) ya está preparado para no renderizar nada cuando los 3 campos vienen `null`, así que no hizo falta tocar el frontend.
- **Archivos/áreas:** `database/seeders/Cliente0ContentSeeder.php` (`upsertServiciosPage()`).
- **Siguiente:** correr `php artisan db:seed` para que el cambio se refleje (bloque `rich_text` de una corrida anterior queda podado automáticamente por `syncBlocks()`, vía el `sort_order >= count($blocks)` al final del método). Confirmar visualmente que la página `/servicios` ya no muestra el párrafo ni el heading "Nuestros servicios" sobre el grid.

## 2026-09-10 — `services_grid` resuelto en runtime contra la tabla `services` (ADR-049, cierra el pendiente de ADR-034)

- **Agente/autor:** Claude (genesis).
- **Qué se hizo:** pedido del Tech Lead, con mockup completo de la página "Servicios" (banner, grid 3×3 de cards con imagen/título/subtítulo/banderas de país/CTA, botón "MÁS SERVICIOS", banner CTA final): resolver el pendiente que ADR-034 había dejado abierto sobre `services_grid`. Se replicó 1:1 el patrón de `testimonials` (ADR-033): el bloque `services_grid` de `PageResource.php` pierde su `Repeater::make('content.items')` manual y los `TextInput::make('title')`/`TextInput::make('subtitle')` duplicados (ya cubiertos por `HeadingFieldset::make()`), reemplazado por una `Section` "Catálogo de servicios" (`content.limit` nullable — vacío = todos los publicados; `content.order` `asc`/`desc` sobre `sort_order`, no `created_at` como testimonios — un catálogo se cura a mano, no tiene noción de "más reciente") + un enlace único opcional (`LinkSchema::makeSingle()`, mismo patrón que `testimonials`/`cta`) + la Section de estilos ya existente. `ResolvesPublicLinks.php` gana la rama de resolución: query batched `Service::query()->published()->get()`, recorte/orden en memoria por bloque, cada item resuelto con la misma forma que `ServiceSummaryResource` (`uuid`/`slug`/`pretitle`/`title`/`subtitle`/`countries`/`image`) más `href` (`/servicios/{slug}`). Se creó `Cliente0ServicesSeeder.php` (12 servicios reales en la tabla `services` — los 8 que ya vivían inline en el bloque, migrados tal cual, más 4 nuevos tomados de rubros ya presentes en `Cliente0TestimonialsSeeder`, para superar el umbral de 9 y poder demostrar la paginación "Ver más servicios" de a 9 — reutiliza 6 imágenes genéricas ya sembradas, cíclicas). `upsertServiciosPage()` (`Cliente0ContentSeeder.php`) gana el parámetro `Page $contactoPage`, el bloque `services_grid` sembrado pasa a `content.limit: null, order: 'asc'` (catálogo completo), y se agrega un bloque `cta` final "¿Listo para transformar tu negocio?" (mismo CTA reutilizable que ya cierra Home/Sobre CICA) — el `run()` principal se reordenó para que `contacto` exista antes de llamar a `upsertServiciosPage()`.
- **Archivos/áreas:** `app/Http/Concerns/ResolvesPublicLinks.php`, `app/Filament/Resources/PageResource.php` (bloque `services_grid`), `database/seeders/Cliente0ServicesSeeder.php` (nuevo), `database/seeders/Cliente0ContentSeeder.php` (`run()`, `upsertServiciosPage()`), `docs/context/DECISIONS.md` (ADR-049).
- **Siguiente:** frontend (cica360) — rediseñar `ServicesGrid.astro` como grid estático (NO carrusel, el Tech Lead aclaró explícitamente "testimonials usa slides o sliders y eso no es el caso, aquí es modo grid") reusando `BlockHeading.astro` y la estructura de sección estándar de `Features`/`RichText`/`Logos`, con "Ver más servicios" revelando de a 9 100% client-side sobre datos ya horneados en build (sitio 100% estático, sin fetch en vivo). Sin PHP en este sandbox — pendiente `php artisan db:seed`, `vendor/bin/pint --dirty --format agent`, y confirmación visual del Tech Lead contra el mockup.

## 2026-09-10 — Filament: `show_scroll_indicator` agregado al schema del bloque `features`

- **Agente/autor:** Claude (cica360, extendido a genesis por necesidad del frontend).
- **Qué se hizo:** pedido del Tech Lead en cica360: "no veo hasta ahora la flecha de invitacion a scrollear que ya usa otros bloques que ya hemos hecho" — al implementar el mismo patrón de `show_scroll_indicator` en `Features.astro` (mismo que `RichText.astro`/`Hero.astro`/`SliderResource`), se confirmó que el campo NO estaba declarado en el schema de Filament del bloque `features` — solo en `slider`/`hero`/`rich_text`. Se agregó `PropertiesSchema::make(['show_scroll_indicator'])` a la Section "Personalización de estilos" del bloque `features`. Campo 100% reusable (`PropertiesSchema.php`, `Forms\Components\Toggle` bindeado a `properties.show_scroll_indicator`, sin `visible()`/dependencias externas) — no requirió ningún setup adicional, solo agregar la llamada.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (bloque `features`, `Section::make('Personalización de estilos')`, gana `PropertiesSchema::make(['show_scroll_indicator'])`).
- **Siguiente:** sin `php` disponible en este sandbox, no se pudo correr `php -l`/tests — revisado manualmente contra los otros 3 call-sites existentes del mismo campo (patrón idéntico). Confirmar en el admin que el toggle aparece y funciona; activar el toggle para la página "Sobre CICA" (Misión/Visión/Valores) si se quiere la flecha visible ahí, per pedido del Tech Lead.

## 2026-09-09 — Seeder: título/subtítulo del bloque `features` acortados (título 1 línea, subtítulo 2 líneas)

- **Agente/autor:** Claude (cica360, extendido a genesis por el mismo seeder de contenido).
- **Qué se hizo:** pedido del Tech Lead: "Cambiar titulo y subtitulo para que el titulo no pase de una linea y el subtitulo no pase de 2 lineas" — en `Features.astro` (cica360) este heading vive dentro de la sección pineada a `100vh` en mobile/tablet, así que la cantidad de líneas que ocupa el texto afecta el layout, no solo el estilo. Título baja de 35 a 19 caracteres ("La confianza se construye de cerca" → "Confianza de cerca"); subtítulo baja de 86 a 51 caracteres ("Con cercanía y transparencia genuinas, acompañamos tu crecimiento con compromiso real." → "Cercanía, transparencia y compromiso en cada paso."). Mismo enfoque persuasivo/PNL ya establecido, reforzando los mismos valores que listan las tarjetas de abajo (Cercanía/Transparencia/Compromiso, presentes en `content.items` "Valores" de este mismo bloque).
- **Archivos/áreas:** `database/seeders/Cliente0ContentSeeder.php` (bloque `features` de `upsertSobreCicaPage()`: `title`/`subtitle`, comentario nuevo con esta 5ta vuelta documentada).
- **Siguiente:** correr `php artisan db:seed` (o el seeder específico) para persistir el cambio en la base — no ejecutado en esta sesión (sin conexión a base de datos disponible acá). Confirmar visualmente en cica360 que el título entra en 1 línea y el subtítulo en 2 en todas las resoluciones del sitio.

## 2026-09-09 — Reversión deliberada: se agrega pretitle/título/subtítulo al bloque `features` de "Sobre CICA" (Misión/Visión/Valores)

- **Contexto**: el 2026-09-07 se había sacado el heading de este bloque a pedido explícito del Tech Lead ("en este diseño no se usa ningun heading" — ver entrada de más abajo), siguiendo fiel al Figma de referencia. Esa decisión sigue siendo correcta para el motivo por el que se tomó.
- **Pedido nuevo, motivo distinto** (cica360, mismo día): en tablet/mobile, `Features.astro` pinea la sección a `100vh`/`100dvh` mientras dura el scroll horizontal del carousel — necesario por cómo funciona el pin de GSAP ScrollTrigger (ver PROGRESS.md de cica360, "el height:100vh era necesario, no cosmético"). Con las tarjetas centradas verticalmente en una sección de pantalla completa y sin heading, quedaba mucho espacio vacío arriba/abajo del carousel (capturas del Tech Lead en tablet/mobile). Pedido textual: "poner un mejor titulo y subtitulo un poco largos ahi para disimular un poco... tienen que ser bien persuasivos y marketeros, que no suene incoherente si no acorde a su identidad... aplicar PNL si es posible... que no sea un simple relleno si no que sea util y con proposito".
- **Qué se hizo**: se agregan `pretitle`/`title`/`subtitle` al bloque `features` en `Cliente0ContentSeeder.php::upsertSobreCicaPage()` — copy persuasivo con técnicas de PNL (presuposiciones — "acompañamos... que buscan crecer con la certeza de", predicados sensoriales — "escuchamos primero, actuamos con claridad después"), voseo regional (consistente con el resto del sitio), y reforzando explícitamente los mismos VALORES que las 3 tarjetas listan abajo (transparencia, cercanía, compromiso) para que el heading no se sienta desconectado del contenido:
  - `pretitle`: "Nuestra esencia"
  - `title`: "La confianza se construye con cercanía, transparencia y resultados reales"
  - `subtitle`: "En CICA acompañamos a personas y organizaciones que buscan crecer con la certeza de contar con un aliado genuino. Escuchamos primero, actuamos con claridad después, y sostenemos cada relación con la misma transparencia, cercanía y compromiso que nos definen."
- **Archivos**: `database/seeders/Cliente0ContentSeeder.php` (bloque `features` de `upsertSobreCicaPage()`).
- **Verificación**: balance de sintaxis (tokenizer Python) OK. Sin PHP disponible en este sandbox para `php -l`. **Pendiente**: correr `php artisan db:seed --class=Cliente0ContentSeeder` (o el seeder que corresponda) en un entorno con PHP/DB real, y confirmación visual del Tech Lead en tablet/mobile de que el heading efectivamente reduce la sensación de vacío.
- **Siguiente**: si el Tech Lead pide ajustar el tono/largo del copy, es un cambio de texto puntual en el mismo array — no requiere tocar `Features.astro` (ya soporta pretitle/título/subtítulo condicionalmente desde el rediseño original).

**CORRECCIÓN (2da vuelta, mismo día) — error real en vivo**: el Tech Lead corrió el seeder y reportó, con captura del error de Laravel: `QueryException`, "value too long for type character varying(255)" — `subtitle` de la tabla `blocks` es `varchar(255)` y el texto original (~265 caracteres) lo excedía. Feedback: "muy largo el titulo, tiene que ser mas corto y no estamos usando pretitulo". Fix:

- Se saca `pretitle` por completo — consistente con el resto del seeder (`testimonials`/`logos` de esta misma página tampoco lo usan, no era una excepción a propósito).
- `title`: de una oración larga (76 caracteres) a "La confianza se construye de cerca" (34 caracteres) — más en línea con el largo de otros títulos de bloque en este archivo ("Casos de éxito", "Empresas con las que trabajamos").
- `subtitle`: de ~265 a 185 caracteres — "Acompañamos a personas y organizaciones que buscan crecer, con la certeza de contar con un aliado genuino: escuchamos primero y actuamos con transparencia, cercanía y compromiso reales." Con margen real bajo el límite de 255, no "que entre por poco".
- Se mantiene el mismo espíritu (PNL, voseo, refuerzo de los valores Transparencia/Cercanía/Compromiso que listan las tarjetas de abajo), solo más compacto.
- **Archivos**: `database/seeders/Cliente0ContentSeeder.php`. **Verificación**: balance de sintaxis OK. Pendiente re-correr `db:seed` y confirmar que ya no tira el `QueryException`.

**CORRECCIÓN (3ra vuelta, mismo día) — ajuste de estilo, no de largo técnico**: el subtítulo de la 2da vuelta (185 caracteres, ya entraba en la columna) tenía forma de descripción — dos puntos + enumeración ("...genuino: escuchamos primero y actuamos con..."). El Tech Lead marcó el problema real: "recuerda que los subtitulos tampoco deberian ser una descripcion, son subtitulos, largo pero no muy largos". Se reemplaza por una sola oración corta (58 caracteres): "Cercanía real, transparencia total y compromiso genuino." — alineado al largo típico de los demás subtítulos de este seeder (55-60 caracteres). `title` sin cambios en esta vuelta. **Archivos**: `database/seeders/Cliente0ContentSeeder.php`. **Verificación**: balance OK. Pendiente `db:seed` + confirmación visual.

**CORRECCIÓN (4ta vuelta, mismo día, con captura en vivo tras el `db:seed`)**: "un poco mas de texto en el subtitulo creo que exageraste" — la 3ra vuelta (58 caracteres) se pasó de corta en la dirección contraria a la 2da. Se sube a 86 caracteres, punto medio entre ambas correcciones: "Con cercanía y transparencia genuinas, acompañamos tu crecimiento con compromiso real." — una sola oración fluida, sin dos puntos ni enumeración, más larga que el resto de los subtítulos del seeder pero sin sonar a descripción. `title` sin cambios. **Archivos**: `database/seeders/Cliente0ContentSeeder.php`. **Verificación**: balance OK. Pendiente `db:seed` + confirmación visual.

## 2026-09-07 — Corrección: `features.content_format` (por item) → `properties.list_style` (por bloque) + renombre — amplía ADR-047

- **Pedido en vivo del Tech Lead**, revisando el formulario resultante del rediseño anterior (misma fecha, ver entrada de abajo): "El nombre no es el adecuado 'Contenido adicional' si no deberia ser Estilo, Formato, presentacion, algo asi, y tambein es mejor pasar a properties como parte del estilo si desea las caracteristicas tipo grid, list o ninguna" + "otra cosa los campos de las properties sus opciones o selectores como estilo de tarjeta, me imagino que estan en un ENUM por que crearlas hardcode generaran problemas despues, cuidar eso."
- **Qué se hizo**:
  1. `app/Enums/FeatureContentFormatEnum.php` eliminado; reemplazado por `app/Enums/FeatureListStyleEnum.php` (mismo backing string `none|list|grid`, nombre y labels más claros). PSR-4 obliga archivo nuevo + borrado del viejo (no es un rename in-place de clase).
  2. `PropertiesSchema.php`: nuevo componente reusable `'list_style'` (Select, `FeatureListStyleEnum::class`, default `None`), con su `use` import correspondiente.
  3. `PageResource.php` (bloque `features`): se quita el `Select::make('content_format')` por item y su `use App\Enums\FeatureContentFormatEnum` (clase ya no existe — sin este cambio, fatal error `Class "App\Enums\FeatureContentFormatEnum" not found` al abrir/guardar la página, confirmado en `storage/logs/laravel.log`). El `TagsInput::make('items')` queda siempre visible (antes su `->visible()` dependía del selector eliminado). `list_style` se agrega a la `Section` "Personalización de estilos" (`PropertiesSchema::make([...])`, junto a `feature_style`/`card_rounded`).
  4. `Cliente0ContentSeeder.php`: se quita `content_format` de los 3 items (Misión/Visión/Valores); se agrega `'list_style' => 'list'` a `properties` del bloque (Valores es el único item con `items[]` cargado, así que es el único que se ve afectado).
  5. `Features.astro` (cica360): `FeatureItem.content_format` eliminado de la interfaz; `FeaturesProperties.list_style` agregado; la lista/grid de puntos ahora se decide con `properties.list_style` (nivel bloque) en vez de `item.content_format` (nivel item) — cada item sigue decidiendo implícitamente si tiene algo que mostrar según si trae `items[]`.
- **Auditoría de enums (2do pedido)**: confirmado — `feature_style` (`FeatureCardStyleEnum`) y `list_style` (`FeatureListStyleEnum`), los 2 selectores nuevos de este bloque, usan enums backed con `HasLabel`, no arrays hardcodeados. **Nota honesta pendiente de decisión del Tech Lead**: varios campos PREEXISTENTES de `PropertiesSchema.php` (no tocados en este bloque ni en el anterior) siguen con `->options([...])` hardcodeado en vez de enum: `background_type`, `content_width`, `padding_y`, `text_align`, `link_radius`, `link_size`, `gradient_direction`, `media_radius`. Es deuda preexistente, no introducida por `features` — queda pendiente confirmar si se quiere ese refactor más amplio como tarea aparte.
- **Archivos**: `app/Enums/FeatureListStyleEnum.php` (nuevo, reemplaza `FeatureContentFormatEnum.php`, eliminado), `app/Filament/Schemas/PropertiesSchema.php`, `app/Filament/Resources/PageResource.php`, `database/seeders/Cliente0ContentSeeder.php`. Del lado cica360: `src/components/blocks/Features.astro`.
- **Verificación**: balance de sintaxis (tokenizer Python) OK en los 4 archivos PHP + `Features.astro`. Sin runtime PHP/Node en este sandbox — pendiente confirmación del Tech Lead corriendo Studio.
- **Siguiente**: confirmar con el Tech Lead si quiere el refactor de los 8 campos hardcodeados preexistentes a enum (fuera de alcance de este cambio puntual).
- **Corrección de copy (mismo día, feedback posterior del Tech Lead)**: el `helperText` del `TagsInput::make('items')` decía "El formato (lista/grid/ninguno) se elige abajo en 'Personalización de estilos'" — referencia a la ubicación interna del formulario, no útil para quien está cargando contenido. Regla recordada explícitamente: los `helperText` deben pensarse en UX para el usuario final (qué hace el campo, qué efecto tiene en el resultado), no como instrucciones de navegación de la interfaz. Reemplazado por: "Puntos breves para esta característica (uno por Enter o coma). Ej: Profesionalismo, Empatía, Transparencia. Si lo dejás vacío, la tarjeta muestra solo el campo Descripción."
- **Confirmación del diseño (Tech Lead, mismo día)**: "el campo 'Ítems de la lista' me gusta como está por si alguien quiere unas una lista de subcaracteristicas, y que el selector de Estilo de lista en properties quede ahi" — confirma explícitamente el diseño final de ADR-048 sin cambios: `content.items[].items` (por item, `TagsInput`, uso libre para sub-características) queda como está, y `properties.list_style` (por bloque, controla el formato de presentación) queda en "Personalización de estilos". Sin cambios de código, solo se documenta la confirmación.
- **Fix real de contenido (Tech Lead, mismo día)**: "en este diseño no se usa ningun heading, no te diste cuenta?" — el `title: 'Misión, visión y valores'` que se le había agregado al bloque `features` de "Sobre CICA" NO estaba en la captura de referencia (Figma "Desktop - ABOUT-US"): el diseño va directo del párrafo introductorio a las 3 tarjetas, sin heading de sección entre medio. Se agregó sin base real; se saca. Sirve de paso como caso de prueba real del fix de espaciado del mismo día en `Features.astro` (cica360): sin ningún campo de heading, el cluster no se renderiza y el grid arranca sin gap fantasma. Archivo: `database/seeders/Cliente0ContentSeeder.php`.
- **Fix real (Tech Lead, mismo día)**: "te diste cuenta que falta el tipo de fondo color?" — el seeder tenía `background_type: 'solid'` sin `background_color` cargado. `resolveBackgroundStyle()` (cica360) devuelve `''` sin un color base, así que la sección quedaba transparente pese a decir "Sólido". Se agrega `background_color: '#F6F6F6'` (mismo tono off-white que usan los `rich_text` "¿Qué hacemos?" del Home vía `text_background_color`, por consistencia) al bloque `features` de `upsertSobreCicaPage()`. Valor razonable, pendiente confirmación visual del Tech Lead. Archivo: `database/seeders/Cliente0ContentSeeder.php`.

## 2026-09-07 — "Sobre CICA": agregados los bloques `testimonials` ("Casos de éxito") y `logos` ("Empresas con las que trabajamos"), clonados de la home

- **Pedido en vivo del Tech Lead**, con captura de referencia (Figma "Desktop - ABOUT-US") y captura del panel "Editar Página" mostrando solo 4 secciones (Heading, Texto Enriquecido, Características/Grid, Footer): "hay que aplicr los bloques necesarios... falta los bloques Testimonios y logos / Socios con todo lo que tienen en home, practicamente clonarlos antes del footer".
- **Qué se hizo**: se agregan 2 bloques nuevos a `upsertSobreCicaPage()`, entre `features` y el `footer` (auto-agregado por `appendFooterBlock()`):
  - `testimonials` — clon exacto de la config de `upsertHomePage()` (`content: {limit: 5, order: desc}`, `properties` con los colores `cicagreen-500`/`400` del sistema de diseño, link "Más casos de éxito" → página `casos-de-exito`). Mismo patrón visual que la captura (3 tarjetas + botón "MÁS CASOS DE ÉXITO").
  - `logos` — mismos 10 items + mismo filtro grayscale/opacidad que la home (mismo carousel, misma data). `title`/`subtitle` SÍ cambian respecto a la home: se usa el texto tal cual aparece en la captura de "Sobre CICA" ("Empresas con las que trabajamos" / "Soluciones integrales diseñadas para impulsar tu negocio"), distinto al subtítulo que usa la home para el mismo bloque.
- **Nota importante — el CTA NO se agregó por separado**: la captura de referencia muestra un bloque "¿Listo para transformar tu negocio?" justo antes del footer, pero ESE bloque ya viene incluido automáticamente en toda página vía el bloque `footer` compartido (`appendFooterBlock()` → `upsertFooterPage()`, que tiene ese CTA sembrado desde 2026-09-01) — agregarlo de nuevo en `upsertSobreCicaPage()` lo hubiera duplicado. Por eso la lista de "bloques faltantes" reportada acá son solo 2 (Testimonials, Logos), no 3.
- **Cambio de orden en `run()`**: el bloque `testimonials` nuevo necesita el id de la página `casos-de-exito` para el link "Más casos de éxito" (mismo patrón `$this->link('...', 'page', $pages['casos-de-exito']->id, ...)` que usa `upsertHomePage()`). Como antes `sobre-cica` se creaba ANTES que `casos-de-exito` en el array `$pages` de `run()`, se reordenó: `servicios`/`casos-de-exito` (sin dependencias entre sí) pasan a crearse primero, `sobre-cica` ahora recibe `$pages` como 2do parámetro (mismo patrón que ya usan `upsertFooterPage()`/`upsertHomePage()`).
- **Archivos**: `database/seeders/Cliente0ContentSeeder.php` (`run()` reordenado, `upsertSobreCicaPage()` con nueva firma `(Tenant $tenant, array $pages)` + 2 bloques nuevos).
- **Verificación**: balance de sintaxis (tokenizer Python) — OK. Sin runtime PHP en este sandbox — pendiente `php artisan db:seed` + confirmación visual del Tech Lead (orden de secciones: Heading → Texto Enriquecido → Características → Casos de éxito → Empresas → footer con CTA).
- **Pendiente sin resolver**: el Tech Lead también mencionó "por ejemplo Texto enriquecido no hay" en el mismo pedido — pero el bloque `rich_text` YA existe como 2do bloque de esta página (ver captura del panel "Editar Página": "Texto Enriquecido 2"). No se identificó ningún gap real asociado a ese comentario puntual; se documenta acá por si el Tech Lead se refería a otra página o a un criterio distinto que no quedó claro.

## 2026-09-07 — Rediseño completo del bloque `features` (Filament + seeder + frontend) — ver ADR-047

- **Pedido en vivo del Tech Lead** (2 capturas: mockup real "Misión/Visión/Valores" con foto real, y árbol de archivos con las 3 imágenes ya subidas a `storage/app/public/media/`): sembrar el contenido real, y aplicar UX completa al formulario del bloque — imagen a la izquierda/ícono+título+descripción a la derecha por item (ambos opcionales), selector nuevo por item (ninguno/lista/grid), properties de fondo unificadas (solid/gradient/image, reusando los componentes ya existentes de `PropertiesSchema`), TODAS las properties opcionales, y 2 properties nuevas específicas del bloque: `feature_style` (simple/formas/tarjeta sin sombra/**tarjeta con sombra**, esta última la del diseño) y, en un mensaje inmediato posterior, `card_rounded` ("otra property especifica para este tipo de bloque sería el campo de rounded activo"). También preguntó explícitamente si se estaba usando el MCP de Laravel Boost.
- **Sobre Laravel Boost**: el paquete está instalado en el proyecto (`vendor/laravel/boost`), pero su servidor MCP no está conectado a esta sesión de Cowork — no aparece en las herramientas disponibles. Se trabajó vía edición directa de archivos + verificación de sintaxis con un tokenizer Python (sin runtime PHP en este sandbox), siguiendo el espíritu de las guías de Boost igual (convenciones del repo, Enums PHP, Filament 5 idiomático) sin poder usar sus herramientas (`search-docs`, `database-schema`, artisan interactivo, etc.).
- **Ver detalle completo, incluyendo un bug latente encontrado (no de este cambio) en `cta`/`colophon`**: ADR-047 en `DECISIONS.md`.
- **Archivos**: `app/Enums/FeatureCardStyleEnum.php` (nuevo), `app/Enums/FeatureContentFormatEnum.php` (nuevo), `app/Filament/Schemas/PropertiesSchema.php`, `app/Filament/Resources/PageResource.php`, `app/Http/Concerns/ResolvesPublicLinks.php`, `database/seeders/Cliente0MediaSeeder.php`, `database/seeders/Cliente0ContentSeeder.php`. Del lado cica360: `src/components/blocks/Features.astro` (reescrito completo — ver PROGRESS.md de cica360, mismo día, incluye el fix real de `item.subtitle`→`item.description`).
- **Verificación**: balance de sintaxis (tokenizer Python string/comment-aware) — OK en los 6 archivos PHP tocados. Sin runtime PHP/Node en este sandbox — pendiente `php artisan db:seed` + `npm run dev`/`astro build` y confirmación visual completa del Tech Lead.
- **Siguiente**: auditar/corregir el bug latente de `cta`/`colophon` (`background_image_id` sin prefijo `content.`, nunca se guarda) en un cambio aparte, no mezclado con este. Si más adelante se instala el set de íconos Heroicons (`@iconify-json/heroicons`) en cica360, mapear `icon` a un ícono real en `Features.astro` (hoy usa un avatar con inicial como fallback).

## 2026-09-06 — "Sobre CICA": 2do bloque reemplazado por `rich_text` simple sin heading (con captura de referencia)

- **Pedido en vivo del Tech Lead** (captura: párrafo centrado gris, sin título, con el cierre en negrita "asesorar con compromiso, transparencia y visión estratégica."): reemplazar el 2do bloque de la página `sobre-cica` por un `rich_text` simple con el texto exacto que pasó ("En CICA creemos que cada persona, familia, emprendimiento o empresa tiene su propio camino. Por eso ofrecemos un enfoque integral, cercano y profesional..."). Explícito: "es sencillo no tiene heading, solo description", "considera el mismo padding de los demas bloques que hicimos en el home para que esté alineado", "no necesita decorator", y "el texto podria mejorarlo a lo uruguayo, pero sin cambiar el significado o el enfoque".
- **Fix**: se reemplaza el bloque `rich_text` anterior ("Quiénes somos", con `title`, `content_width: narrow`, `padding_y: md` y decorador inferior tipo onda) por uno nuevo:
  - Sin `title`/`pretitle`/`subtitle` — se omiten esas 3 keys directamente (no hay flag "ocultar heading"; `RichText.astro` ya renderiza cada uno condicionalmente solo si `block.title`/`block.pretitle`/`block.subtitle` tienen valor, así que no declararlos alcanza).
  - Sin `decorator_top`/`decorator_bottom` — se dejan de declarar esas properties (mismo criterio que el bloque introductorio del Home, que tampoco las trae).
  - `content_width: 'boxed'` + `padding_y: 'lg'` (antes `narrow`/`md`) — mismos 2 valores que usa el `rich_text` introductorio del Home (el que sigue al Hero, `upsertHomePage()`), para que el ancho de columna y el ritmo vertical queden alineados con "los demás bloques que hicimos en el home", en vez de un tercer valor sin relación.
  - Copy: mismo significado/enfoque del texto pasado por el Tech Lead, con un giro leve a voseo rioplatense en 2ª persona ("te ofrecemos", "tus necesidades", "te acompaña", "asesorarte") — mismo criterio ya usado en los heading de páginas internas (ver "Contactame"), sin agregar ni quitar ningún concepto y en el mismo orden. El cierre queda en `<strong>` para reproducir el énfasis en negrita de la captura.
- **Archivos**: `database/seeders/Cliente0ContentSeeder.php` (`upsertSobreCicaPage()`).
- **Verificación**: balance de sintaxis (tokenizer Python string/comment-aware) — OK sobre el archivo completo. Sin runtime PHP en este sandbox — pendiente `db:seed` + confirmación visual del Tech Lead.
- **Siguiente**: ninguno.

## 2026-09-06 — Título de Servicios y Casos de Éxito revertido al corto del diseño (subtítulos quedan con voseo)

- **Contexto**: la entrada de abajo (mismo día) reescribió título Y subtítulo del bloque `heading` de las 3 páginas nuevas con voseo rioplatense. Tras ver capturas a 500px y a 375px (mobile real), el Tech Lead reportó: "no son muy largos los titulos esa resolucion es de 500 en navegador pero en mobile de 375px (las 2 ultimas capturas) se formará 2 lineas" — y resolvió: "pero los titulos son muy largos, acortar o dejarlo como estaba en el diseño, solo el contactame que suena mas argento" + aclaración inmediata: "los subtitulos dejalos asi".
- **Fix**: se revierte SOLO el `title` del bloque `heading` en 2 de las 3 páginas (Servicios y Casos de Éxito) a la versión corta original del diseño; "Contactame" queda como la única excepción con tono argento, tal como se pidió explícitamente. Los `subtitle` de las 3 páginas NO se tocan — quedan con el voseo sembrado en la entrada de abajo.
  - Servicios: "Descubrí nuestros servicios" → **"Servicios"** (subtítulo sin cambios: "Te acompañamos en cada etapa de tu proyecto").
  - Casos de Éxito: "Conocé nuestros casos de éxito" → **"Casos de éxito"** (subtítulo sin cambios: "Historias reales de quienes ya confiaron en nosotros").
  - Contacto: sin cambios — "Contactame" / "Contanos en qué podemos ayudarte" se mantienen tal cual (es la excepción aprobada).
- **Nota**: el ajuste de leading/tamaño del título y subtítulo en mobile (375px) que también pidió el Tech Lead en el mismo mensaje ("el subtitulo si puede formar 2 lineas pero el leading y tamaño ajustar en mobile") es un cambio de CSS en `Heading.astro` — ver `docs/context/PROGRESS.md` de cica360, mismo día, para el detalle (14ta vuelta: `leading-tight` en título, `text-base leading-snug sm:text-lg sm:leading-normal` en subtítulo).
- **Archivos**: `database/seeders/Cliente0ContentSeeder.php` (`upsertServiciosPage()`, `upsertCasosDeExitoPage()`).
- **Verificación**: balance de sintaxis (tokenizer Python string/comment-aware) — OK sobre el archivo completo. Sin runtime PHP en este sandbox — pendiente `db:seed` + confirmación visual/de tono del Tech Lead.
- **Siguiente**: ninguno.

## 2026-09-06 — Título/subtítulo de los 3 bloques `heading` nuevos con voseo rioplatense

- **Pedido en vivo del Tech Lead**: "los titulos o subtitulos del hearings que sean mas uruguayos que es parecido al lexico argento" — con ejemplo puntual: "por ejemplo en contacto que diga 'Contactame'".
- **Fix**: se reescribe el título/subtítulo del BLOQUE `heading` (no el título/subtítulo de la `Page` en `upsertPage()`, que queda neutro para SEO/listados) en las 3 páginas sembradas en la entrada de abajo, con voseo e imperativos rioplatenses:
  - Contacto: "Contacto" → **"Contactame"** / "Conversemos sobre tu próximo paso" → **"Contanos en qué podemos ayudarte"**.
  - Servicios: "Servicios" → **"Descubrí nuestros servicios"** / "Soluciones integrales para cada etapa de tu proyecto" → **"Te acompañamos en cada etapa de tu proyecto"**.
  - Casos de Éxito: "Casos de éxito" → **"Conocé nuestros casos de éxito"** / "Historias reales de clientes que confiaron en nosotros" → **"Historias reales de quienes ya confiaron en nosotros"**.
- **No se tocó**: el eslogan compartido "Conectamos conocimientos, potenciamos decisiones." (usado en 4 lugares del seeder — Hero, footer, "Sobre CICA", una card — ver grep) por ser tagline de marca transversal, no copy propio de un bloque puntual; tampoco los títulos/subtítulos de `upsertPage()` (esos alimentan SEO/breadcrumbs/listados, y divergir el banner del bloque de esos valores es intencional, no un descuido).
- **Archivos**: `database/seeders/Cliente0ContentSeeder.php` (`upsertContactoPage()`, `upsertServiciosPage()`, `upsertCasosDeExitoPage()`).
- **Verificación**: balance de sintaxis (tokenizer Python string/comment-aware) — OK sobre el archivo completo. Sin runtime PHP en este sandbox — pendiente `db:seed` + confirmación visual/de tono del Tech Lead.
- **Siguiente**: ninguno.

## 2026-09-06 — Bloque `heading` replicado a Contacto, Servicios y Casos de Éxito (mismo patrón que "Sobre CICA")

- **Pedido en vivo del Tech Lead**: "lo mismo generar en seeder el contenido inicial, con el primer bloque heading para las paginas internas de servicios, casos de exito, contatos".
- **Qué se hizo**: se agrega un bloque `heading` como PRIMER bloque de `upsertContactoPage()`, `upsertServiciosPage()` y `upsertCasosDeExitoPage()` — mismo patrón/properties que el ya seteado en `upsertSobreCicaPage()` (`background_type: image`, `overlay_color: #2D2C4D`, `overlay_opacity: 90`, `decorator_bottom: wave` blanco, `title_alignment: center`).
- **Decisiones sin diseño propio para estas 3 páginas** (a diferencia de `sobre-cica`, que sí tenía `ABOUT.pdf` de referencia): se reutilizan las MISMAS 3 imágenes de encabezado ya sembradas (`header_desktop/tablet/mobile`, `Cliente0MediaSeeder`) como banner genérico compartido entre páginas internas — no se generaron fotos nuevas por página. Título/subtítulo del bloque son idénticos a los que cada página ya recibía en su propio `upsertPage()` (no se inventó copy nuevo): Contacto → "Contacto"/"Conversemos sobre tu próximo paso"; Servicios → "Servicios"/"Soluciones integrales para cada etapa de tu proyecto"; Casos de Éxito → "Casos de éxito"/"Historias reales de clientes que confiaron en nosotros".
- **Archivos**: `database/seeders/Cliente0ContentSeeder.php` (`upsertContactoPage()`, `upsertServiciosPage()`, `upsertCasosDeExitoPage()`).
- **Verificación**: balance de sintaxis (tokenizer Python string/comment-aware) — OK sobre el archivo completo. Sin runtime PHP en este sandbox — pendiente `php artisan db:seed` + confirmación visual del Tech Lead en las 3 páginas.
- **Siguiente**: si más adelante aparecen imágenes de encabezado propias para alguna de estas páginas (no la genérica compartida), actualizar `content.image_*_id` de ese bloque puntual.

## 2026-09-06 — Bloque `heading` de "Sobre CICA": `overlay_opacity` default sembrado en 90 (no 100)

- **Pedido en vivo del Tech Lead** (captura del panel de Filament, campos "Color del overlay / filtro" y "Opacidad del overlay" con el slider en `90`): "el gradiente configurado en el seer que no sea al 100% que se regule al 90% inicial default".
- **Fix**: `overlay_opacity` en `upsertSobreCicaPage()` pasa de `100` a `90` — deja un resquicio mínimo de transparencia incluso en el tramo "sólido" (0-35%) del degradado, en vez del 100% de intensidad literal del spec de Figma.
- **Archivos**: `database/seeders/Cliente0ContentSeeder.php` (properties del bloque `heading`).
- **Siguiente**: correr `php artisan db:seed` de nuevo para que `overlay_opacity: 90` llegue a la base.

## 2026-09-06 — Bloque `heading` de "Sobre CICA": `overlay_opacity` revertido de 40 a 100 (el fix real era de altura, no de intensidad)

- **Contexto**: la entrada de abajo (mismo día) bajó `overlay_opacity` de 100 a 40 pensando que la intensidad era el problema. El Tech Lead aclaró después el propósito real del degradado: "esto es para que en la parte de 100% quedará detras del navbar" — el tramo sólido (0%-35%) no está pensado para verse, debe quedar oculto detrás del `<header>` (`position: fixed`).
- **Causa real**: el problema nunca fue la intensidad del color — era que el overlay en cica360 (`Heading.astro`) usaba `inset-0` (degradado a lo largo de TODA la sección, varios cientos de px), mucho más alto que el navbar real (~88px), así que gran parte del tramo sólido quedaba visible por debajo del navbar en vez de oculto detrás suyo.
- **Fix**: `overlay_opacity` vuelve a `100` (valor literal del spec de Figma, sin atenuar) — el fix real se hizo del lado de cica360, dándole al overlay una altura fija (`h-64`, 256px) calculada contra el alto real del navbar (`pt-7` + `data-glass-bar` `h-[60px]` ≈ 88px en reposo), no proporcional a la sección completa. Ver `docs/context/PROGRESS.md` de cica360, mismo día, para el detalle completo del cálculo.
- **Archivos**: `database/seeders/Cliente0ContentSeeder.php` (properties del bloque `heading` en `upsertSobreCicaPage()`).
- **Siguiente**: correr `php artisan db:seed` de nuevo para que `overlay_opacity: 100` llegue a la base, y confirmar visualmente que el tramo sólido ya no asoma por debajo del navbar.

## 2026-09-06 — Bloque `heading` de "Sobre CICA": `overlay_opacity` bajado de 100 a 40 (se veía "exagerado" en vivo)

- **Pedido en vivo del Tech Lead** (2 capturas del resultado en vivo vs. la referencia de Figma, ya con el overlay renderizando por primera vez tras el `db:seed`): "creo que hemos exagerado por que se ve asi, en la segunda captura esta la espectativa".
- **Causa real**: con `overlay_opacity: 100`, la zona "sólida" del degradado (0-35% de la sección, ver `Heading.astro`) tapaba la imagen de fondo casi por completo — resultado: un lavado parejo/oscuro en toda la franja superior, en vez del velo sutil de la referencia (donde la foto se ve vívida, con solo un oscurecimiento leve detrás del título).
- **Fix**: `overlay_opacity` baja de `100` a `40` en `upsertSobreCicaPage()` — deja pasar la imagen de fondo incluso en la zona "sólida" del degradado. El color (`#2D2C4D`) y los stops porcentuales (0%/35%/100%) del degradado en sí no cambian, solo la intensidad general.
- **Archivos**: `database/seeders/Cliente0ContentSeeder.php` (properties del bloque `heading` en `upsertSobreCicaPage()`).
- **Siguiente**: correr `php artisan db:seed` de nuevo para que el nuevo valor llegue a la base, y confirmar visualmente si `40` ya calza con la referencia o necesita otro ajuste — es un valor estimado a partir de la comparación visual, no medido pixel a pixel contra el archivo de Figma.

## 2026-09-06 — Bloque `heading` de "Sobre CICA": sembrado `overlay_color`/`overlay_opacity` (faltaban por completo)

- **Contexto**: el Tech Lead compartió el spec exacto de Figma para una capa de degradado intermedia entre la imagen de fondo y el texto del banner "Sobre CICA" (`Heading.astro`, cica360). El componente frontend ya tenía el código del degradado implementado (`overlay_color`/`overlay_opacity` → `linear-gradient` + `color-mix()`, ver `docs/context/HOME_INTEGRATION.md` de cica360), pero el Tech Lead reportó que "no aparece esa capa".
- **Causa real**: el bloque `heading` sembrado en `upsertSobreCicaPage()` (`Cliente0ContentSeeder.php`) nunca tuvo `overlay_color`/`overlay_opacity` en sus `properties` — solo `background_type`, `decorator_bottom`, `decorator_bottom_color` y `title_alignment`. En el frontend, `overlayOpacity` cae a 0 por default (`properties.overlay_opacity ?? 0`) y la condición `overlayOpacity > 0 && properties.overlay_color` fallaba en silencio: el código estaba bien, pero sin datos sembrados nunca se ejecutaba. El campo SÍ existe en el schema de Filament (`ColorPicker`/`Slider` en `PageResource.php`, bloque `heading`) — el gap era solo de dato sembrado, no de schema.
- **Fix**: se agregan `overlay_color: '#2D2C4D'` y `overlay_opacity: 100` a las `properties` del bloque `heading` en `upsertSobreCicaPage()` — mismo color sólido que especifica el spec de Figma (0% a 35% de la franja, degradando a transparente hacia el 100%; el degradado en sí ya está resuelto en el componente frontend, `overlay_opacity: 100` solo multiplica la intensidad completa del degradado, no re-atenúa el fade).
- **Archivos**: `database/seeders/Cliente0ContentSeeder.php` (properties del bloque `heading` en `upsertSobreCicaPage()`).
- **Cruce con cica360**: ver `docs/context/PROGRESS.md` de cica360, mismo día — 2 vueltas adicionales del lado frontend ajustando cómo se renderiza ese degradado (de banda fija de 176px a `inset-0` proporcional al alto real de la sección).
- **Siguiente**: correr `php artisan db:seed` (o el seeder específico) para que el dato llegue a la base — sin PHP en este sandbox, pendiente que lo ejecute el Tech Lead.

## 2026-09-05 — Heading de "Sobre CICA" sembrado (contenido + imágenes + decorador) + fix real: mismatch `'waves'`/`'wave'` en el bloque `heading`

- **Pedido en vivo del Tech Lead** (capturas: árbol de archivos con `cica360_media_header-desktop/tablet/mobile.webp` ya en `storage/app/public/media/`, y el modal "Editar Página" con un bloque "Heading (Sección de Títulos)" ya agregado a mano en Studio con título/subtítulo cargados): sembrar en el SEEDER el contenido inicial del heading de la página `sobre-cica` (título, subtítulo, las 3 imágenes) con `background_type: imagen` y decorador inferior tipo onda en blanco. Referencia visual: `cica360/docs/UX-UI-design/ABOUT.pdf`.
- **Media**: `Cliente0MediaSeeder.php` — 3 entradas nuevas (`header_desktop`/`header_tablet`/`header_mobile`) apuntando a los 3 archivos ya commiteados, mismo patrón que el resto del catálogo (`firstOrCreate` por `tenant_id`+`path`).
- **Fix real descubierto en el camino**: el bloque `heading` en `PageResource.php` tenía sus PROPIOS `Select` de `decorator_top`/`decorator_bottom` con opciones hardcodeadas a mano (`'none'/'curve'/'waves'/'triangle'/'diagonal'`) en vez de reusar `PropertiesSchema::makeComponents(['decorator_top', ...])` como ya hacía `rich_text`. El valor `'waves'` (plural) NUNCA coincidía con `DecoratorShapeEnum::Wave->value` (`'wave'`, singular) que consume el frontend (`DecoratorShape` en `cica360/src/lib/types.ts`) — elegir "Ondas" en Studio guardaba un valor que el sitio público nunca iba a reconocer, silenciosamente (sin error, el decorador simplemente no aparecería). Se reemplazan los 4 componentes ad-hoc por los canónicos compartidos — mismas opciones, mismo enum, ya sin el mismatch.
- **Contenido**: `upsertSobreCicaPage()` gana un nuevo primer bloque `BlockTypeEnum::Heading` — `title: 'Sobre CICA'`, `subtitle: 'Conectamos conocimientos, potenciamos decisiones.'` (sin `pretitle`, el título grande del PDF ES el título del bloque), `content.image_desktop_id/image_tablet_id/image_mobile_id` resueltos vía `Cliente0MediaSeeder::mediaId()`, `properties.background_type: 'image'`, `properties.decorator_bottom: 'wave'` (ya con el valor correcto) + `decorator_bottom_color: '#ffffff'`, `title_alignment: 'center'`.
- **Archivos**: `database/seeders/Cliente0MediaSeeder.php`, `database/seeders/Cliente0ContentSeeder.php`, `app/Filament/Resources/PageResource.php`.
- **Verificación**: balance de sintaxis con un tokenizer Python que ahora respeta strings/comentarios PHP (el checker naive anterior daba falsos positivos con clases Tailwind arbitrarias tipo `w-[calc(...)]` dentro de un bloque `[...]` — corregido para esta y futuras verificaciones) — OK en los 3 archivos. Sin runtime PHP en este sandbox — pendiente `php artisan db:seed` (o `migrate:fresh --seed`) y confirmación visual del Tech Lead.
- **Siguiente**: ver entrada de cica360 (mismo día) — el stub de `Heading.astro` no consumía ninguno de estos campos, así que también se reconstruyó para que esto se vea reflejado en el sitio.

## 2026-09-05 — Fix real: picker de "Añadir bloque" (`Builder`) perdía las primeras opciones cuando la lista flipeaba hacia arriba

- **Pedido en vivo del Tech Lead** (3 capturas del modal "Editar Página", tab Contenidos): con ~9-11 tipos de bloque disponibles, al abrir "Añadir bloque" con el botón cerca del borde inferior del modal, el dropdown se abre hacia arriba y las primeras opciones de la lista (arriba del todo, ej. "Imagen única", "Llamado a la Acción") quedan invisibles/inaccesibles, sin scroll para llegar a ellas. Se pidió elegir entre 2 soluciones: (1) duplicar el botón "Añadir bloque" arriba y abajo de la lista de secciones, o (2) limitar el alto del picker (~400px) con scroll interno.
- **Investigación** (grounded en el código real, no solo por lectura del comportamiento): el `Builder` (`Filament\Forms\Components\Builder`, usado en `PageResource.php` sin ninguna customización propia todavía) usa el picker 100% stock de Filament 5. `vendor/filament/forms/resources/views/components/builder/block-picker.blade.php` envuelve `<x-filament::dropdown>` (paquete `filament-support`), que YA soporta flip/shift automático (`x-float`, floating-ui) y YA tiene la plumbing para un panel auto-limitado (`fi-scrollable` + `style="max-height: ..."`, condicionados a que se le pase la prop `maxHeight` o `size`) — pero `block-picker.blade.php` nunca le pasa ninguna de las dos. De ahí el bug: el panel flipea correctamente, pero no tiene tope de alto, así que si es más alto que el espacio disponible arriba del trigger, el exceso queda clippeado por el modal sin ningún scroll para compensar. `Builder.php` no expone ningún método fluido para esto (`blockPickerColumns()`/`blockPickerWidth()` solo tocan columnas/ancho).
- **Decisión — opción 2, no la 1**: duplicar el trigger no tiene soporte nativo, requeriría forkear más superficie de Blade (todo el layout del picker, no solo el dropdown) y no resuelve la causa raíz (seguiría sin alto máximo). Fijar `max-height` sí es soportado de punta a punta por el componente compartido de Filament — solo faltaba conectarlo.
- **Fix**: nuevo Blade override en `resources/views/vendor/filament-forms/components/builder/block-picker.blade.php` (namespace de vistas `filament-forms`, confirmado leyendo el `FormsServiceProvider`) — copia exacta del vendor con un único cambio: `:max-height="'400px'"` agregado al `<x-filament::dropdown>`. Esto activa `fi-scrollable` (clase CORE de Filament ya compilada en su CSS vendor — NO una clase Tailwind arbitraria de este proyecto, a diferencia del fix de `MenuTreeBuilder`, así que **no hace falta tocar el `@source` del theme custom del panel** esta vez) + el `max-height` inline. El panel ahora se autolimita a 400px con scroll propio, sin importar hacia qué lado haya flipeado.
- **Archivos**: `resources/views/vendor/filament-forms/components/builder/block-picker.blade.php` (nuevo).
- **Verificación**: balance de sintaxis (tokenizer Python, brackets/parens/llaves) — OK. Sin runtime PHP en este sandbox (no hay `php artisan tinker`/`serve`) — pendiente que el Tech Lead confirme visualmente que el picker abre con scroll interno y ninguna opción queda inaccesible, sin necesitar recompilar assets (el fix es puro Blade + una clase core de Filament, no requiere `npm run build`).
- **Siguiente**: si Filament actualiza `block-picker.blade.php` en una futura versión del paquete, re-diffear este override contra el nuevo vendor y reaplicar solo el `:max-height`.

## 2026-09-02 — Mejoras de UX/UI en el módulo de Contenidos (Segmentación por tipo, modal headings, sticky footer z-index)

- **Pedidos en vivo del Tech Lead**:
  1. Fusionar `colophon` en `footer` (eliminar redundancia).
  2. Desactivar opción `landing` del menú "Crear Contenido" para el MVP (comentada, no eliminada) y asignar íconos Heroicon específicos por tipo.
  3. Tabs de filtrado agrupados debajo de "Contenidos": **Páginas** (`type = page`, activo por default), **Legales** (`type = legal`), **Secciones** (`type` en `header`, `footer`).
  4. Botones y títulos dinámicos en SlideOver: *Crear Página* / *Editar Página*, *Crear Aviso Legal* / *Editar Aviso Legal*, *Crear Sección* / *Editar Cabecera (Header)*, etc.
  5. Ocultamiento completo de vista previa de URL (`url_preview`) en partials (Header/Footer) sin renderizar mensajes como *"Página tipo Cabecera (Header) (no tiene URL pública)"*. Restringir toggle `is_home` solo a `page` y `landing`.
  6. Fix del footer de modales SlideOver (`.fi-modal-footer`): `position: sticky`, `bottom: 0`, `z-index: 50 !important` con fondo sólido para que sliders y botones del builder se deslicen limpia e infaliblemente por debajo sin solaparse con las acciones.
- **Implementación**:
  - `PageTypeEnum.php`: Eliminado `Colophon`. 5 etiquetas en español (*Página*, *Landing Page*, *Cabecera (Header)*, *Pie de página (Footer)*, *Aviso Legal*).
  - `ManagePages.php`: Acciones typed en `getHeaderActions()` con íconos Heroicon, `modalHeading`, `fillForm(['type' => $type->value])`, `slideOver()`. `getTabs()` con `Filament\Schemas\Components\Tabs\Tab`.
  - `PageResource.php`: Headings de `EditAction` dinámicos. Restricción de `is_home`. Headings y `CreateAction`s de estado vacío (*create_from_empty_state_page*, *create_from_empty_state_legal*, *create_from_empty_state_partial*) montando `$livewire->mountAction()`.
  - `HeadingFieldset.php`: `url_preview` oculto para partials. Resolución segura de `$recordId`.
  - `public/css/filament/api-console.css`: Reglas `.fi-modal-footer` y `.fi-modal-content` para z-index y sticky footer.
  - `docs/api/v1.md` / `openapi.v1.yaml`: Actualizados los valores del enum `type`.
- **Archivos**: `app/Enums/PageTypeEnum.php`, `app/Filament/Schemas/HeadingFieldset.php`, `app/Filament/Resources/PageResource/Pages/ManagePages.php`, `app/Filament/Resources/PageResource.php`, `public/css/filament/api-console.css`, `docs/api/v1.md`, `docs/api/openapi.v1.yaml`.
- **Verificación**: Pint format run (`vendor/bin/pint`), `php artisan test --compact` ejecutado: **43/43 tests pasando (100%)**.

## 2026-09-02 — Seeder: `copyright_text` real ("2026 CICA360") en el bloque `footer_bottom` — ya no queda vacío

- **Pedido en vivo** (captura del campo "Año y nombre (Auspicio/Convenio)" con "2026 CICA360" ya cargado en Studio): agregar ese valor al seeder como contenido inicial.
- **Contexto — por qué estaba vacío**: el comentario original del seeder (escrito el mismo día, antes de ADR-043) decía explícitamente que `copyright_text` "quedaría ignorado igual por el gate de white-label" porque en ese momento CICA360 todavía era Free Forever (ADR-006). Ese comentario quedó desactualizado un rato más tarde el mismo día, cuando se creó el plan Auspicio/Convenio (ADR-043) y CICA360 fue reasignado a ese plan — `copyright_text` SÍ se usa ahí (es el fragmento que arma `content.copyright_html` vía `ResolvesPublicLinks`, ver ADR-043).
- **Fix**: `Cliente0ContentSeeder.php`, bloque `footer_bottom` de `footer-principal` — `content: []` → `content: ['copyright_text' => '2026 CICA360']`. Comentario de cabecera reescrito para reflejar el estado real (ya no dice "no se siembra").
- **Archivos**: `database/seeders/Cliente0ContentSeeder.php`.
- **Verificación**: balance de sintaxis (tokenizer Python) — OK. Sin PHP en este sandbox — pendiente `php artisan db:seed` (o re-sembrar solo `footer-principal`) para que tome efecto, y confirmación visual de que el copyright público se lea "© 2026 CICA360 - Todos los derechos son reservados" + "Powered by Stamless".
- **Siguiente**: ninguno.

## 2026-09-02 — Nuevo endpoint público: `GET /testimonials` (catálogo standalone, sin `show` por slug) — ver ADR-046

- **Pedido en vivo**, disparado por el fix anterior (Services agregado al Playground): el Tech Lead notó que no había un grupo "Testimonials" equivalente y pidió explícitamente que se agregue ("no hay testimonios").
- **Decisión de diseño**: a diferencia de Services/Posts, Testimonios NO tiene `slug` en su tabla (ver migración `2026_08_31_000003_create_testimonials_table.php`) y no hay ningún caso de uso real de "un testimonio individual" — siempre se consume como colección. Se construyó solo `index()` (sin `show()`), filtrado por `is_visible = true`, ordenado por `sort_order` (mismo campo que ya usa el admin para reordenar en Filament, `TestimonialResource::table()`) — detalle completo de las alternativas descartadas (agregar `slug`, ordenar por `created_at` como el bloque embebido) en ADR-046.
- **Implementación**: `App\Http\Resources\Api\V1\TestimonialResource` (nuevo, shape `{uuid, name, role, quote, avatar}` — mismo shape que ya usa `ResolvesPublicLinks::transformBlockContent()` para el bloque `testimonials` embebido, más `uuid`), `App\Http\Controllers\Api\V1\TestimonialController::index()` (nuevo, mismo esqueleto que `ServiceController`), ruta `GET /v1/{tenant}/testimonials` en `routes/api.php` (grupo `abilities:content:read` existente), grupo `Testimonials` nuevo en `ApiPlayground::PRESETS` (1 preset, sin `.show`), secciones nuevas en `docs/api/v1.md`/`docs/api/openapi.v1.yaml` (mismo criterio de la entrada anterior de Services).
- **Archivos**: `app/Http/Resources/Api/V1/TestimonialResource.php` (nuevo), `app/Http/Controllers/Api/V1/TestimonialController.php` (nuevo), `routes/api.php`, `app/Filament/Pages/ApiPlayground.php`, `docs/api/v1.md`, `docs/api/openapi.v1.yaml`.
- **Verificación**: balance de sintaxis (tokenizer Python) en los 4 archivos PHP, `openapi.v1.yaml` parseado con `pyyaml` — todo OK. Sin PHP en este sandbox — pendiente confirmación visual del Tech Lead: grupo "Testimonials" en el Playground, `GET /v1/cica360/testimonials` respondiendo con los testimonios visibles. cica360 no consume este endpoint todavía (sigue resolviendo testimonios embebidos por página) — queda disponible para uso futuro.
- **Siguiente**: ninguno.

## 2026-09-02 — Fix real: Services no aparecía en API Playground ni en API Documentation

- **Reportado en vivo** (captura del Playground, sidebar "Endpoints de ejemplo"): no había ningún grupo "Services" (ni tampoco "Testimonials") junto a Pages/Posts/Menus/Sliders/Media/Forms; tampoco aparecían en "API Documentation".
- **Causa raíz — 2 gaps distintos**:
  1. **Gap real (Services)**: cuando se construyó el endpoint público de Services este mismo día (ver ADR-044), se actualizó el manual de API de **cica360** (`docs/context/api/stamless-api-v1.md`, obligatorio por su propio `CLAUDE.md`) pero se pasó por alto el manual PROPIO de genesis — `docs/api/v1.md` + `docs/api/openapi.v1.yaml` — que es la fuente que además renderiza en vivo la página "API Documentation" de Console (`ApiDocumentation.php` lee `docs/api/v1.md` directo, sin duplicar contenido — confirmado leyendo la clase). Y `ApiPlayground.php::PRESETS` (el array que arma el sidebar de "Endpoints de ejemplo") tampoco se había tocado — nunca se le agregó el grupo `Services`.
  2. **No es un gap real (Testimonials)**: los testimonios NUNCA tuvieron ni tienen un endpoint público propio — por diseño (ver ADR-033), se resuelven EMBEBIDOS dentro de `content.items[]` del bloque `testimonials` de una página (`GET /pages/{slug}`), no como un recurso top-level tipo `/testimonials`. No hay nada que agregar al Playground/documentación para esto — se le explica la distinción al Tech Lead en vez de inventar un endpoint que no existe.
- **Fix**: `app/Filament/Pages/ApiPlayground.php` — 2 presets nuevos (`services.index`/`services.show`, grupo `Services`, mismo patrón que `posts.*`). `docs/api/v1.md` — nuevas secciones `### GET /services` y `### GET /services/{slug}` (insertadas entre `posts/{slug}` y `menus/{slug}`, mismo formato/ejemplos que el resto), tabla de abilities actualizada, y el `type` de `MenuItem` documentado ahora incluye `service` (`href` resuelto a `/servicios/{slug}`). `docs/api/openapi.v1.yaml` — tag `Services` nuevo, paths `/services`/`/services/{slug}`, schemas `ServiceCountry`/`ServiceSummary`/`Service` nuevos, `MenuItem.type` enum actualizado con `service`.
- **Archivos**: `app/Filament/Pages/ApiPlayground.php`, `docs/api/v1.md`, `docs/api/openapi.v1.yaml`.
- **Verificación**: balance de sintaxis (tokenizer Python) en `ApiPlayground.php` — OK; `openapi.v1.yaml` parseado con `python3 -c "import yaml; yaml.safe_load(...)"` — OK (con `pip install pyyaml --break-system-packages` en el sandbox). Como "API Documentation" renderiza `docs/api/v1.md` en vivo sin duplicar contenido, este fix cubre ambas superficies (Playground + Documentation) con un solo cambio de fondo (los `.md`/`.yaml`) más el array de presets. Sin PHP en este sandbox — pendiente confirmación visual del Tech Lead: el grupo "Services" debería aparecer ahora en el sidebar del Playground con 2 ejemplos, y la sección `GET /services`/`GET /services/{slug}` en "API Documentation".
- **Siguiente**: ninguno.

## 2026-09-02 — Fix real, 2da vuelta: los campos del formulario inline de `MenuTreeBuilder` seguían sin estilo tras el fix del theme

- **Reportado en vivo** (captura tras el fix anterior): las tarjetas/badges/drag handle/botones del árbol YA se veían correctamente estilados (confirma que el `theme.css` custom sí se compiló y tomó efecto) — pero al expandir un ítem, los campos del panel de edición inline ("Etiqueta / Título", "Tipo de enlace", "Destino", "Página de destino", "Activo") seguían viéndose como inputs/selects planos del navegador, sin el box oscuro/bordeado/redondeado que sí tienen "Nombre"/"Slug" a la izquierda (esos SÍ son un `TextInput` nativo de Filament).
- **Causa raíz (distinta a la del fix anterior, aunque relacionada)**: las clases `fi-input`/`fi-select` que se les había puesto A MANO como string suelto (`class="fi-input mt-1 block w-full rounded-lg ..."`) no son suficientes — el box/borde/fondo visible de un campo real de Filament no vive en el `<input>`/`<select>` en sí, vive en un DIV contenedor aparte (`fi-input-wrp`), generado por el componente Blade `<x-filament::input.wrapper>` (confirmado leyendo `vendor/filament/support/resources/views/components/input/wrapper.blade.php`) — el `<input>`/`<select>` interno solo aporta `fi-input`/`fi-select-input` (reset básico, sin box). Al escribir el HTML a mano en vez de usar los componentes Blade reales de Filament, faltaba justamente ese wrapper.
- **Fix**: se reemplazan los `<input>`/`<select>`/checkbox escritos a mano por los componentes Blade REALES de Filament — `<x-filament::input.wrapper>` envolviendo `<x-filament::input>` (texto/URL), `<x-filament::input.select>` (tipo/destino/referencia) y `<x-filament::input.checkbox>` (Activo) — mismo patrón exacto que usa Filament internamente (confirmado en `vendor/filament/support/resources/views/components/pagination/index.blade.php` para el selector "por página" y `vendor/filament/tables/resources/views/components/search-field.blade.php` para el buscador de tablas). Los bindings de Alpine (`x-model`/`x-model.number`/`x-on:input`/`x-on:change`) se pasan como atributos extra sobre estos componentes — Blade los reenvía tal cual al elemento HTML real vía `$attributes`, sin necesidad de tocar el JS del builder. Como estas son clases SEMÁNTICAS del core de Filament (no Tailwind arbitrario), su CSS ya viene precompilado de fábrica — no dependen del `theme.css` custom agregado en el fix anterior (ese seguía siendo necesario para las clases Tailwind SÍ arbitrarias del resto del componente: tarjetas, badges, drag handle).
- **Archivos**: `resources/views/filament/forms/components/menu-tree-builder.blade.php`.
- **Verificación**: revisión manual de balance de tags Blade (apertura/cierre de cada `<x-filament::input.wrapper>`/`<x-filament::input.select>`). Sin PHP/navegador en este sandbox — pendiente confirmación visual del Tech Lead: los campos del panel de edición inline deberían verse ahora con el mismo estilo oscuro/bordeado que "Nombre"/"Slug". **No requiere volver a compilar** (esta vez no se tocó ninguna clase Tailwind nueva, solo se reemplazó el markup por componentes ya compilados de Filament) — aunque no está de más un `npm run build` de confirmación si quedó pendiente del fix anterior.
- **Siguiente**: ninguno, salvo la confirmación visual.

## 2026-09-02 — Fix real: `MenuTreeBuilder` se veía sin ningún estilo (falta un theme custom del panel de Filament)

- **Reportado en vivo** (captura de "Editar Menú" real): en vez de las tarjetas diseñadas (drag handle, badges de tipo, botones indentar/desindentar/expandir/borrar, bordes/sombra), se veía una lista de texto plano — solo título + " Página" pegado al lado, con indentación visible en los sub-items pero sin ningún otro estilo. Como la jerarquía indentada SÍ se veía bien (`Home Página` en nivel 0, `fdgdfgdfg Página` anidado 2 niveles), quedaba claro que Alpine/JS/la hidratación del estado SÍ funcionaban — el problema era puramente de CSS.
- **Causa raíz**: Filament NO incluye clases Tailwind arbitrarias en su CSS precompilado por default — solo compila (vía Tailwind JIT, en build-time del propio paquete `filament/filament`) las clases que sus PROPIOS componentes usan internamente. Cualquier clase Tailwind usada en un Blade CUSTOM de la app (como `resources/views/filament/forms/components/menu-tree-builder.blade.php`, con `rounded-lg`/`shadow-sm`/`cursor-grab`/`bg-gray-100`/etc.) simplemente no tiene ningún efecto a menos que el panel tenga su propio "theme" compilado — comportamiento oficial y documentado de Filament 4/5 (confirmado con `WebSearch` + lectura de `filamentphp.com/docs/4.x/styling/overview`, sección "Using Tailwind CSS classes in your Blade views or PHP files": *"A custom theme is required to use Tailwind CSS classes in your own code... the styles won't be applied because they're not included in the compiled CSS"*). Este proyecto nunca tuvo un theme custom para el panel `cms` — hasta ahora nunca hizo falta porque ningún Blade custom anterior usaba clases Tailwind propias fuera de las que Filament ya trae.
- **Fix (oficial de Filament, replicado a mano)**: el comando real sería `php artisan make:filament-theme cms` (no ejecutable, sin runtime de PHP en el sandbox) — se replicó su resultado exacto leyendo `vendor/filament/filament/src/Commands/MakeThemeCommand.php` y su stub (`vendor/filament/filament/stubs/ThemeCss.stub`): (1) `resources/css/filament/cms/theme.css` nuevo — importa el CSS base de Filament (`@import '.../vendor/filament/filament/resources/css/theme.css'`) y declara 2 `@source` (sintaxis de Tailwind v4, sin `tailwind.config.js`) que le dicen a Tailwind dónde escanear clases: `app/Filament/**/*` y `resources/views/filament/**/*` — este último YA cubre el Blade del `MenuTreeBuilder`, sin necesitar un `@source` extra. (2) `vite.config.js`: el nuevo `theme.css` se agrega al array `input` del plugin de Laravel (junto a `app.css`/`app.js`, ya existentes). (3) `PanelCmsProvider.php`: `->viteTheme('resources/css/filament/cms/theme.css')` agregado a la cadena del panel, después de `->path('')`.
- **Verificación**: balance de sintaxis (tokenizer Python) en `PanelCmsProvider.php`, `node --check` en `vite.config.js` — OK. Se intentó compilar con `npm run build` directo en este sandbox (Node 22 y `node_modules` sí están disponibles acá, a diferencia de PHP) — **falla con un error de binding nativo de `rolldown`** (`Cannot find module '@rolldown/binding-linux-arm64-gnu'`/`binding-wasm32-wasi`): los binarios nativos instalados son `darwin-arm64` (Mac del Tech Lead), incompatibles con este sandbox Linux — mismo bloqueo ya documentado en sesiones anteriores para `astro build`/`astro check` del lado de cica360, confirmado ahora que también aplica a este repo. **No se pudo verificar visualmente ni compilar desde acá.**
- **Archivos**: `resources/css/filament/cms/theme.css` (nuevo), `vite.config.js`, `app/Providers/Filament/PanelCmsProvider.php`.
- **Siguiente — acción requerida del Tech Lead**: correr `npm run build` (o `npm run dev`/`composer run dev`) localmente para compilar el theme nuevo — sin este paso, el fix no toma efecto (el `theme.css` existe en el repo pero Filament sigue sirviendo el CSS default hasta que Vite lo compile a `public/build/`). Una vez compilado, confirmar visualmente que el `MenuTreeBuilder` se vea con las tarjetas/drag handle/badges diseñados.

## 2026-09-02 — Menu builder rediseñado: drag-and-drop propio estilo WordPress (`MenuTreeBuilder`) + endpoint público de Servicios como 4to tipo de enlace

- **Pedido en vivo** (3 capturas: UI actual de 3 Repeaters anidados, mockup plano estilo WP, editor clásico real de WordPress): "esta bueno pero no es muy amigable manejar o gestionar los submenus de esa forma, es mejor la segunda captura, desde ahi poder manejar el anidar o cambiar a parent similar a wordpress". Se preguntó explícitamente por 2 caminos (lista plana + `Select` de padre, más simple/segura, vs. drag-and-drop real) — el Tech Lead eligió **drag-and-drop real**.
- **Evaluación de 4 plugins de Filament** (URLs compartidas por el Tech Lead: `ysfkaya/filament-menu-builder`, `solution-forest/filament-tree`, `notebrainslab/filament-menu-manager`, `biostate/filament-menu-builder`) con un requisito extra: "que se pueda personalizar el tipo de enlace como tipo pagina interna, post del blog, servicio, o custom link". Los 4 descartados por el mismo motivo de fondo: ninguno se integra con el esquema `menus`/`menu_items` ya cerrado del MVP sin asumir su propio modelo de datos o requerir adaptar el schema. El Tech Lead decidió explícitamente: **"Construir el drag-and-drop propio, sin plugin"**. Detalle completo de las 4 alternativas y por qué se descartó cada una: `DECISIONS.md` ADR-045.
- **Bloqueo descubierto durante el diseño**: "Servicio" como tipo de enlace no tenía URL pública (el modelo `Service`/`ServiceResource` de Filament existían, pero sin endpoint API — pendiente ya anotado en ADR-034). Se preguntó cómo proceder (agregar el tipo con links rotos / omitir Servicio por ahora / construir el endpoint primero) — el Tech Lead eligió explícitamente: **"Construir el endpoint de servicios primero"**.
- **Fase A — Endpoint público de Servicios** (mismo patrón que `Post`, ver ADR-044): `ServiceSummaryResource`/`ServiceResource` (nuevos), `ServiceController::index()`/`show()` (nuevo, mismo esqueleto que `PostController`), 2 rutas nuevas (`GET /v1/{tenant}/services`, `/services/{slug}`) dentro del grupo `abilities:content:read` ya existente. `MenuItemTypeEnum::Service` nuevo (entre `Post` y `External`), resuelto en `MenuController::attachResolvedHrefs()` y `ResolvesPublicLinks::attachResolvedBlockContent()` (ambos con batch anti-N+1 de `Service::whereIn(...)->get(['id','slug'])`).
- **Fase B — `MenuTreeBuilder`, campo Filament propio** (ver ADR-045 para el detalle técnico completo): nuevo `App\Filament\Forms\Components\MenuTreeBuilder` (extiende `Field`) editando un array plano con `depth` por ítem, drag-and-drop vía `window.Sortable` usado directo (no la directiva `x-sortable` de Filament — no expone `onStart`/posición del mouse, necesaria para calcular indentado por arrastre horizontal), con botones indentar/desindentar como alternativa sin mouse, profundidad máx. 3 (misma jerarquía de antes), y selector de tipo de enlace (Página/Post/Servicio/Custom) inline por ítem con referencia condicional al tipo. `MenuResource` reescrito: `form()` usa un solo `MenuTreeBuilder::make('itemsTree')`; nuevos métodos privados `flattenMenuTree()` (árbol real → array plano, hidratación) y `syncMenuTree()` (array plano → `parent_id`/sort_order reales, transaccional, con altas/bajas/reorden en una sola pasada — algoritmo de pila `$parentAtDepth` por profundidad); `CreateAction`/`EditAction` con `->using()` custom (guardan solo campos propios de `Menu`, sincronizan el árbol aparte — `itemsTree` no es fillable ni relación nativa); nuevo `createAction()` público (factory compartida entre el botón de estado vacío y el de cabecera, evita duplicar el `->using()`).
- **Fix de modelo relacionado, encontrado durante esta fase**: `Page::scopePubliclyLinkable()` (ya existente desde el fix del selector de destino) reutilizado en `getPageOptions()` del nuevo builder — el selector de "Página de destino" del menu builder también queda excluyendo Header/Footer, sin duplicar el filtro.
- **cica360**: `types.ts` gana el bloque completo de tipos de Servicio (`ServiceCountry`/`ServiceOffer`/`ServiceCoverage`/`ServiceContent`/`ServiceSummary`/`Service`) y `MenuItemType` se extiende con `'service'`; `api.ts` gana la sección "Services" completa (mismo patrón que "Posts": `getServices()`/`getService()`/`getAllServices()`); nuevo `src/pages/servicios/[slug].astro` (solo el detalle — el listado `/servicios` ya lo sirve una `Page` seedeada existente vía el catch-all `[slug].astro`, se verificó que crear un `servicios/index.astro` habría colisionado con esa ruta estática ya existente, y se evitó a propósito).
- **Archivos (genesis)**: `app/Http/Resources/Api/V1/ServiceSummaryResource.php` (nuevo), `app/Http/Resources/Api/V1/ServiceResource.php` (nuevo), `app/Http/Controllers/Api/V1/ServiceController.php` (nuevo), `routes/api.php`, `app/Enums/MenuItemTypeEnum.php`, `app/Http/Controllers/Api/V1/MenuController.php`, `app/Http/Concerns/ResolvesPublicLinks.php`, `app/Filament/Forms/Components/MenuTreeBuilder.php` (nuevo), `resources/views/filament/forms/components/menu-tree-builder.blade.php` (nuevo), `public/js/filament/menu-tree-builder.js` (nuevo), `app/Filament/Resources/MenuResource.php` (reescrito), `app/Filament/Resources/MenuResource/Pages/ManageMenus.php`, `app/Providers/Filament/PanelCmsProvider.php`.
- **Archivos (cica360)**: `src/lib/types.ts`, `src/lib/api.ts`, `src/pages/servicios/[slug].astro` (nuevo).
- **Verificación**: balance de sintaxis (tokenizer Python, con el workaround ya conocido para atributos PHP 8 `#[Fillable(...)]`) en los 15 archivos PHP tocados/nuevos de genesis, `node --check` (sintaxis OK) sobre `menu-tree-builder.js`, y balance de sintaxis sobre los 3 archivos de cica360 — todo "OK". Verificación EXTRA (dado el riesgo/complejidad de un campo Filament custom nunca antes construido en este proyecto): lectura directa del código vendor de Filament 5 (`Field.php`, `Component.php`, `ComponentManager.php`, `ViewComponent.php`, `HasFieldWrapper.php`, `HasStateBindingModifiers.php`, `HasState.php`, `CreateAction.php`, `EditAction.php`, `CanCustomizeProcess.php`, `field-wrapper.blade.php`, `key-value.js` de-minificado) para confirmar cada patrón usado (`setUp()`, extracción de métodos públicos a variables Blade, el binding `$wire.$entangle(...)`, `->using()` en ambas acciones). **Sin PHP/Node(Livewire)/navegador funcional en este sandbox — nada de esto fue renderizado ni ejercitado en vivo.** Pendiente, a confirmar por el Tech Lead en Studio: (1) que `/v1/{tenant}/services` y `/services/{slug}` respondan bien; (2) que `/servicios/{slug}` renderice en cica360; (3) que el árbol del menu builder cargue con la jerarquía real, arrastrar reordene/anide/desanide correctamente (mouse y botones), los 4 tipos de enlace guarden y resuelvan, y que crear/editar/borrar en una sola sesión sincronice `menu_items` sin huérfanos. ADRs: ADR-044 (Servicios público) y ADR-045 (`MenuTreeBuilder`) en `DECISIONS.md`.
- **Siguiente**: confirmación visual/funcional del Tech Lead en Studio (menu builder + servicios). Pendiente aparte, no bloqueante: reflejar los nuevos endpoints de Servicios y el tipo `service` de menú en `docs/context/api/stamless-api-v1.md` de cica360 (regla obligatoria de su `CLAUDE.md`).

## 2026-09-02 — Fix real: Header/Footer aparecían como opción en TODOS los selectores de "página de destino" del proyecto

- **Bug reportado en vivo** (captura real, dropdown "Página de destino" de un ítem de menú): "Footer principal" listado como opción — se pidió auditar TODO el proyecto: "en todos las paginas destino a seleccionar no deberia listarse ningun partial como footer/header porque no tienen url de salida, en todo el proyecto revisar ese selector".
- **Investigación (agente, no asumido)**: grep exhaustivo de `Page::` en `app/` — 4 selectores de "destino" sin ningún filtro por tipo (mismo bug, 4 lugares): `MenuResource.php` (`reference_id`, ítems de menú), `LinkSchema.php::make()` y `::makeSingle()` (`source_id`, compartido por ~10 bloques/CTAs de toda la app — menús, colophon, slides, etc.), y `PageResource.php` (`page_id` del ítem de `services_grid`). Se identificaron también 2 selectores de `Page` que NO son parte de este bug y se dejaron intactos a propósito: `content.footer_page_id` del bloque `footer` (ese SÍ debe listar únicamente páginas tipo `Footer` — es la única referencia legítima a un partial, ya estaba bien scopeado) y `parent_id` de la jerarquía interna de páginas (organización en Studio, no es un destino de navegación pública).
- **Fix (centralizado — un scope, no 4 parches puntuales)**: `Page::scopePubliclyLinkable()` nuevo (`app/Models/Page.php`) — `whereNotIn('type', [Header, Footer])`. Aplicado en los 4 call sites confirmados (`->publiclyLinkable()`). Vivir en el modelo, no en cada Resource, evita que un 5to selector futuro reintroduzca el mismo bug.
- **Archivos**: `app/Models/Page.php`, `app/Filament/Resources/MenuResource.php`, `app/Filament/Schemas/LinkSchema.php` (2 sitios), `app/Filament/Resources/PageResource.php` (`services_grid`).
- **Verificación**: balance de sintaxis (tokenizer Python) en los 4 archivos (`Page.php` con el falso positivo conocido del atributo `#[Fillable(...)]` descartado manualmente, igual que con `MenuItem.php` antes). Sin PHP en este sandbox — pendiente confirmación visual del Tech Lead: ningún selector de "página de destino" (menús, CTAs, colophon, services_grid, slides) debería listar Header/Footer.
- **Siguiente**: ninguno.

## 2026-09-02 — Fix real: `tenant_id` NOT NULL al crear submenús anidados + ocultar tab "SEO / Enlaces" en Header/Footer

- **Bug reportado en vivo** (captura de error 500 real, "al intentar crear mas submenus"): `SQLSTATE[23502] Not null violation: tenant_id` al guardar un `MenuItem` anidado (nivel 2/3) desde `MenuResource`. El INSERT ni siquiera incluía la columna `tenant_id` en la query.
- **Causa raíz** (investigada con agente, no asumida): `App\Traits\HasTenant::bootHasTenant()` autocompleta `tenant_id` en `static::creating()` leyendo `App\Services\TenantManager`, un singleton propio de la app — **distinto y jamás conectado** a la tenancy nativa de Filament (`Filament::getTenant()`, resuelta por `IdentifyTenant` desde el segmento `{tenant:slug}` de la URL de Studio). El único punto que sí llena `TenantManager` es el middleware global `App\Http\Middleware\ResolveTenant`, pero resuelve por query param/headers o por dominio en `tenant_domains` — ninguna estrategia aplica a Studio (host fijo, tenant por slug en la URL vía Filament) — así que `TenantManager::hasTenant()` era siempre `false` dentro de Studio. Filament sí autoasocia el tenant en el modelo TOP-LEVEL de cada Resource (`BelongsToTenant::observeTenancyModelCreation()`, cubre `Menu`), pero no tiene ninguna noción de que `MenuItem` (creado por un Repeater anidado con `->relationship()`) también necesita `tenant_id` — bug latente en CUALQUIER modelo `HasTenant` creado así, no solo `MenuItem`: también afecta a `Slide` (bajo `SliderResource`, mismo patrón `Repeater::make('slides')->relationship('slides')`).
- **Fix (centralizado, arquitectónico — no un parche puntual en `MenuResource`)**: nuevo middleware `App\Http\Middleware\SyncTenantManagerWithFilament`, registrado vía `->tenantMiddleware([...])` en `PanelCmsProvider` (nuevo grupo, corre después de `IdentifyTenant` de Filament, garantizado que `Filament::getTenant()` ya resolvió) — puentea `Filament::getTenant()` hacia `TenantManager::setTenant()` en cada request de Studio. Corrige el gap de raíz para TODOS los modelos `HasTenant`, no solo `MenuItem`/`Slide`, y para cualquier Repeater anidado que se agregue a futuro.
- **2da vuelta, mismo bug, reproducido de nuevo en vivo**: el fix anterior no alcanzaba — el guardado real de Filament (crear/editar registros) no navega la página, va por el endpoint AJAX de Livewire (`/livewire-xxx/update`, confirmado en el stack trace del error repetido), que NO vuelve a correr el middleware "normal" del panel — solo el subset que Filament registra explícitamente como persistente vía `Livewire::addPersistentMiddleware()` (confirmado en el core: `IdentifyTenant` SÍ está en esa lista fija de `FilamentServiceProvider`, por eso el resto de la tenancy scoping funciona bien en guardados de otros recursos — pero un `->tenantMiddleware()` de un panel NO se agrega ahí automáticamente). Fix real: `->tenantMiddleware([SyncTenantManagerWithFilament::class], isPersistent: true)` — el flag `isPersistent` es lo que efectivamente registra el middleware en la lista persistente de Livewire.
- **3ra vuelta, mismo flujo, reproducido de nuevo en vivo**: con `tenant_id` ya resuelto, apareció un 2do NOT NULL distinto en el mismo INSERT: `menu_id` en `menu_items`, específico de sub-items (nivel 2/3). Causa raíz DISTINTA a la anterior, self-contenida en el modelo: `MenuItem::children()` es un `HasMany` SOBRE `MenuItem` mismo (self-relation por `parent_id`), no sobre `Menu` — cuando Filament guarda un item anidado por esa relación, Eloquent completa automáticamente `parent_id` (la FK de ESA relación) pero no tiene ninguna noción de `menu_id`, que pertenece a la relación `Menu::items()`, distinta. Los items de nivel 1 (`rootItems`, `HasMany` directo de `Menu`) sí lo reciben gratis por el mismo mecanismo — por eso el bug solo aparecía en submenús, nunca en items raíz. Fix: `MenuItem::booted()` nuevo — en `static::creating()`, si `menu_id` llega vacío y hay `parent_id`, lo copia del padre con una lookup mínima (`static::query()->whereKey($item->parent_id)->value('menu_id')`). Vive en el modelo (no en `MenuResource`) para cubrir cualquier camino de creación futuro, no solo el Repeater actual.
- **`PageResource.php`, pedido en la misma sesión**: el tab "SEO / Enlaces" completo (Metadata SEO, Open Graph, Enlaces relacionados, Propiedades de la página) se oculta cuando el Content editado es de tipo `Header` o `Footer` — son **partials compartidos sin URL pública propia** ("este tipo de contenido... no necesita SEO/Enlaces, nada de lo que hay en ese tab"), mismo criterio ya usado para ocultar el slug de esos 2 tipos en la tabla. `->hidden(fn (Get $get) => in_array($get('type'), [Header, Footer]))` sobre el `Tabs\Tab::make('SEO / Enlaces')`.
- **Archivos**: `app/Http/Middleware/SyncTenantManagerWithFilament.php` (nuevo), `app/Providers/Filament/PanelCmsProvider.php`, `app/Models/MenuItem.php`, `app/Filament/Resources/PageResource.php`.
- **Verificación**: balance de sintaxis (tokenizer Python) en los 4 archivos + confirmado que `Panel::tenantMiddleware()` y su parámetro `isPersistent` existen en el vendor de Filament 5 instalado, y que `IdentifyTenant` ya está en la lista persistente fija de `FilamentServiceProvider` (por eso el resto de la tenancy scoping ya funcionaba en otros recursos). `MenuItem.php` da un falso positivo del tokenizer con la sintaxis de atributos PHP 8 (`#[Fillable([...])]`, ya presente antes de esta sesión) — confirmado manualmente balanceado quitando esa línea antes de tokenizar. Sin PHP en este sandbox — pendiente confirmación visual/funcional del Tech Lead: (1) crear un submenú de nivel 2/3 en `MenuResource` ya no debería tirar 500 (2 causas distintas corregidas: `tenant_id` y `menu_id`), (2) el tab "SEO / Enlaces" ya no debería aparecer al editar Header/Footer.
- **Siguiente**: ninguno. Vale la pena, en otra sesión, auditar si algún `RelationManager`/Action custom (fuera de los Repeaters con `->relationship()` ya revisados) crea otros modelos `HasTenant` por un camino distinto — no se auditó en esta pasada.

## 2026-09-02 — 4 pedidos en vivo: paridad de Sections en Publicación/Servicios, modal de Multimedia más angosto, reorder del Menú por botones

- **Pedido en vivo** (4 capturas, un solo mensaje): "en publicacion, servicios no tiene los ajustes en las sections y todos son collapsed y descripciones" + "de archivos multimedia al editar o nuevo reducir el ancho del modal sideover" + "el menu no se puede mover drag and drop con sort, tree (hasta 3 niveles)".
- **`PostResource.php`/`ServiceResource.php`, paridad con `PageResource.php`**: "Metadata SEO" y "Open Graph (Redes Sociales)" ganan `->collapsed()` + `->description()` (les faltaba, a diferencia de "Enlaces relacionados"/"Propiedades..." que ya lo tenían en su mayoría). De paso, "Propiedades del post" (`PostResource`) y "Personalización de estilos" (`ServiceResource`) ganan descripción Y `background_type`/`background_color_secondary`/`gradient_direction` — tenían `background_color` sin el selector de tipo, gap real de la auditoría ADR-041 (esa auditoría se hizo sobre los bloques de `PageResource.php`, no sobre estos 2 recursos aparte). "Enlaces relacionados" de `PostResource` también gana descripción (le faltaba, a diferencia de la de `ServiceResource`).
- **`MediaResource.php`**: `EditAction`/`CreateAction` (ambas `->slideOver()`) ganan `->modalWidth('md')` — el form real es 1 sola columna (nombre, archivo, alt text), el ancho default dejaba casi todo el modal vacío. Mismo patrón `->modalWidth('Nxl')` ya usado en Service/Slider/Testimonial.
- **`MenuResource.php`**: el drag-and-drop por mouse (SortableJS/Alpine, default de Filament) es un gotcha conocido con Repeaters ANIDADOS varios niveles adentro — el drag de un item hijo puede interferir con el sortable del padre, reportado en vivo como "no se puede mover". Se reemplaza por `->reorderableWithButtons()` en los 2 niveles de Repeater (`rootItems` y `children`, recursivo hasta profundidad 3) — mismo patrón ya usado en `ServiceResource`, deterministas, sin depender de que el drag nativo funcione bien anidado. `orderColumn('sort_order')` (ya existía, por nivel) sigue persistiendo el orden — la jerarquía de 3 niveles en sí ya estaba implementada (ver tarea #37 de sesiones previas), lo que fallaba era la interacción de arrastre.
- **Archivos**: `app/Filament/Resources/PostResource.php`, `app/Filament/Resources/ServiceResource.php`, `app/Filament/Resources/MediaResource.php`, `app/Filament/Resources/MenuResource.php`.
- **Verificación**: balance de sintaxis (tokenizer Python) en los 4 archivos. Sin PHP en este sandbox — pendiente confirmación visual del Tech Lead: Sections collapsed+con descripción en Publicación/Servicios, modal de Multimedia más angosto, y que los botones ↑/↓ del Menú reordenen y persistan correctamente en los 3 niveles.
- **Siguiente**: ninguno.

## 2026-09-02 — Descripciones agregadas a las 3 Sections del tab "SEO / Enlaces" que no las tenían

- **Pedido en vivo** (captura del tab "SEO / Enlaces"): "falta descripción en estas sections" — "Metadata SEO", "Open Graph (Redes Sociales)" y "Enlaces relacionados" no tenían `->description()` (a diferencia de "Propiedades de la página", la 4ª del mismo tab, que ya la tenía) — mismo criterio ya establecido en la sesión para las Sections de bloques (positivo, revela qué hay adentro, no limitaciones).
- **Descripciones**: "Metadata SEO" → "Título, palabras clave y descripción que Google muestra en los resultados de búsqueda."; "Open Graph (Redes Sociales)" → "Título, descripción e imágenes con las que se ve la página al compartirla en redes sociales o chats."; "Enlaces relacionados" → "Botones o enlaces adicionales asociados a esta página (no forman parte del contenido de los bloques)."
- **Archivos**: `app/Filament/Resources/PageResource.php`.
- **Verificación**: balance de sintaxis (tokenizer Python).
- **Siguiente**: ninguno.

## 2026-09-02 — Fix real, 2da vuelta: `background_type` seguía vacío en la Sección "Propiedades de la página" (campo bindeado directo al modelo, sin Builder)

- **Reporte en vivo** (captura, tab SEO/Enlaces, Sección "Propiedades de la página"): "sigue siendo requerido y nada por default" — el fix anterior (`backfillSliderDefaults()` en `PageResource.php`) solo cubre bloques dentro del Builder de `content.blocks` (hidratados vía `loadStateFromRelationshipsUsing` custom); esta Sección en particular usa `PropertiesSchema::make(['background_type', ...])` bindeado DIRECTO a `properties.*` de la Página (sin Builder de por medio, hidratación nativa de Filament) — el backfill anterior nunca llegaba a tocarlo.
- **Fix (centralizado, no puntual)**: se agrega `->afterStateHydrated()` a las 2 definiciones compartidas del campo (`background_type`/`background_type_image`) en `PropertiesSchema.php` — si el estado hidratado llega vacío, lo setea a `'solid'` ahí mismo. Al vivir en la definición COMPARTIDA del campo (no en un call site puntual), corrige de una sola vez CUALQUIER lugar que use este campo — la Sección de Página, y también refuerza los bloques del Builder (complementa, no reemplaza, el backfill de `PageResource.php`, que sigue siendo la red de seguridad del lado del guardado).
- **Archivos**: `app/Filament/Schemas/PropertiesSchema.php`.
- **Verificación**: balance de sintaxis (tokenizer Python). Sin PHP en este sandbox — pendiente confirmación visual del Tech Lead: la Sección "Propiedades de la página" ya no debería exigir seleccionar "Tipo de fondo" a mano en páginas existentes.
- **Siguiente**: ninguno.

## 2026-09-02 — Fix real: "El campo tipo de fondo es obligatorio" bloqueaba el guardado en `colophon`/pie de página (y cualquier bloque legado)

- **Reporte en vivo** (captura, colophon/"pie de página"): "quiero guardar cambios... considerar si es requerido algo que tenga por default un valor" — el Select `background_type` (ADR-041, `->required()`) llegaba vacío ("Seleccione una opción") en un bloque que YA existía antes de que ese campo se agregara, y bloqueaba el guardado.
- **Causa raíz**: mismo bug de fondo ya documentado para los `Slider` de `properties` (ver `SLIDER_PROPERTY_DEFAULTS`/ADR-037-adenda) — el `->default('solid')` de Filament solo se aplica al CREAR un registro nuevo desde cero, nunca al hidratar datos parciales vía `loadStateFromRelationshipsUsing` (que es como se cargan bloques ya guardados). Cualquier bloque/página guardado antes de ADR-041, o donde el Tech Lead nunca abrió la sección de estilos, tiene `background_type` simplemente ausente en el jsonb — el Select lo muestra vacío y `->required()` bloquea el guardado, aunque el usuario no haya tocado esa sección.
- **Fix**: `backfillSliderDefaults()` (ya existía, aplicado tanto al cargar como al guardar cada bloque — mismo mecanismo, sin tocar los call sites) ahora también rellena `properties.background_type` con `'solid'` cuando está ausente (`BACKGROUND_TYPE_DEFAULT`, nueva constante), con el mismo criterio: nunca pisa un valor real ya elegido. Al ser un fix centralizado en la función compartida (2 call sites: hidratación y guardado del Builder de `blocks`), cubre automáticamente TODOS los bloques con `background_type`, no solo `colophon`.
- **Archivos**: `app/Filament/Resources/PageResource.php`.
- **Verificación**: balance de sintaxis (tokenizer Python). Sin PHP en este sandbox — pendiente que el Tech Lead confirme que el guardado de `colophon`/footer (y cualquier bloque legado) ya no exige seleccionar "Tipo de fondo" a mano.
- **Siguiente**: ninguno.

## 2026-09-02 — Nuevo plan "Auspicio/Convenio" (`sponsorship`): copyright acotado con "Powered by Stamless" fijo — ver ADR-043; CICA360 reasignado

- **Pedido en vivo**: "Se me ocurre un nuevo plan que tendrá las mismas características del Freemium/Free, pero con el nombre: Auspicio/Convenio, este plan si podrá modificar el copyright pero será con el formato '©[YEAR_AND_COMPANY_NAME_OR_PROJECT_NAME_INPUT] - Todos los derechos son reservados <br/> Powered by Stamless [link]'... este cliente 0 de cica360 será uno de ellos Plan Auspicio/Convención." + aclaración: "Este plan se gestionará y se asignará manualmente por la empresa propietaria B2B al cliente que en convenio o auspicio pactaron."
- **`Tenant.php`**: `isFreeTier()` ahora incluye `'sponsorship'` (mismas restricciones que Free/Freemium en cualquier otro gate). Nuevos `isSponsorshipTier()` (`plan === 'sponsorship'`) y `canEditCopyright()` (`! in_array(plan, ['free','freemium'])` — reemplaza el `! isFreeTier()` que ya no alcanza, porque Auspicio/Convenio SÍ puede editar y a la vez SIGUE siendo free-tier).
- **`PageResource.php`, `footer_bottom`**: el campo de copyright pasa de 2 a 3 variantes mutuamente excluyentes: Placeholder candado (Free/Freemium puro), `TextInput` acotado "Año y nombre" (Auspicio/Convenio, `maxLength(120)`, con helper explicando la plantilla), `TextInput` libre de siempre (cualquier otro plan pago).
- **`ResolvesPublicLinks.php`**: nueva rama en `footer_bottom` — para tenants `isSponsorshipTier()`, compone `content.copyright_html` envolviendo el fragmento (escapado con `e()`) en la plantilla fija `©[fragmento] - Todos los derechos son reservados <br/> Powered by <a href="https://stamless.com" target="_blank">Stamless</a>`; `copyright_text` queda `null` en ese caso (el fragmento crudo no se expone suelto).
- **`Cliente0Seeder.php`**: `plan` de CICA360 pasa de `'free'` a `'sponsorship'`.
- **cica360**: `types.ts` — `FooterBottomContent` gana `copyright_html?: string | null`. `FooterBottom.astro` — prioriza `copyright_html` (HTML crudo vía `set:html`, estilo scoped nuevo para el link "Powered by Stamless") sobre `copyright_text`/fallback.
- **Nota**: el dominio del link es `stamless.com` (el que usa el resto del código) — el mensaje del Tech Lead decía "stambless.com", tratado como typo.
- **Archivos**: `app/Models/Tenant.php`, `app/Filament/Resources/PageResource.php`, `app/Http/Concerns/ResolvesPublicLinks.php`, `database/seeders/Cliente0Seeder.php` (genesis); `src/lib/types.ts`, `src/components/blocks/FooterBottom.astro` (cica360).
- **Verificación**: balance de sintaxis (tokenizer Python) en los 6 archivos. Sin PHP/Node en este sandbox — pendiente `db:seed` + confirmación visual: el campo acotado aparece en Studio para CICA360, y una vez cargado el fragmento el copyright público se ve con el link real a Stamless. El seeder deja el fragmento vacío por default (mismo criterio ya establecido para este bloque).
- **Siguiente**: ninguno.

## 2026-09-02 — `colophon`: íconos opcionales en `link_list` + columna de marca con logo hardcodeado — corrección en vivo sobre el bloque

- **Pedido en vivo** (captura del footer real): "falta iconos y el logo gris poner en el footer de forma hardcode acompañando con el texto entre comillas, sin titulo (aqui los titulos en estas columnas serán opcionales) no usar el tipico heading."
- **`App\Enums\LinkIconEnum`** (nuevo): `email`/`phone`/`whatsapp`/`location`/`link`, mismo patrón que `SocialPlatformEnum` (el `value` es la clave que resuelve el ícono real en el frontend).
- **`LinkSchema::make()`** gana un 3er parámetro opcional `bool $withIcon = false` — cuando es `true`, prepende un Select de `LinkIconEnum` a cada ítem del Repeater. Deliberadamente `false` por default: los ~10 consumidores existentes de `LinkSchema::make()` (CTAs de página, menús, etc.) no lo piden y no deben ganar un campo nuevo sin pedirlo. El `link_list` de `colophon` es el único que pasa `withIcon: true`.
- **`ResolvesPublicLinks::transformPublicLink()`**: sin cambios de lógica — ya devolvía un array whitelist fijo (`type`/`label`/`source_type`/`source_slug`/`href`/`target`), se agregó `'icon' => $link['icon'] ?? null` a ese whitelist; inofensivo para cualquier otro consumidor (nunca manda `icon`, sale `null`).
- **Columna de marca (sin campo nuevo)**: el wordmark de CICA360 NO se sube por Studio — cica360 es un sitio de un solo tenant (no un frontend multi-tenant genérico), y `Header.astro` ya hardcodea sus 3 SVG de marca (`public/logos/`) con el mismo criterio: son assets del SITIO, no contenido editable. Se definió la convención "columna sin título pero con descripción = columna de marca" (`isBrandColumn()` en `Colophon.astro`) — sin agregar ningún campo a Filament. El seeder solo puso `title: null` en la columna 1 (antes `'CICA360'`).
- **Seeder**: columna 1 de `colophon` (CICA360) con `title: null` (la descripción/cita se mantiene igual, sin comillas literales — el frontend las agrega); columna "Contacto": `icon: 'email'` en el link de correo, `icon: 'whatsapp'` en el de teléfono (mismo link a `wa.me`, sin cambios de URL).
- **Archivos**: `app/Enums/LinkIconEnum.php` (nuevo), `app/Filament/Schemas/LinkSchema.php`, `app/Filament/Resources/PageResource.php`, `app/Http/Concerns/ResolvesPublicLinks.php`, `database/seeders/Cliente0ContentSeeder.php` (genesis); `src/lib/types.ts`, `src/components/blocks/Colophon.astro` (cica360).
- **Verificación**: balance de sintaxis (tokenizer Python) en los 7 archivos. Sin PHP/Node en este sandbox — pendiente que el Tech Lead corra `db:seed` y confirme visualmente: ícono de "enviar" junto al correo, ícono de WhatsApp junto al teléfono, wordmark gris + cita en itálica en la primera columna, sin heading.
- **Siguiente**: ninguno.

## 2026-09-02 — `footer_bottom` v2: copyright con gate de white-label por plan + selector de menú/texto opcional — ver ADR-042

- **Pedido en vivo** (extenso, con captura del bug de 2 footers simultáneos): "vamos cambiando de estrategia, se cambia el bloque de barra inferior..." — rediseño completo del bloque `footer_bottom` (copyright personalizado gateado por plan, selector de contenido derecho menú/texto) + consolidación del sitio a un solo footer (se retira el hardcodeado de `BaseLayout.astro`).
- **`Tenant::isFreeTier()`** (nuevo): lee `tenants.plan` (string plano), `true` para `'free'`/`'freemium'`. Sin enlazar el sub-sistema `Plan`/`Subscription` (billing, fuera de alcance del MVP).
- **`PageResource.php`, bloque `footer_bottom` reescrito**: `content.copyright_text` (`TextInput`) solo visible/editable si el tenant NO es free-tier; en Free/Freemium se muestra un `Placeholder` de solo lectura explicando el candado. `content.right_type` (`Select`: vacío/"Mostrar menú"/"Mostrar texto personalizado", `->live()`) reemplaza `secondary_text`; `content.menu_id` (`Select` de `Menu::pluck('name','id')`, scope automático por tenant) visible solo con `right_type: menu`; `content.right_text` (`TextInput`, `maxLength(40)`) visible solo con `right_type: text`.
- **`ResolvesPublicLinks.php`**: nuevo batch de `Menu` con `items` eager-loaded (solo nivel principal `parent_id: null` + `is_active: true`) — sus `reference_id` de Page/Post se suman a los `$pageIds`/`$postIds` YA existentes, sin query aparte; nueva pasada de resolución de `href` por item (mismo criterio que `MenuController::attachResolvedHrefs()`, duplicado a propósito, ver ADR-042 alternativas). Rama `footer_bottom` nueva en `transformBlockContent()`: fuerza `copyright_text: null` si el tenant es free-tier (gate reforzado, defensa en profundidad) y resuelve `menu_id` → `menu: {name, items[]}`.
- **Seeder**: `footer_bottom` de CICA360 pasa a `content: []` — sin `copyright_text` (quedaría ignorado igual, CICA360 es Free Forever) ni `right_type`/`menu_id`/`right_text` (default vacío a propósito, "predeterminado en los seeders sin nada o vacío").
- **cica360**: `types.ts` — `FooterBottomContent` reestructurado (`secondary_text` → `right_type`/`menu`/`right_text`), nuevas `FooterBottomMenu`/`FooterBottomMenuItem`. `FooterBottom.astro` reescrito: copyright SIEMPRE visible (antes el bloque entero podía no renderizar nada si ambos campos venían vacíos) con fallback hardcodeado de año dinámico ("© {año} Stamless CMS Headless. Todos los derechos reservados."); lado derecho como `<nav>` semántico (menú) o texto, centrado si no hay nada del lado derecho. `BaseLayout.astro` deja de importar/montar `Footer.astro` (el hardcodeado — nav fija + copyright fijo) — sin borrar el archivo, solo se deja de renderizar; `menu` se mantiene para `Header`.
- **Archivos**: `app/Models/Tenant.php`, `app/Filament/Resources/PageResource.php`, `app/Http/Concerns/ResolvesPublicLinks.php`, `database/seeders/Cliente0ContentSeeder.php` (genesis); `src/lib/types.ts`, `src/components/blocks/FooterBottom.astro`, `src/layouts/BaseLayout.astro` (cica360).
- **Verificación**: balance de sintaxis (tokenizer Python de una sola pasada) en los 7 archivos — sin PHP/Node en este sandbox. Pendiente confirmación visual del Tech Lead: un solo footer en el sitio; fallback de copyright para CICA360; "Mostrar menú" con "menu-principal" mostrando los links de nivel superior.
- **Siguiente**: ninguno.

## 2026-09-02 — `colophon` gana la 3ª opción "Imagen" (con filtros/blend) que le faltaba — actualización de ADR-041

- **Corrección en vivo, con capturas**: al aplicar ADR-041 (entrada de arriba), `colophon` quedó con el selector `background_type` de 2 opciones (Sólido/Degradado) en vez de 3 — el Tech Lead señaló que "no existe imagen, debería tener también la posibilidad de tener una imagen con los filtros y blend que necesiten personalizar", igual que `cta`.
- **Fix**: `colophon` pasa de `background_type` a `background_type_image` (3 opciones); gana `MediaUpload::make('background_image_id', ...)` + el mismo `Grid` de 10 filtros/blend (`media_blend_mode`/`overlay_opacity`/`media_brightness`/`media_opacity`/6 filtros CSS) que ya usa `cta`, ambos visibles/requeridos solo con `background_type: image` — mismo patrón exacto, sin inventar uno nuevo. `ResolvesPublicLinks::BLOCK_MEDIA_FIELDS` (mapa genérico ya existente) gana la entrada `'colophon' => ['background_image_id' => 'background_image']` — una sola línea, la resolución de id→objeto Media ya es automática para cualquier bloque en ese mapa (recolección + transformación), sin tocar `transformBlockContent()`.
- **cica360**: `Colophon.astro` gana la misma capa de imagen + `backgroundImageEffectStyle()` (filtros/blend/opacidad) que `Cta.astro`, sin extraerla todavía a un helper compartido (solo 2 consumidores por ahora). `types.ts`: `ColophonContent` gana `background_image?: Media | null`.
- **Archivos**: `app/Filament/Resources/PageResource.php`, `app/Http/Concerns/ResolvesPublicLinks.php` (genesis); `src/components/blocks/Colophon.astro`, `src/lib/types.ts` (cica360).
- **Verificación**: balance de sintaxis (Python, tokenizer de una pasada) en los 4 archivos.
- **Siguiente**: ninguno.

## 2026-09-02 — `background_type` requerido en todos los bloques con color de fondo + 3ª opción "Imagen" excluyente en `cta`/`heading` — ver ADR-041

- **Pedido en vivo** (con `AskUserQuestion` para acotar alcance y comportamiento exacto): "tipo de fondo" (`background_type`) pasa a ser obligatorio en TODO bloque con color de fondo; en los bloques que además tienen su propia imagen de fondo (`cta`, `heading`), se agrega una 3ª opción "Imagen" que oculta el color/degradado cuando está seleccionada (y viceversa: el campo de imagen se oculta y se vuelve obligatorio solo con "Imagen").
- **`PropertiesSchema.php`**: `background_type` gana `->required()`; nueva variante `background_type_image` (3 opciones, mismo campo de datos `properties.background_type`, usada solo en `cta`/`heading`); `background_color` gana `->visible()` condicionado a que el tipo NO sea `image`.
- **`PageResource.php`**: `background_type` sumado a 13 bloques + Página (hero, rich_text, image, features, faq, contact_form, split, testimonials, logos, services_grid, colophon, footer_bottom, página SEO/Enlaces). `cta` (sección "Fondo") y `heading` (imágenes + "Propiedades Visuales") reescritos con exclusividad real color↔imagen.
- **Seeder**: `background_type => 'solid'` explícito en los 5 bloques que ya sembraban `background_color` (CTA home, colophon, footer_bottom, 2 testimonials). `colophon`/`footer_bottom` cambian su color sembrado de `#2D2C4D` a **`#191838`** (pedido explícito, fondo predeterminado real del pie de página, distinto del indigo del CTA).
- **cica360**: `Cta.astro` migrado a `resolveBackgroundStyle()` (mismo helper de ADR-040) — gana soporte real de degradado que antes ignoraba por completo. `Heading.astro` sin cambios (stub, fuera del límite Claude=datos/Antigravity=visual del `CLAUDE.md` de cica360 — no renderizaba color/imagen antes tampoco, sin regresión).
- **Nota de proceso**: se detectó y corrigió un falso positivo en mi propio script de verificación de sintaxis (contaba `//` dentro de URLs como `https://...` en strings como inicio de comentario, corrompiendo el conteo) — reescrito como tokenizer de una sola pasada; usado para el resto de la sesión.
- **Archivos**: `app/Filament/Schemas/PropertiesSchema.php`, `app/Filament/Resources/PageResource.php`, `database/seeders/Cliente0ContentSeeder.php` (genesis); `src/components/blocks/Cta.astro` (cica360).
- **Verificación**: balance de sintaxis (Python, tokenizer corregido) en los 4 archivos — sin PHP/Node en este sandbox. Pendiente confirmación del Tech Lead: que abrir y guardar un `cta`/`heading` YA existente (sembrado antes de este cambio) no rompa por el nuevo `->required()` de `background_type`, y que el degradado del CTA se vea bien en el sitio.
- **Siguiente**: ninguno.

## 2026-09-02 — Descripción "teaser" + `->collapsed()` en las 8 secciones de personalización/properties

- **Pedido en vivo**: cada `Section` de "Personalización de estilos"/"Propiedades..." debe tener una descripción — no explicando limitaciones, sino listando en positivo qué se puede configurar adentro (para que valga la pena desplegarla); y todas deben estar colapsadas por default.
- **Cubiertas las 8 que existen en el archivo** (todas ya estaban `->collapsed()` salvo la primera):
  - `heading` > "Propiedades Visuales": +`->collapsed()` (faltaba) + "Decoradores superior e inferior, color de fondo, overlay y ajustes de brillo/contraste."
  - `cta` > "Personalización de estilos": "Color de texto, ancho del contenido y espaciado vertical de la sección."
  - `split` > "Personalización de estilos": "Posición de la imagen, colores de fondo y texto, ancho, espaciado, animación y filtros/mezcla de la imagen."
  - `testimonials` > "Personalización de estilos": "Color de fondo de la sección y de las tarjetas, color de texto, espaciado y animación de entrada."
  - `logos` > "Personalización de estilos": "Color de fondo, espaciado, ancho de contenido y filtro de escala de grises/opacidad de los logos."
  - `colophon` > "Personalización de estilos": "Ancho de contenido, fondo sólido o degradado, color de texto y espaciado vertical."
  - `footer_bottom` > "Personalización de estilos": "Fondo sólido o degradado, color de texto y espaciado vertical."
  - Página > pestaña SEO/Enlaces > "Propiedades de la página": "Color de fondo, color de texto y animación de entrada de la página."
  - Fuera de alcance a propósito: `features`/`faq` tienen sus `PropertiesSchema` sueltas, sin envolver en `Section` — no se tocó su estructura, solo se pidió cubrir las secciones ya existentes.
- **Archivo**: `app/Filament/Resources/PageResource.php`. Verificación: balance de sintaxis (Python) — sin PHP en este sandbox.
- **Siguiente**: ninguno.

## 2026-09-02 — Revisión de copy: descripciones de `Section` redundantes/obvias en `PageResource.php`

- **Pedido en vivo**: la descripción de "Botón (opcional)" en el bloque `cta` sonaba obvia ("un solo botón — activalo o desactivalo..."); pedido de revisar TODAS las descripciones de `Section` del archivo.
- **Auditoría**: se revisaron las ~15 `Section::make()->description()` del archivo. La mayoría ya son informativas (explican relaciones no obvias, ej. "Diseño de la sección" en `hero`/`rich_text`, "Filtro de testimonios", "Límite compartido con la API" en `logos`). 4 quedaron redundantes/obvias, corregidas:
  - `cta` > "Botón (opcional)": "Un solo botón — activalo o desactivalo sin perder lo ya configurado." → "Enlace a una página, post o URL externa, con ícono y estilo propios."
  - `cta` > "Fondo": "El fondo de la sección siempre ocupa el ancho completo de la pantalla..." (dato de implementación, no accionable para el editor) → "Color base de la sección; sumá una imagen opcional en capas más abajo, con mezcla y filtros propios."
  - `heading` > "Imágenes del Encabezado": "Selecciona las imágenes responsivas" (repetía literal el nombre de la sección/campos) → "Cada tamaño de pantalla puede tener su propio recorte de imagen."
  - `hero` > "Imágenes de fondo responsivas": "Sube o selecciona imágenes de fondo para diferentes dispositivos" (mismo problema) → "La imagen de escritorio es obligatoria en modo Manual; tablet y móvil son opcionales." (verificado contra el código: solo `content.background_image_id` tiene `->required()` condicional, tablet/móvil no).
- **Archivo**: `app/Filament/Resources/PageResource.php`. Verificación: balance de sintaxis (Python).
- **Siguiente**: ninguno.

## 2026-09-02 — `footer_bottom`: agregadas properties de estilo (antes solo tenía los 2 campos de texto)

- **Pedido en vivo**: el bloque `footer_bottom` no tenía sección de "Personalización de estilos" — a diferencia de todos los demás bloques del pie de página, no podía cambiar fondo/color de texto/padding.
- **Fix**: nueva `Section::make('Personalización de estilos')` colapsable en `PageResource.php` con `PropertiesSchema::make(['background_type', 'background_color', 'background_color_secondary', 'gradient_direction', 'text_color', 'padding_y'])` a 2 columnas — mismas properties genéricas que ya usa `colophon` (sólido/degradado reusable, ver ADR-040). Del lado `cica360`, `FooterBottom.astro` ya consumía `background_color`/`text_color` (vía `resolveBackgroundStyle()`) pero no tenía `padding_y` — se agregó el mismo mapa `PADDING_Y_CLASSES` que usa `Colophon.astro` (valores más chicos, `py-3` a `py-8`, acorde a que es solo una barra, no una sección completa).
- **Archivos**: `app/Filament/Resources/PageResource.php` (genesis); `src/components/blocks/FooterBottom.astro` (cica360).
- **Verificación**: balance de sintaxis (Python) en ambos archivos — sin PHP/Node en este sandbox.
- **Siguiente**: ninguno.

## 2026-09-02 — Fix real: `(data.items ?? []).map is not a function` en `Colophon.astro` (sub-bloque `social_links`, API) + reorganización de UX en `colophon`/`cta` (Filament)

- **Bug real reportado en vivo** (captura del navegador, `blocks/Colophon.astro:121:39`): `TypeError: (data.items ?? []).map is not a function` al renderizar los íconos de redes sociales de un colophon guardado desde Studio (no el sembrado por seeder, que está bien formado).
- **Causa raíz:** el Builder anidado `content.columns[].blocks[]` de `colophon` (en `PageResource.php`) no está bindeado por `->relationship()`, así que el `saveRelationshipsUsing` de nivel página recibe su estado CRUDO de Livewire (keyeado por uuid interno del widget), sin pasar por el pipeline normal de dehidratación de Filament — el mismo bug de origen que ya documenta ADR-037. El Repeater `items` de `social_links`, varios niveles adentro de esa estructura, puede terminar guardado con keys no-secuenciales → `json_encode` lo serializa como objeto `{}` en vez de array `[]` → `.map()` revienta en el frontend. `link_list`/`image_link` ya estaban blindados por accidente (el `->values()->all()` es efecto colateral de mapear con `transformPublicLink()`); `social_links` no mapea nada, así que nunca se reindexaba.
- **Fix:** `ResolvesPublicLinks::transformBlockContent()`, rama `colophon` — nuevo `elseif ($subType === 'social_links') { $data['items'] = collect($data['items'] ?? [])->values()->all(); }`. Self-healing: cualquier colophon ya guardado con keys corruptas vuelve a servir `items` como array real sin necesidad de re-sembrar.
- **De paso, 2 ajustes de UX pedidos en vivo (sin relación con el bug):** properties sueltas de `colophon` (ancho/fondo/colores/padding, antes flotando sin sección al final del bloque) ahora viven dentro de su propia `Section::make('Personalización de estilos')` colapsable, mismo patrón que `split`/`testimonials`/`logos` — y en 2 columnas. Página > pestaña SEO/Enlaces > "Propiedades de la página" (fondo/texto/animación) pasó a 3 columnas. Se probó y se revirtió un agrupamiento de las 3 secciones del bloque `cta` ("Fondo"/"Botón (opcional)"/"Personalización de estilos") dentro de un contenedor único — el Tech Lead pidió dejarlas como 3 secciones independientes, como estaban.
- **Archivos:** `app/Http/Concerns/ResolvesPublicLinks.php` (fix real); `app/Filament/Resources/PageResource.php` (`colophon`: properties en `Section` + 2 columnas; página: properties en 3 columnas; `cta`: sin cambios netos, ida y vuelta).
- **Verificación:** balance de paréntesis/llaves/corchetes (Python) en ambos archivos — sin PHP en este sandbox. Pendiente confirmación visual del Tech Lead: recargar el sitio público de CICA360 y confirmar que el footer con redes sociales ya no tira el error en consola.
- **Siguiente:** ninguno.

## 2026-09-02 — Fix real: `TypeError` en `social_links` (`SocialPlatformEnum::tryFrom()` con un enum, no un string) + badge de tipo movido junto al slug

- **Agente/autor:** Claude — 2 correcciones en vivo sobre el trabajo recién terminado del bloque `colophon`.
- **Fix 1 — `TypeError`:** reporte real del Tech Lead (captura de Studio, 500 al guardar): `App\Enums\SocialPlatformEnum::tryFrom(): Argument #1 ($value) must be of type string|int, App\Enums\SocialPlatformEnum given`, en el `itemLabel()` del Repeater de `social_links`. Causa: cuando un `Select::make()->options(EnumClass::class)` vive dentro de un `Repeater`, el `$state` que recibe `itemLabel()` puede traer el campo YA como instancia del enum (no el string crudo) dependiendo del momento del ciclo de vida en que Livewire dispara el update — `tryFrom()` exige `string|int`, revienta con un objeto. Fix: el closure ahora chequea `instanceof SocialPlatformEnum` antes de intentar `tryFrom()`, cubriendo los 2 casos.
- **Fix 2 — badge de tipo reubicado:** el Tech Lead confirmó con una captura que el primer intento (columna `type` separada, ver entrada de abajo, mismo día) quedó mal posicionado — el badge apareció en el extremo derecho de la tabla, lejos del slug ("los badges de tipo de contenido tiene que estar a lado del slug"). Se revierte esa columna separada: el badge ahora vive DENTRO de la descripción de la columna "Título", justo al lado del texto "Slug: xxx" — mismo renglón, misma celda. Técnicamente: `TextColumn::description()` acepta un `Htmlable` (el helper `e()` de Laravel detecta `Htmlable` y NO lo escapa, lo renderiza tal cual — es el mismo mecanismo que usa Filament internamente para pintar sus propios badges), así que se armó un helper nuevo `PageResource::typeBadgeHtml()` que genera el MISMO markup que produce un `TextColumn::badge()` nativo (`<span class="fi-badge fi-size-sm {clases}">`, usando `FilamentColor::getComponentClasses(BadgeComponent::class, $color)` — las clases CSS reales del sistema de color de Filament, no un `<span>` con estilos inventados a mano) y se concatena junto al slug en un solo `HtmlString`.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (`itemLabel()` de `social_links`; columna `title` — `description()` reescrita; columna `type` standalone ELIMINADA; nuevo helper privado `typeBadgeHtml()`; imports nuevos `FilamentColor`/`BadgeComponent`/`HtmlString`).
- **Verificación:** balance de paréntesis/llaves/corchetes (Python, comment/string-aware) — 962/962, 39/39, 185/185 en el archivo completo. Sin PHP en este sandbox — pendiente confirmación visual del Tech Lead: (a) "Crear Sección" con un sub-bloque de redes sociales guarda sin el `TypeError`; (b) el badge de tipo aparece pegado al texto "Slug: xxx", no en una columna aparte.
- **Siguiente:** ninguno.

## 2026-09-02 — Bloques `colophon`/`footer_bottom` (Content tipo `Footer`) + fondo sólido/degradado genérico — ver ADR-040

- **Agente/autor:** Claude, a pedido del Tech Lead con captura de referencia (footer real de 3 columnas + barra de copyright inferior): "agregar un nuevo bloque en el tipo footer... 'Colophon'... hasta 4 columnas... con un botón dropdown para elegir unos subbloques (lista de links, links de redes sociales con ícono predeterminado, imagen con link)... properties básicos: ancho de contenido, tipo color de fondo (sólido o gradiente)..." + "un último bloque para el pie de página, que permita tener 2 casillas input para colocar el copyright y otro texto opcionales... si es uno al centro si son 2 se posicionan a los costados" + mid-turn: "si existe ese componente de fondo color, podría agregarse la opción de tipo de fondo y color de fondo secundario y dirección del gradiente, para que cualquier sección donde se requiera tenga opción a gradiente" + "en el seeder va solo colores sólidos predefinidos como están".
- **Qué se hizo:**
  - **`App\Enums\SocialPlatformEnum`** nuevo (Facebook/Instagram/LinkedIn/X/YouTube/TikTok/WhatsApp) — el `value` de cada caso es la clave que usa el frontend para elegir el ícono de marca (Phosphor `ph:{platform}-logo-fill`, ya disponible vía `@iconify-json/ph`, sin dependencia nueva).
  - **`PropertiesSchema.php`**: 3 campos GENÉRICOS nuevos (no específicos de `colophon`, reusables por cualquier bloque futuro) — `background_type` (`solid`/`gradient`), `background_color_secondary` (visible solo si `gradient`), `gradient_direction` (8 direcciones, valores 1:1 con `bg-gradient-to-*` de Tailwind). `background_color` (ya existente) sigue siendo el color base en los 2 modos.
  - **`PageResource.php`**: 2 `Builder\Block` nuevos, ambos SOLO disponibles para Content tipo `Footer` (`$footerOnlyBlocks`, filtro inverso al `$footerAllowedBlocks` ya existente — nunca aparecen en Página/Landing/Legal). `colophon`: sin `HeadingFieldset` ("no tendrá header o heading"), `Repeater::make('content.columns')` con `->maxItems(4)` e `itemLabel` literal por posición ("Columna 1/2/3/4"), cada columna con título corto + descripción (`maxLength(120)`, contador vía `->hint()`) + un `Builder` ANIDADO ("botón dropdown para elegir subbloques") con 3 tipos: `link_list` (reusa `LinkSchema::make()` tal cual), `social_links` (`Repeater` de `{platform, url}`), `image_link` (`MediaUpload` + `LinkSchema::makeSingle()['main']`). `footer_bottom`: 2 `TextInput` opcionales (`content.copyright_text`/`content.secondary_text`), el layout centrado-vs-costados se decide en el FRONTEND, no en Filament.
  - **`ResolvesPublicLinks.php`**: nuevo helper privado `collectLinkIds()` (compartido entre `attachResolvedLinks()` y la recolección nueva de `colophon`, evita duplicar la misma condición); nueva query batched de `Post` dentro de `attachResolvedBlockContent()` (antes solo la tenía `attachResolvedLinks()` — `colophon` es el primer tipo de bloque cuyo `content` puede referenciar un Post); rama `colophon` en `transformBlockContent()` que recorre `content.columns[].blocks[]` (estructura de 3 niveles que ningún mapa genérico existente cubre) resolviendo `link_list`/`image_link` de cada sub-bloque vía `transformPublicLink()`/`resolveMediaRef()` — `social_links` no tiene ningún id que resolver, pasa tal cual.
  - **cica360**: `src/lib/background.ts` nuevo (`resolveBackgroundStyle()`, genérico y reusable — primer consumidor `Colophon.astro`, pero cualquier bloque futuro que sume los mismos 3 campos a su `PropertiesSchema::make([...])` lo puede reusar sin tocar este archivo de nuevo); `Colophon.astro`/`FooterBottom.astro` nuevos; `BlockRenderer.astro` los registra; `types.ts` gana `colophon`/`footer_bottom` en `BlockType` + interfaces `ColophonContent`/`ColophonColumn`/`ColophonSubBlock`/`ColophonLinkListData`/`ColophonSocialLinksData`/`ColophonImageLinkData`/`FooterBottomContent`/`SocialPlatform`.
  - **`Cliente0ContentSeeder.php`**: `upsertFooterPage()` gana ambos bloques — `colophon` con 3 columnas (marca+tagline "CICA360", "Contacto" con email/WhatsApp vía `link_list`, "Síguenos" con `social_links`), colores SÓLIDOS únicamente (`background_type: solid`, sin degradado sembrado, a pedido explícito); `footer_bottom` con el copyright del año actual.
- **Archivos/áreas:** `app/Enums/SocialPlatformEnum.php` (nuevo), `app/Enums/BlockTypeEnum.php`, `app/Filament/Schemas/PropertiesSchema.php`, `app/Filament/Resources/PageResource.php`, `app/Http/Concerns/ResolvesPublicLinks.php`, `database/seeders/Cliente0ContentSeeder.php`; cica360: `src/lib/background.ts` (nuevo), `src/components/blocks/Colophon.astro` (nuevo), `src/components/blocks/FooterBottom.astro` (nuevo), `src/components/blocks/BlockRenderer.astro`, `src/lib/types.ts`.
- **Verificación:** balance de paréntesis/llaves/corchetes verificado (Python, comment/string-aware) en los 6 archivos PHP y los 5 archivos TS/Astro — todos cuadran. Conteo de tags HTML (section/div/ul/li/a/h3/p/img) verificado en los 2 componentes Astro nuevos — todos cuadran. `npx astro check`/`npm run build` no se pudieron correr en este sandbox (binario nativo de `rolldown` roto en el entorno — error preexistente del sandbox, no relacionado a este cambio). Sin PHP en este sandbox — pendiente `vendor/bin/pint --dirty`, `php artisan test`, `npm run build`, `php artisan db:seed --class=Cliente0ContentSeeder`, y confirmación visual del Tech Lead: (a) el bloque `colophon` solo aparece como opción al editar un Content tipo `Footer`, nunca en Página/Landing/Legal; (b) hasta 4 columnas, cada una con su propio dropdown de sub-bloques; (c) el contador de caracteres de "Descripción breve" funciona; (d) `background_type: gradient` muestra el segundo color + dirección, y el degradado se ve en el sitio; (e) `footer_bottom` centra el texto con 1 campo cargado y lo separa a los costados con los 2.
- **Siguiente:** ninguno de mi lado. Nota para el futuro: los 3 campos de gradiente en `PropertiesSchema` (`background_type`/`background_color_secondary`/`gradient_direction`) están disponibles pero no consumidos aún por ningún bloque salvo `colophon`/`footer_bottom` — sumarlos al `PropertiesSchema::make([...])` de cualquier otro bloque (ej. `cta`, `rich_text`) les da la opción de degradado gratis, sin tocar `PropertiesSchema.php` de nuevo; del lado del frontend, cualquier bloque nuevo que los use puede reusar `resolveBackgroundStyle()` de `src/lib/background.ts` tal cual.

## 2026-09-02 — `PageResource`: el tipo de contenido pasa a ser badge propio, separado del slug

- **Agente/autor:** Claude, a pedido del Tech Lead sobre una captura del listado de Páginas: "el tipo de contenido que está a lado del slug tiene que ser un badge para que no se confunda con el slug y sin guion intermedio".
- **Qué se hizo:** la columna "Título" mostraba "Slug: casos-de-exito - Página" como texto plano en la descripción (`TextColumn::description()` es texto plano, no puede renderizar un componente Badge dentro de la misma celda). Se sacó el tipo de esa descripción (ahora solo "Slug: casos-de-exito", sin " - Tipo"; `Header`/`Footer` sin descripción en absoluto, ya que tampoco muestran slug) y se agregó una columna nueva `Tables\Columns\TextColumn::make('type')->label('Tipo')->badge()` justo al lado, con color por tipo (`Página`→primary, `Landing`→info, `Legal`→warning, `Header`/`Footer`→gray) — mismo patrón visual que ya usa la columna `Estado`.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (columna `title`/`description()`, nueva columna `type`).
- **Verificación:** balance de paréntesis/llaves/corchetes verificado con strip de comentarios/strings (Python, sin PHP en este sandbox) — 868/868, 36/36, 171/171 en el archivo completo. Pendiente confirmación visual del Tech Lead en Studio.
- **Siguiente:** ninguno.

## 2026-09-01 — Fix real: `SQLSTATE[23502]` `lang_iso` NOT NULL al crear contenido — preset completo en los 7 botones de creación

- **Agente/autor:** Claude — reporte real del Tech Lead con captura de Studio (500 al guardar "Crear Aviso Legal", justo después del fix de los botones de estado vacío de la entrada de abajo) + pedido explícito: "revisar que desde ese botón en el listado tanto para página, legal y secciones se preseteen los valores requeridos completos para evitar problemas".
- **Causa:** los 4 `Actions\CreateAction` del dropdown "Crear Contenido" (`ManagePages::getHeaderActions()`) y, tras el fix de la entrada de abajo, los 3 `Actions\CreateAction` de `emptyStateActions()` en `PageResource.php`, solo forzaban `type` explícitamente vía `mutateFormDataUsing()`. `lang_iso` y `status` dependían de que sobrevivieran los defaults del FORM (`Hidden::make('lang_iso')->default('es')`, `Select::make('status')->default('draft')`) a través del `->fillForm()` de un `CreateAction` — y en la práctica `lang_iso` no sobrevivía: llegaba `null` explícito al `insert`, lo cual pisa el default de la COLUMNA en Postgres (un default de columna solo aplica cuando la clave está AUSENTE del `insert`, no cuando está presente con `null` — `DETAIL: Failing row contains (...)` del error confirmó `lang_iso` en `null` con `type='legal'` correctamente seteado). El mismo bug latente afectaba a los 7 puntos de entrada, no solo "Crear Aviso Legal" — nunca se había manifestado antes porque, hasta el fix de los botones de estado vacío (entrada de abajo), esos 3 nunca habían llegado a ejecutar un guardado real.
- **Fix:** `lang_iso` (`LanguageEnum::Spanish->value`) y `status` (`PublishStatusEnum::Draft->value`) ahora se fuerzan explícitos en los 7 `CreateAction`, en dos puntos: `->fillForm([...])` (precarga visible en el modal) y `->mutateFormDataUsing(fn (array $data) => array_merge($data, [..., 'lang_iso' => $data['lang_iso'] ?? LanguageEnum::Spanish->value, 'status' => $data['status'] ?? PublishStatusEnum::Draft->value]))` (red de seguridad final antes del insert — usa `?? ` para no pisar un valor que el usuario sí llegó a cambiar a mano), mismo patrón ya usado para `type`.
- **Archivos/áreas:** `app/Filament/Resources/PageResource/Pages/ManagePages.php` (4 acciones del dropdown "Crear Contenido" + import de `LanguageEnum`/`PublishStatusEnum`), `app/Filament/Resources/PageResource.php` (3 acciones de `emptyStateActions()` + import faltante de `LanguageEnum`, agregado en esta vuelta).
- **Verificación:** balance de paréntesis/llaves/corchetes verificado con un strip manual de comentarios/strings (el conteo bruto daba falsos positivos por los `(`/`)` sueltos en comentarios en español) — ambos archivos balanceados. Sin PHP en este sandbox — pendiente que el Tech Lead confirme en Studio que "Crear Página"/"Crear Cabecera"/"Crear Pie de página"/"Crear Aviso Legal" (dropdown) y "Crear Página"/"Crear Aviso Legal"/"Crear Sección" (estado vacío) guardan sin error en las 7 rutas.
- **Siguiente:** ninguno de mi lado. Nota para el futuro: este bug (`Hidden` field default no sobrevive `CreateAction::fillForm()`) es un patrón general de Filament 5 — cualquier `CreateAction` nuevo que dependa de un default de un campo oculto del form debe forzar el valor explícito en `fillForm()`/`mutateFormDataUsing()`, no confiar en el default del campo.

## 2026-09-01 — 3 ajustes menores reportados en vivo: label del bloque `footer`, campo "Orden" de `logos` opcional, `is_home` restringido en la tabla

- **Agente/autor:** Claude, 3 correcciones puntuales pedidas por el Tech Lead sobre capturas de Studio, en la misma sesión que el resto de las entradas de este día.
- **Qué se hizo:**
  - **Label del bloque `footer`** — "no usar estas expresiones '(referencia a sección compartida)', confundirá": `BlockTypeEnum::Footer` y el `Builder\Block::make('footer')->label(...)` en `PageResource.php` pasan de `"Footer (referencia a sección compartida)"` a simplemente `"Footer"`.
  - **Campo "Orden" del bloque `logos`** — "debería ser opcional orden, pero si debería ser requerido entonces que tenga un valor preseleccionado": se sacó `->required()` del `Select::make('content.order')`, se mantuvo `->default('first')` (el resolver en `ResolvesPublicLinks.php` ya tenía `$content['order'] ?? 'first'` como fallback, así que el campo vacío nunca rompe nada).
  - **`is_home` restringido en la tabla** — "is_home solo puede definirse o seleccionarse en tipo página y landing, no puede ser un footer, header o legal": el FORM (`HeadingFieldset.php`) ya tenía esta restricción vía `->visible()`, pero la columna `is_home` de la TABLA (ícono clickeable tipo toggle) no la tenía — un Header/Footer/Legal podía marcarse como Inicio con un clic directo en el listado. Nuevo `private const IS_HOME_ELIGIBLE_TYPES = [PageTypeEnum::Page, PageTypeEnum::Landing]` en `PageResource`; la columna ahora no muestra ícono (ni tooltip de "marcar como Inicio") para tipos no elegibles, y la `Actions\Action` que dispara el toggle queda `->disabled()` con un guard adicional dentro del `->action()` por si acaso.
- **Archivos/áreas:** `app/Enums/BlockTypeEnum.php`, `app/Filament/Resources/PageResource.php` (label del bloque `footer`, `Select` de `logos`, columna `is_home` + `IS_HOME_ELIGIBLE_TYPES`).
- **Verificación:** revisión manual (sin PHP en este sandbox). Pendiente confirmación visual del Tech Lead: (a) el bloque `footer` en el Builder ya no muestra la frase entre paréntesis; (b) "Orden" en `logos` ya no exige selección; (c) el ícono de Inicio no aparece (ni es clickeable) en filas Header/Footer/Legal.
- **Siguiente:** ninguno.

## 2026-09-01 — Fix real: botones "Crear Aviso Legal"/"Crear Sección" del estado vacío no hacían nada

- **Agente/autor:** Claude — reporte del Tech Lead: "tanto legales como secciones no abre o no funciona el botón tipificado para crear un contenido".
- **Causa:** el botón del estado vacío (`table()->emptyStateActions()`) era UNA sola `Actions\Action` genérica cuyo `->action()` hacía `$livewire->mountAction($actionName)` — un intento de "montar" por nombre una acción DISTINTA, registrada en otra clase (`ManagePages::getHeaderActions()`, el dropdown "Crear Contenido" del header de la página). Ese patrón indirecto es frágil: si `mountAction()` no encuentra la acción (por nombre, timing de cacheo entre el ciclo de vida de la Table vs. el de la Page, o cualquier otro desajuste de contexto), Filament simplemente no hace nada — sin excepción, sin mensaje, el clic "no abre y no tira nada", exactamente el síntoma reportado. Nunca se manifestó en la tab "Páginas" porque esa tab casi siempre tiene contenido (el estado vacío nunca se renderiza ahí), así que solo era observable en tabs realmente vacías como Legales/Secciones.
- **Fix:** se reemplaza la acción "puente" por 3 `Actions\CreateAction` autocontenidos y directos — la MISMA configuración (`model`/`form`/`fillForm`/`mutateFormDataUsing`/`slideOver`) que ya usan los botones del dropdown "Crear Contenido", pero cada uno con su propio `->visible(fn ($livewire) => ($livewire->activeTab ?? 'paginas') === '...')` para que solo se muestre el que corresponde a la tab activa. Sin indirección: el botón que se ve ES la acción que crea el registro, no un disparador de otra cosa. "Secciones" (Header+Footer agrupados en esa tab) sigue creando un Header por default desde el estado vacío — mismo criterio simplificado que tenía la versión anterior; Footer sigue disponible desde el dropdown del header.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (`table()->emptyStateActions()`).
- **Verificación:** balance de paréntesis/llaves/corchetes del diff correcto; confirmado que `Filament\Actions\CreateAction` existe en `vendor/filament/actions/src/CreateAction.php` (namespace correcto, ya importado vía `use Filament\Actions;`). Sin PHP en este sandbox — pendiente que el Tech Lead confirme en Studio que "Crear Aviso Legal" (tab Legales) y "Crear Sección" (tab Secciones) abren el modal correcto con el tipo precargado.
- **Siguiente:** ninguno.

## 2026-09-01 — Fix real: 500 en el sitio público (`Class "App\Http\Concerns\Block" not found`) — bloque `footer` sin importar

- **Agente/autor:** Claude — reporte real del Tech Lead: tras correr `php artisan migrate` (soft delete de `pages`, ver entrada de abajo), el sitio de cica360 seguía mostrando `ApiError: Error interno. Intentá de nuevo.` en TODAS las páginas.
- **Causa:** al agregar la resolución del bloque `footer` en `transformBlockContent()` (`ResolvesPublicLinks.php`), usé `fn (Block $footerBlock): array => [...]` como type-hint del closure que mapea los bloques de la página de footer referenciada — pero nunca importé `App\Models\Block` en este trait (namespace `App\Http\Concerns`). PHP resuelve el nombre de clase de un type-hint recién cuando la función se INVOCA (no al parsear el archivo), así que el error no aparecía hasta que efectivamente se resolvía un bloque `footer` — y como el seeder (`appendFooterBlock()`, ver entrada previa) agrega ese bloque al final de las 5 páginas públicas, CUALQUIER página del sitio lo disparaba. El error real (`Class "App\Http\Concerns\Block" not found`) queda oculto detrás del mensaje genérico de la API (ADR-024: nunca expone el error real al cliente) — coincidencia de timing con el fix de la migración de soft delete hizo parecer que era la misma causa, pero era un bug distinto y nuevo, introducido en el mismo cambio del bloque `footer`.
- **Fix:** `use App\Models\Block;` agregado a los imports de `ResolvesPublicLinks.php`. Revisado el resto del archivo por el mismo patrón (`grep` de `fn (Tipo $var)`) — solo esos 2 closures usan type-hints de clase (`Testimonial`, ya importado, y `Block`, ahora corregido); ninguno más.
- **Archivos/áreas:** `app/Http/Concerns/ResolvesPublicLinks.php` (imports).
- **Verificación:** balance de paréntesis/llaves/corchetes sin cambios (el fix es una línea de `use`). Sin PHP en este sandbox — pendiente que el Tech Lead recargue el sitio y confirme que el 500 desaparece en todas las páginas.
- **Siguiente:** ninguno.

## 2026-09-01 — `pages`: soft delete + papelera en Studio + prefijo "Slug: " en el listado — ver ADR-039

- **Agente/autor:** Claude, a pedido del Tech Lead sobre una captura del listado de Páginas: "tanto para el tipo página, landing y legal, si debe mostrar slug debajo pero usar prefijo 'Slug: ' para identificar o diferenciarse en algunos casos del mismo título similar, los slug son únicos por tenant y al ser borrados deberán ser soft-delete... solo no deberían duplicarse con contenido activo y no borrado (softdelete)... que permita forzar borrado permanente o vaciar basurero" +, mid-turn: "que permita restaurar o recuperar un contenido de la papelera por si borró accidentalmente".
- **Qué se hizo:**
  - **Migración nueva** (`2026_09_01_000001_add_soft_deletes_to_pages_table.php`): agrega `deleted_at`, reemplaza el `unique(['tenant_id','lang_iso','slug'])` plano por un **índice único parcial** (`CREATE UNIQUE INDEX ... WHERE deleted_at IS NULL`, vía `DB::statement()` — sintaxis idéntica en PostgreSQL y SQLite, sin ramificar por driver) — así una página papelereada no bloquea reusar su slug, pero dos páginas ACTIVAS con el mismo slug siguen siendo imposibles.
  - **`App\Models\Page`**: trait `SoftDeletes` + `'deleted_at' => 'datetime'` en `casts()`. Sin tocar nada más — el global scope que agrega el trait ya excluye automáticamente los registros papelereados de CUALQUIER query normal (`Page::query()`, la validación `->unique()` de `HeadingFieldset::validSlug()`, la resolución pública, el `Select` del bloque `footer`, el `Select` de `parent_id`), así que esos puntos quedan correctos sin cambios.
  - **`PageResource`**: nuevo `getEloquentQuery()` que saca el `SoftDeletingScope` (necesario para que la tabla pueda mostrar papelereados cuando corresponda); `Tables\Filters\TrashedFilter::make()` nuevo en `filters()` (3 estados: sin papelereados/default, con papelereados, solo papelereados); `Actions\RestoreAction`/`ForceDeleteAction` nuevas en `actions()` (Filament ya trae la visibilidad correcta por default — solo aparecen si el registro está papelereado); `Actions\RestoreBulkAction`/`ForceDeleteBulkAction` en `bulkActions()`; nueva acción de header "Vaciar papelera" (`->headerActions()`) que corre `Page::onlyTrashed()->forceDelete()` sobre TODO lo papelereado del tenant de una sola vez (no requiere seleccionar fila por fila), con confirmación y visible solo si hay al menos 1 papelereado.
  - **Prefijo "Slug: "**: la columna "Título" para `Página`/`Landing`/`Legal` pasó de `"casos-de-exito - Página"` a `"Slug: casos-de-exito - Página"` — evita la ambigüedad de que esa segunda línea parezca un subtítulo cuando 2 títulos se parecen. `Header`/`Footer` no cambian (siguen sin mostrar slug, ver entrada de abajo, mismo día).
  - **Fix de paso, bug real reportado en vivo**: el `Builder\Block::make('footer')` agregado un rato antes en la misma sesión (ver entrada de abajo) usaba `->icon('heroicon-o-rectangle-bottom')` — ese nombre de ícono no existe en el set de Heroicons, tiraba `BladeUI\Icons\Exceptions\SvgNotFound` (500 real, capturado por el Tech Lead abriendo el tab "Secciones"). Corregido a `heroicon-o-rectangle-stack` (mismo ícono ya usado para el botón "Crear Pie de página (Footer)" en `ManagePages.php`, confirmado válido).
- **Archivos/áreas:** `database/migrations/2026_09_01_000001_add_soft_deletes_to_pages_table.php` (nuevo), `app/Models/Page.php`, `app/Filament/Resources/PageResource.php` (`getEloquentQuery()`, `filters()`, `actions()`, `bulkActions()`, `headerActions()`, columna "Título", ícono del bloque `footer`).
- **Verificación:** balance de paréntesis/llaves/corchetes del diff (`git diff | grep '^+'` aislado) correcto en los 3 archivos PHP. Sin PHP en este sandbox — pendiente `php artisan migrate`, `vendor/bin/pint --dirty`, `php artisan test`, y confirmación visual del Tech Lead: (a) papelerear una página y confirmar que desaparece del listado default; (b) crear una página nueva con el MISMO slug que una recién papelereada y confirmar que NO choca; (c) el filtro de papelera muestra los 3 estados correctamente; (d) "Restaurar" en una fila papelereada la trae de vuelta; (e) "Vaciar papelera" borra todo lo papelereado del tenant tras confirmar; (f) el bloque `footer` ya no tira el 500 de ícono al abrir el tab "Secciones".
- **Siguiente:** ninguno de mi lado. Nota para el futuro: la validación `->unique()` del slug (`HeadingFieldset.php`) y `validSlug()` NO están scoped por `lang_iso` (solo por el global scope de tenant) — gap preexistente, no introducido ni corregido en esta vuelta, fuera de alcance de lo pedido.

## 2026-09-01 — Bloque `footer`: referencia por página a un Content compartido tipo `Footer` (reemplaza el fetch global del frontend)

- **Agente/autor:** Claude, a pedido del Tech Lead: "en el tipo página o landing... necesitamos un bloque footer que permita seleccionar un contenido de su mismo tenant que sea de tipo footer, para relacionarlo como un grupo de secciones comunes en varias páginas que tengan a ese footer". Aclarado por `AskUserQuestion`: este mecanismo **reemplaza** (no coexiste con) el fetch fijo a `footer-principal` que tenía `BaseLayout.astro` en cica360 (entrada del día anterior, más abajo).
- **Qué se hizo:**
  - **`BlockTypeEnum::Footer = 'footer'`** (`app/Enums/BlockTypeEnum.php`) — nuevo caso, label "Footer (referencia a sección compartida)".
  - **Nuevo `Builder\Block::make('footer')`** en `PageResource.php`: un único campo `Select` (`content.footer_page_id`), opciones = `Page::query()->where('type', PageTypeEnum::Footer->value)->pluck('title', 'id')` (tenant-scoped implícito vía el global scope de `Page`, mismo criterio que el `Select` de `parent_id` ya existente). A propósito NO se incluye en `$footerAllowedBlocks` (el subconjunto permitido para Content tipo `Footer`) — un footer no puede referenciar otro footer; ver además el guard server-side de abajo.
  - **Resolución recursiva en `ResolvesPublicLinks.php`:** `attachResolvedBlockContent()` y `transformBlockContent()` ganan un parámetro `bool $resolveFooterBlocks = true` (guard anti-recursión). Se recolectan los `footer_page_id` de todos los bloques `footer` de la página, se hace una query batched de esos `Page` (con sus propios `blocks` visibles, ordenados), y en `transformBlockContent()` un nuevo branch `if ($type === 'footer')` resuelve la página referenciada COMPLETA — vuelve a llamar `attachResolvedLinks()` + `attachResolvedBlockContent($footerBlocks, resolveFooterBlocks: false)` sobre sus propios bloques — y arma `content.footer_page = ['slug' => ..., 'blocks' => [...]]`, cada bloque con la MISMA forma que produce `BlockResource` (`uuid`/`type`/`pretitle`/`title`/`subtitle`/`content`/`links`/`properties`/`sort_order`), para que el frontend reutilice su `BlockRenderer` genérico sin lógica especial.
  - **Seeder (`Cliente0ContentSeeder.php`):** reordenado `run()` para crear el Content `footer-principal` (`upsertFooterPage()`) antes de `home` (que ahora puede referenciarlo). Nuevo helper `appendFooterBlock(Page $page, Tenant $tenant, int $footerPageId)` agrega el bloque `footer` al final de CADA página pública (`contacto`, `sobre-cica`, `servicios`, `casos-de-exito`, `home`) en un segundo paso, después de que el Content de footer ya existe — así el CTA vuelve a aparecer en todo el sitio, ahora vía el bloque explícito en vez de un fetch global.
  - **Frontend (cica360, mismo día):** `BlockRenderer.astro` gana `footer: FooterBlock`; nuevo `FooterBlock.astro` re-despacha `block.content.footer_page.blocks[]` al mismo `BlockRenderer`. `BaseLayout.astro`/`Footer.astro` vuelven a su forma anterior (Footer.astro solo recibe `menu`, sin `blocks` ni fetch de ningún Content) — el contenido del footer ahora llega como un bloque más dentro de `page.blocks`, renderizado en `[slug].astro`/`index.astro` como cualquier otro. Ver `cica360/docs/context/PROGRESS.md`.
- **Archivos/áreas:** `app/Enums/BlockTypeEnum.php`, `app/Filament/Resources/PageResource.php` (nuevo bloque `footer`), `app/Http/Concerns/ResolvesPublicLinks.php` (resolución recursiva), `database/seeders/Cliente0ContentSeeder.php` (orden de `run()` + `appendFooterBlock()`).
- **Verificación:** balance de paréntesis/llaves/corchetes del diff (aislado con `git diff | grep '^+'`) correcto en los 4 archivos PHP tocados. Sin PHP en este sandbox — pendiente `vendor/bin/pint --dirty`, `php artisan test`, correr el seeder y confirmar en Studio que: (a) `Página`/`Landing` ofrecen el bloque `footer` con el Select cargando los Content tipo `Footer`; (b) un Content tipo `Footer` NO ofrece `footer` en su propio picker; (c) la API (`/pages/{slug}`) devuelve `footer_page.blocks[]` resuelto para un bloque `footer`; (d) en cica360, el CTA vuelve a aparecer al final de cada página pública.
- **Siguiente:** confirmación visual del Tech Lead en Studio + frontend real (`npm run build`/`dev`). Considerar, en un paso futuro, permitir más de un Content tipo `Footer` por tenant (ya soportado por el schema — el Select ya lista todos) para casos de landings con footer distinto.

## 2026-09-01 — Header/Footer: ya no muestran el slug en el listado de Páginas

- **Agente/autor:** Claude, a pedido del Tech Lead: "cuando es una sección footer así como header son tipos de contenido que no tendrán slug... son partials compartidos" — y luego, sobre el primer intento (que agregaba "— sección compartida, sin URL propia" como texto explicativo): "dejar de mostrar estos escritos o comentarios".
- **Qué se hizo:** en la columna "Título" de `PageResource` (tabla de Páginas), la descripción bajo el título para tipos `Header`/`Footer` se simplificó a solo el label del tipo (ej. "Pie de página"), sin comentario adicional — el slug sigue sin mostrarse (sigue existiendo en la tabla/DB, sigue siendo el identificador interno que usa el bloque `footer` para referenciarlas), pero tampoco se agrega texto explicativo extra. El resto de los tipos (`Página`/`Landing`/`Legal`) sigue mostrando "slug - Tipo" sin cambios.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (`->description()` de la columna `title`).
- **Verificación:** revisión manual (sin PHP en este sandbox). Pendiente: confirmación visual del Tech Lead en Studio.
- **Siguiente:** ninguno.

## 2026-09-01 — Fix: `ErrorException: Undefined variable $richTextLinkMainFields` (bug propio del cambio anterior)

- **Agente/autor:** Claude — reporte real del Tech Lead con captura de Studio (500 al guardar/renderizar el Builder de bloques).
- **Causa:** al convertir `->blocks([...])` (array literal) en `->blocks(function (Get $get) { ... })` (Closure, entrada de abajo, mismo día) para poder condicionar la lista por `type`, me olvidé de que un Closure de PHP NO hereda automáticamente las variables del scope que lo contiene — a diferencia de un array literal, que vive en el mismo scope de `form()`. 5 variables calculadas antes del Builder (`$richTextLinkFields`/`$richTextLinkMainFields` para `rich_text`, `$testimonialsLinkFields` para `testimonials`, `$ctaLinkFields`/`$ctaLinkMainFields` para `cta` — todas del patrón `LinkSchema::makeSingle()`) quedaron fuera de alcance dentro del Closure, y Filament lo primero que hace al montar el Builder es evaluar TODOS los bloques (no solo el que se abre) — por eso rompía apenas se tocaba el formulario, no solo al abrir `rich_text`.
- **Fix:** `use ($richTextLinkFields, $richTextLinkMainFields, $testimonialsLinkFields, $ctaLinkFields, $ctaLinkMainFields)` explícito en la firma del Closure.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (firma de `->blocks(function (Get $get) use (...) {...})`).
- **Verificación:** revisión manual línea por línea de las 6 referencias a esas 5 variables dentro del array de bloques (`grep` confirmó exactamente esas y ninguna más), balance de paréntesis/llaves/corchetes del archivo completo sin cambios netos respecto a antes del fix (el `use (...)` es un paréntesis balanceado en sí mismo). Sin PHP en este sandbox para reproducir el error real. Pendiente: el Tech Lead debe confirmar que Studio carga sin el 500.
- **Siguiente:** ninguno — con esto el fix de la entrada de abajo queda completo.

## 2026-09-01 — Bloques del Builder condicionados por tipo de Content + Content "Footer principal" con el CTA trasladado

- **Agente/autor:** Claude, a pedido del Tech Lead: "en el colofón o footer en ese tipo de contenido debería estar disponible el llamado a la acción (CTA)" + lista cerrada de bloques permitidos para `Footer` + "trasladar el bloque predeterminado Llamado a la Acción (CTA), de esa forma dependerá del footer" + "el bloque en el tipo de contenido 'Página', dejar como está con todos esos bloques existentes".
- **Qué se hizo:**
  - **Picker de bloques condicionado por `type`** (`PageResource.php`, campo `Builder::make('blocks')`): antes `->blocks([...])` era un array estático con los 13 bloques siempre disponibles. Pasó a `->blocks(function (Get $get) { $allBlocks = [...]; ...; return $allBlocks; })` — Filament evalúa Closures con `Get $get` inyectado, mismo patrón ya usado en `->visible()` en otras partes de este archivo. Si `type` (leído del campo sibling, reactivo) es `PageTypeEnum::Footer`, se filtra `$allBlocks` a solo `image`/`cta`/`features`/`faq`/`contact_form`/`testimonials`/`logos` (vía `array_filter` + `Block::getName()`); cualquier otro `type` (`page`/`landing`/`header`/`legal`) sigue viendo la lista completa sin cambios — "Página" queda exactamente como estaba.
  - **Nuevo Content sembrado, tipo `Footer`** (`Cliente0ContentSeeder.php`, `upsertFooterPage()`): `slug: 'footer-principal'`, `type: PageTypeEnum::Footer`. El bloque CTA ("¿Listo para transformar tu negocio?") que vivía hardcodeado en `upsertHomePage()` se trasladó tal cual (mismo título/subtítulo/properties/link) a este nuevo Content — la Home ya no lo siembra.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (`Builder::make('blocks')->blocks(Closure)`), `database/seeders/Cliente0ContentSeeder.php` (nuevo método `upsertFooterPage()`, `run()` lo invoca, CTA sacado de `upsertHomePage()`).
- **IMPORTANTE — pendiente de frontend:** `cica360/src/components/Footer.astro` sigue siendo el scaffold de "Fase 2" (recibe solo `menu`, sin fetch de ningún Content). Con este cambio, el CTA sembrado YA NO aparece en ningún lado del sitio público hasta que `Footer.astro` se actualice para pedir el Content `footer-principal` (`getPage('footer-principal')`, ya existe en `api.ts`) y renderizar sus bloques — esto es trabajo de frontend nuevo, no solo "conectar", ver nota en `cica360/docs/context/TASK.md`.
- **Verificación:** revisión manual + `git diff` línea por línea contando paréntesis/llaves/corchetes en ambos archivos tocados (balance neto correcto, sin PHP en este sandbox para `php -l`/Pint/tests). Pendiente: `vendor/bin/pint --dirty`, correr el seeder, y en Studio confirmar que un Content tipo `Footer` solo ofrece los 7 bloques de la lista y que "Página" sigue con los 13.
- **Siguiente:** ~~conectar `Footer.astro`~~ — hecho en la misma sesión, ver `cica360/docs/context/PROGRESS.md` (mismo día): `BaseLayout.astro` ahora pide `getPage('footer-principal')` y se lo pasa a `Footer.astro`, que renderiza sus bloques (el CTA) con el mismo `BlockRenderer` que usan las páginas normales. El CTA vuelve a aparecer en el sitio, ahora en el footer de TODAS las páginas en vez de solo al final de la Home.

## 2026-09-01 — MediaResource: fusiona Nombre de archivo + badge de Tipo Mime bajo "Nombre", quita columna "Disco"

- **Agente/autor:** Claude, a pedido del Tech Lead: "hay que fusionar debajo de Nombre debe de estar Nombre de archivo + badge con el tipo Mime" + "quitar la columna Disco no es relevante".
- **Qué se hizo:**
  - Mismo patrón title/subtitle ya usado en `PostResource`/`PageResource` (`->description()`), pero con un giro: acá la descripción es HTML real, no texto plano — un `Illuminate\Support\HtmlString` con el `file_name` + un badge coloreado del `mime_type` (Filament renderiza `Htmlable` sin escapar dentro de `description()`, así que el `<span>` sale como HTML real, no como texto crudo). Color del badge por prefijo del mime (`image`/`video`/`application`/resto), nuevo método privado `mimeBadgeClasses()`. Las columnas `file_name` y `mime_type` como columnas propias de la tabla se eliminaron (ya viven fusionadas bajo "Nombre"); el `searchable()` de "Nombre" ahora busca en `['name', 'file_name']` para no perder la búsqueda por nombre de archivo.
  - Columna "Disco" sacada de la tabla (dato no relevante para el editor de contenido). El filtro `SelectFilter::make('disk')` se dejó intacto — sigue sirviendo para acotar la lista aunque ya no se vea el dato por fila.
- **Archivos/áreas:** `app/Filament/Resources/MediaResource.php` (`table()`, nuevo método `mimeBadgeClasses()`).
- **Verificación:** revisión manual del archivo completo (sin PHP en este sandbox para `php -l`/Pint) — sintaxis y balance de llaves/paréntesis correctos a simple vista. Pendiente: `vendor/bin/pint --dirty` y confirmación visual del Tech Lead en Studio.
- **Siguiente:** ninguno.

## 2026-09-01 — Split: labels más cortos y sin ambigüedad para los 2 colores de fondo

- **Agente/autor:** Claude, a pedido del Tech Lead: "cambiar labels que sean cortos pero sin dejar de ser más UX, para no confundir al personalizar".
- **Qué se hizo:** dos cambios de label, uno global y uno acotado a un solo call site:
  - `text_background_color` (global, en `PropertiesSchema.php` — hoy solo lo consume `split`, cambio seguro): `'Color de fondo de la columna de texto'` → `'Color de fondo del texto'`.
  - `background_color` — el label base sigue siendo `'Color de fondo'` (correcto para `cta`/`rich_text`/`testimonials`/`logos`, donde de verdad es el fondo de TODA la sección). Pero en el Fieldset "Sección" de `split` específicamente, ahora que el texto tiene su propio fondo, ese campo queda efectivamente detrás de la imagen — se relabela SOLO en ese call site a `'Color de fondo de la imagen'` (`PropertiesSchema::makeComponents(['background_color'])[0]->label(...)`, en vez de reusar el spread genérico), sin tocar el label compartido que usan los demás bloques.
- **Archivos/áreas:** `app/Filament/Schemas/PropertiesSchema.php` (label de `text_background_color`), `app/Filament/Resources/PageResource.php` (relabel puntual de `background_color` en el bloque `split`).
- **Verificación:** revisión manual (sin PHP en este sandbox). Pendiente: `vendor/bin/pint --dirty`, confirmar visualmente en Studio que los 2 labels del Fieldset "Sección" de `split` dicen "Color de fondo de la imagen" / "Color de fondo del texto", y que los demás bloques (`cta`/`rich_text`/`testimonials`/`logos`) siguen diciendo "Color de fondo" sin cambios.
- **Siguiente:** ninguno.

## 2026-09-01 — Split: nueva property `text_background_color` + sembrado en los 2 splits de la home

- **Agente/autor:** Claude, a pedido del Tech Lead con captura de referencia ("¿Qué hacemos?"): "algo que nos olvidamos en el split es que la zona de texto tiene color de fondo".
- **Qué se hizo:** `background_color` en `split` pinta toda la sección (imagen incluida); la captura pedía un fondo aparte, solo detrás de la columna de texto. Se agregó `text_background_color` a `PropertiesSchema::makeComponents()` (mismo mecanismo `ColorPicker` que `item_background_color` de `testimonials`) y se sumó a la lista de campos del Fieldset "Sección" del bloque `split` en `PageResource.php`. Se sembró `#F6F6F6` (= `cicagray-50` del Design System, corregido el mismo día — el primer intento usó `#F5F5F5`, un gris aproximado que no correspondía a ningún paso real de la escala) en los 2 bloques `split` reales de la home ("¿Qué hacemos?" y "¿A quién nos dirigimos?") en `Cliente0ContentSeeder.php`, para que ambas secciones compartan el mismo look.
- **Archivos/áreas:** `app/Filament/Schemas/PropertiesSchema.php` (nuevo componente `text_background_color`), `app/Filament/Resources/PageResource.php` (bloque `split`, Fieldset "Sección"), `database/seeders/Cliente0ContentSeeder.php` (properties de los 2 blocks `split`). Ver también `cica360/docs/context/PROGRESS.md` (mismo día) — consumo real del campo en `Split.astro`.
- **Verificación:** revisión manual (sin PHP en este sandbox). Pendiente: `vendor/bin/pint --dirty`, correr el seeder actualizado, confirmar en Filament que el campo aparece en "Personalización de estilos" > "Sección" del bloque `split`.
- **Siguiente:** ninguno — cambio autocontenido.

## 2026-09-01 — CTA: `link_radius` sembrado de `full` (pill) a `lg` (moderado)

- **Agente/autor:** Claude, a pedido del Tech Lead con captura del botón real: "no es rounded-full la expectativa".
- **Qué se hizo:** `Cliente0ContentSeeder.php` sembraba el bloque `cta` con `link_radius => 'full'` (pill completo). Se cambió a `'lg'` (`rounded-lg`, moderado) — mismo criterio que ya se había aplicado antes al botón de `rich_text`. Cambio solo del lado del seeder; el campo `link_radius` en el formulario de Filament (`PageResource.php`, dentro de `LinkSchema`/`PropertiesSchema::makeComponents(['link_radius', 'link_size'])`) ya existía y sigue permitiendo cualquiera de las 6 opciones (`xs`/`sm`/`md`/`lg`/`xl`/`full`) por página.
- **Archivos/áreas:** `database/seeders/Cliente0ContentSeeder.php` (bloque `cta`). Ver también `cica360/docs/context/PROGRESS.md` (mismo día) — el fallback en `Cta.astro` también se alineó a `lg`.
- **Verificación:** revisión manual (sin PHP en este sandbox). Pendiente: el Tech Lead debe correr `php artisan db:seed --class=Cliente0ContentSeeder` (o el flujo de seed que use) y confirmar visualmente.
- **Siguiente:** ninguno — cambio autocontenido.

## 2026-09-01 — CTA: relabel de "Subtítulo" a "Descripción breve (Opcional)" en el admin

- **Agente/autor:** Claude, a pedido del Tech Lead comparando contra el dev-mode de Figma.
- **Qué se hizo:** en el bloque `cta`, el campo que en el resto de los bloques se llama "Subtítulo" no es semánticamente un subtítulo — es una descripción breve de una línea bajo el título (confirmado por el Tech Lead). Pretítulo y esta descripción ya eran opcionales a nivel de formulario (ningún `TextInput` de `HeadingFieldset` fuera de `title` tenía `->required()`), pero no lo comunicaban explícitamente en el label. Se agregaron dos parámetros opcionales a `HeadingFieldset::make()` — `pretitleLabel`/`subtitleLabel` (ambos `null` por defecto, caen al label de siempre: `'Pre título'`/`'Subtítulo'`, cero impacto en el resto de los bloques que la llaman sin argumentos) — y el bloque `cta` los pasa como `'Pre título (Opcional)'` / `'Descripción breve (Opcional)'`. El campo sigue siendo `subtitle` en el modelo/DB (mismo patrón `block.subtitle` que consumen todos los bloques) — este es un relabel puramente de UI en Filament, sin cambio de esquema.
- **Archivos/áreas:** `app/Filament/Schemas/HeadingFieldset.php` (nuevos params `pretitleLabel`/`subtitleLabel`), `app/Filament/Resources/PageResource.php` (llamada de `HeadingFieldset::make()` dentro del bloque `cta`).
- **Verificación:** revisión manual de sintaxis (sin PHP disponible en este sandbox para `php -l`/Pint) — cambio acotado y de bajo riesgo (2 params opcionales con default `null`, un solo call site nuevo). Pendiente que el Tech Lead corra `vendor/bin/pint --dirty` y confirme visualmente en Studio que el campo dice "Descripción breve (Opcional)" en el bloque CTA.
- **Siguiente:** ver también la entrada de PROGRESS.md de `cica360` (mismo día) — la descripción breve del CTA pasó de `text-lg/text-base` (18px/16px) a `text-2xl` (24px) fijo, para calzar con el dev-mode de Figma.

## 2026-09-01 — CTA: rediseño completo (fondo color+imagen en capas, botón único, content_width) + preseteo del seeder

- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead compartió una captura de referencia (franja `cicaindigo` sólida, título+subtítulo centrados en blanco, un botón dorado en pill con ícono) y pidió rediseñar el bloque `cta` "con la experiencia que tienes en mejorar y reorganizar el formulario aplicando mas UX". Antes: `Textarea::make('content.body')` suelto (redundante con `subtitle`, el mockup no muestra una tercera línea), `LinkSchema::make()` — el Repeater multi-enlace completo para lo que en la práctica siempre fue un solo botón —, y solo 5 properties sueltas sin ninguna de imagen de fondo. Rediseño:
  1. **Un solo botón opcional**: `LinkSchema::makeSingle('links')` (mismo patrón que `rich_text`/`testimonials`) + gate `properties.show_link`, con `link_radius`/`link_size` insertados dentro del grid principal (mismo criterio que esos dos bloques).
  2. **Fondo en capas, no excluyentes**: `properties.background_color` (base) + `content.background_image_id` (`MediaUpload` opcional, capa INTERMEDIA superpuesta ENCIMA del color, no lo reemplaza) + las properties `media_*` ya genéricas (blend mode + 6 filtros CSS + opacidad, mismo set que `split`/`heading`) para que esa imagen se pueda mezclar/filtrar. Se le dio uso real por primera vez a `overlay_opacity` (ya existía en `PropertiesSchema` pero sin ningún consumidor en ningún bloque hasta hoy): un velo negro opcional encima de la imagen, debajo del texto, para legibilidad.
  3. **`content_width`** (`full`/`boxed`/`narrow`, campo genérico ya existente) sumado al bloque — mismo estándar confirmado por el Tech Lead y ya aplicado a `split`/`rich_text`/`logos`: la `<section>` SIEMPRE es fullwidth (fondo edge-to-edge sin excepción), `content_width` solo condiciona el contenedor interno de texto/botón.
  4. Se agregaron `lang_iso`/`is_visible` (Toggle) al bloque, que antes no los tenía — inconsistente con el resto de los bloques de `PageResource.php`.
  Del lado de la API: `BLOCK_MEDIA_FIELDS` de `ResolvesPublicLinks.php` suma `'cta' => ['background_image_id' => 'background_image']` para resolver la nueva imagen a un objeto `Media` público, mismo patrón que `image`/`split`. `Cliente0ContentSeeder.php` preseteado con los valores reales de la captura: `subtitle` extendido a "...a alcanzar tus objetivos" (antes más corto), `background_color: #2D2C4D` (`cicaindigo-500`, sin imagen — la captura no muestra ninguna), `text_color: #FFFFFF`, `content_width: boxed`, `padding_y: lg`, `show_link: true`, `link_radius: full`, `link_size: lg` — todos explícitos para no depender de ningún fallback, y que el seeder reproduzca la captura tal cual sin configuración manual adicional en Studio.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (bloque `cta` reescrito por completo + `$ctaLinkFields`/`$ctaLinkMainFields` nuevos). `app/Http/Concerns/ResolvesPublicLinks.php` (`BLOCK_MEDIA_FIELDS['cta']` nuevo). `database/seeders/Cliente0ContentSeeder.php` (bloque CTA de la home preseteado). Del lado de `cica360`: `src/components/blocks/Cta.astro` (reescritura completa, ver su propio PROGRESS.md).
- **Verificación:** `/tmp/php_balance.py` sobre los 3 archivos PHP — cuadra (`PageResource.php` 769/769 33/33 151/151; `ResolvesPublicLinks.php` 184/184 59/59 124/124; `Cliente0ContentSeeder.php` 74/74 17/17 123/123). Sin PHP/servidor en este sandbox — no se pudo correr el seeder ni confirmar visualmente, pendiente de confirmación del Tech Lead.
- **Siguiente:** el Tech Lead debe correr `php artisan db:seed` (o `--class=Cliente0ContentSeeder`), abrir el bloque `cta` en Studio y confirmar las 3 secciones nuevas (Fondo, Botón, Personalización de estilos), y en el sitio confirmar que la franja calza con la captura de referencia (color, texto, botón) sin tocar nada más.

## 2026-09-01 — Logos: `content.limit`/`content.order` para acotar cuántos se comparten con la API

- **Agente/autor:** Claude
- **Qué se hizo:** pedido del Tech Lead: "en el admin solo se deberia indicar cuantos se listaran en el api para poner un limite maximo de logos compartidos con el frontsite y el orden los mas recientes o los primeros". Se le preguntó explícitamente si esto ameritaba convertir `logos` en un módulo propio (tabla + Filament Resource, mismo patrón que `testimonials`) o mantenerlo como el `Repeater` inline actual sumando solo el filtro — eligió la segunda opción (menor alcance, reversible, sin migración). Implementado: 2 campos nuevos en el bloque `logos` de `PageResource.php` (`content.limit`: `TextInput` numérico opcional, 1–28, sin default = sin límite; `content.order`: `Select` con `first`/`recent`, default `first`) dentro de una Section nueva "Límite compartido con la API", entre la Galería de Logos y Personalización de estilos. Del lado de la API, `ResolvesPublicLinks::transformBlockContent()` aplica el filtro sobre `content.items` DESPUÉS de resolver los `media_id` a objetos `Media` (mismo bloque `if ($type === 'logos' ...)`, justo debajo de la resolución de `ITEMS_MEDIA_FIELD`): `order === 'recent'` invierte el array (los logos no tienen fecha propia — "más reciente" es el último agregado a la lista, asumiendo que un `Repeater` agrega al final por defecto), después `limit` recorta con `array_slice()`. Sin `content.limit` seteado no se recorta nada — mismo comportamiento que tenía el bloque antes de este campo, sin romper contenido ya sembrado (el `Repeater` en sí sigue teniendo su propio tope de `maxItems(28)`, sin cambios, es un límite distinto — cuántos se PUEDEN cargar en Studio vs. cuántos se COMPARTEN con la API).
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (bloque `logos`: Section "Límite compartido con la API" + descripción actualizada de "Galería de Logos"). `app/Http/Concerns/ResolvesPublicLinks.php` (`transformBlockContent()`: bloque `if ($type === 'logos' ...)` nuevo + docblock de clase actualizado).
- **Verificación:** `/tmp/php_balance.py` sobre ambos archivos — cuadra (`PageResource.php` 727/727 33/33 141/141; `ResolvesPublicLinks.php` 184/184 59/59 123/123). Sin PHP/servidor en este sandbox — no se pudo confirmar en vivo que la API recorte/ordene de verdad, pendiente de confirmación del Tech Lead.
- **Siguiente:** el Tech Lead debe abrir el bloque `logos` en Studio y confirmar la nueva Section "Límite compartido con la API" (cantidad + orden), guardar con un límite menor a la cantidad real de logos cargados, y confirmar que la API/sitio público solo muestra esa cantidad, en el orden elegido.

## 2026-09-01 — Logos: nueva property `content_width` (fullwidth/boxed), default fullwidth (extiende el patrón de ADR-032)

- **Agente/autor:** Claude
- **Qué se hizo:** pedido del Tech Lead: agregar al bloque `logos` una property de ancho ("fullwidth o boxed, por default fullwidth"). Se reusó el campo genérico `content_width` que ya existe en `PropertiesSchema` (mismo usado por `split`/`rich_text`, ver ADR-032) en vez de crear uno nuevo — se sumó a la llamada `PropertiesSchema::make([...])` del bloque `logos` en `PageResource.php` (sección "Personalización de estilos"). Sin `->default()` a nivel de campo (mismo motivo que `split`/`rich_text`: un `->default()` en un `Select` compartido por varios bloques solo aplicaría a bloques nuevos creados desde cero, no a los ya sembrados) — el default `full` se resuelve del lado del frontend. En `cica360`, `Logos.astro` ahora expone `properties.content_width` en su interfaz, con un mapa de 2 clases (`full`: fullwidth real con el mismo padding responsive que usa `RichText.astro` para su variante `full` — `px-6 3md:px-[80px]`, no bleed a `px-0` como `Split.astro` porque acá hay grilla de logos + heading, no una sola imagen; `boxed`: el cap de `max-w-6xl` que ya traía el bloque antes de que existiera la property, sin cambios) y cae a `full` por defecto (`properties.content_width === 'boxed' ? 'boxed' : 'full'` — cualquier otro valor, incluido `narrow` si llegara de datos legados/futuros, también cae a `full`... revisar nota abajo). El contenedor que antes tenía `max-w-6xl ... px-4 sm:px-6 lg:px-8` hardcodeado ahora usa la clase resuelta.
- **Nota de diseño:** a diferencia de `split`/`rich_text` (3 opciones: full/boxed/narrow, fallback a `boxed`), acá solo interesan 2 — se dejó el campo de Studio genérico completo (por si el Tech Lead más adelante quiere exponer `narrow` también acá), pero el frontend colapsa cualquier valor que no sea exactamente `'boxed'` a `full`.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (bloque `logos`, `content_width` sumado a `PropertiesSchema::make()`). `src/components/blocks/Logos.astro` (cica360: `content_width` en `LogosProperties`, `CONTENT_WIDTH_CLASSES`, aplicado al contenedor).
- **Verificación:** `/tmp/php_balance.py` sobre `PageResource.php` — cuadra (711/711, 33/33, 138/138, sin cambio neto). `/tmp/astro_check.py` sobre `Logos.astro` — cuadra, TAG STACK vacío y TAG ERRORS ninguno (mismo archivo que ya había dado un check 100% limpio antes en la sesión). Sin PHP/servidor en este sandbox — no se pudo confirmar visualmente el fullwidth real en el sitio, pendiente de confirmación del Tech Lead.
- **Siguiente:** el Tech Lead debe abrir el bloque `logos` en Studio ("Personalización de estilos") y confirmar que aparece el selector "Ancho del contenido"; en el sitio público, confirmar que sin tocar nada la franja de logos ya se ve fullwidth (antes era boxed a `max-w-6xl` sin excepción), y que eligiendo "Caja (Boxed)" vuelve al ancho anterior.

## 2026-09-01 — Fix real: `SliderResource` (API pública) nunca exponía `properties` — la flecha de scroll del Hero no se mostraba nunca
- **Agente/autor:** Claude
- **Qué se hizo:** El Tech Lead reportó que la flecha de scroll del Hero (`show_scroll_indicator`) nunca se mostraba en el sitio, aunque `Cliente0Seeder::upsertPlaceholderSlider()` la siembra explícitamente en `true` para el slider del home. Causa: cuando `show_scroll_indicator` se movió de "por slide" a "por Slider" (sesión anterior — ver tareas #1/#2 del historial), se actualizaron `Hero.astro`, `Cliente0HomeSlidesSeeder`/`Cliente0Seeder` y `SliderResource` de Filament, pero quedó afuera `app/Http/Resources/Api/V1/SliderResource.php` — el recurso que arma la respuesta JSON pública de `/v1/{tenant}/sliders/{slug}` — que nunca incluyó `properties` en su `toArray()` (a diferencia de `SlideResource`/`BlockResource`, que sí lo hacían desde antes). `Hero.astro` lee `slider.properties?.show_scroll_indicator`, así que siempre recibía `undefined` sin importar el valor real guardado en la base. Fix: agregado `'properties' => self::asObject($this->properties)` al `toArray()` de `SliderResource.php`, mismo patrón (`NormalizesJsonFields`) que `BlockResource`/`SlideResource`. Confirmado que el `types.ts` de cica360 ya esperaba este campo (`Slider.properties: SliderProperties`, de la misma tarea anterior) — no hizo falta ningún cambio en el frontend, el bug era puramente de la API.
- **Archivos/áreas:** `app/Http/Resources/Api/V1/SliderResource.php`.
- **Verificación:** `/tmp/php_balance.py` — balance de paréntesis/llaves/corchetes limpio (4/4, 2/2, 1/1). Sin PHP/servidor en este sandbox, no se pudo confirmar en vivo que la respuesta de la API incluya `properties` ni que la flecha aparezca en el sitio — pendiente de confirmación del Tech Lead.
- **Siguiente:** El Tech Lead debe recargar la home y confirmar que la flecha de scroll animada aparece sobre el decorador inferior del Hero. Si sigue sin aparecer después de este fix, revisar si el slider real del tenant (no el placeholder de `Cliente0Seeder`) tiene `properties.show_scroll_indicator` en `true` — puede que el slider real de contenido (`Cliente0HomeSlidesSeeder` u otro) no herede el placeholder inicial.

## 2026-09-01 — Segundo bug de la misma familia, confirmado y corregido: `Slider` (opacidad/brillo/filtros) también se corrompía al guardar (ver adenda ADR-037)
- **Agente/autor:** Claude
- **Qué se hizo:** Después del fix de `MediaUpload` (entrada de abajo), el Tech Lead reprodujo el guardado sin cambios en Home y, con el inspector del navegador, mostró que el `<img>` del bloque `split` tenía el `src` CORRECTO pero un `style` inline que lo hacía invisible: `filter: brightness(0%) saturate(0%) grayscale(0%) ... blur(0px); opacity: 0`. Nuevo bug, mismo patrón de fondo: `Forms\Components\Slider` (opacidad/brillo/saturación/contraste — usado por `PropertiesSchema` y el bloque `heading`) tiene su propio state cast (`SliderStateCast::get()`, `floatval($state)`). Como el seeder nunca escribe estas propiedades explícitamente, su valor crudo es `null` al momento de guardar, y `floatval(null)` = `0.0` — pisando el default real (`100` para brillo/opacidad/saturación/contraste) con `0`, sin importar que el slider esté configurado con `->default(100)` en Filament (ese default solo aplica al crear un registro nuevo desde cero, no al hidratar datos parciales vía `loadStateFromRelationshipsUsing`). Confirmó también que el problema se agravaba por MAMP Pro sirviendo código cacheado (opcache) — el log no mostraba requests nuevos hasta reiniciar los servidores. Fix: mapa exhaustivo `PageResource::SLIDER_PROPERTY_DEFAULTS` (cada `Slider::make('properties.X')` de la app con su default real: 100 para brillo/opacidad/saturación/contraste, 0 para escala de grises/sepia/rotación de matiz/desenfoque/overlay) + helper `backfillSliderDefaults()`, aplicado tanto al cargar (`loadStateFromRelationshipsUsing`) como al guardar (`saveRelationshipsUsing`, red de seguridad). **Verificado con datos reales**: log del guardado de confirmación del Tech Lead mostró `content.media_id` como escalar (`"4"`/`"5"`) y `properties.media_opacity`/`media_brightness`/`media_filter_saturate`/`contrast`/`brightness` en `100` — ambos fixes funcionando juntos. Mensaje final del Tech Lead: "quedó". Se retiraron los 2 `Log::info()` de diagnóstico temporal usados durante la investigación.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (`SLIDER_PROPERTY_DEFAULTS`, `backfillSliderDefaults()`, aplicado en `loadStateFromRelationshipsUsing` y `saveRelationshipsUsing`; logs de diagnóstico retirados), `docs/context/DECISIONS.md` (adenda a ADR-037).
- **Verificación:** Balance de paréntesis/llaves/corchetes (`php_balance.py`) tras cada edición — cuadra (711/711, 33/33, 138/138 en el estado final). Causa y fix confirmados con datos reales del log de producción local del Tech Lead, no solo lectura de código — igual que el bug de `MediaUpload`, esto tiene evidencia directa, no es una red de seguridad especulativa.
- **Siguiente:** Ninguno urgente — el Tech Lead confirmó que quedó resuelto. Si en el futuro se agrega un `Slider::make('properties.X')` nuevo en cualquier Resource, hay que sumarlo a `PageResource::SLIDER_PROPERTY_DEFAULTS` con su default real para no reabrir este bug — vale la pena anotarlo como convención si se formaliza `.ai/rules` más adelante.

## 2026-09-01 — Fix de causa raíz confirmada: `saveRelationshipsUsing` corrompía campos `MediaUpload` al guardar (ver ADR-037)
- **Agente/autor:** Claude
- **Qué se hizo:** Se confirmó (no solo se blindó) la causa exacta del reporte crítico del Tech Lead: guardar una página en Studio (incluso sin cambios) corrompía los campos `MediaUpload`/`FileUpload` de sus bloques (imagen del `split`, `media_id` de cada item de `logos`, etc.). Diagnóstico con evidencia real: se agregó un `Log::info()` temporal en `saveRelationshipsUsing()` que registró el valor exacto de `content.media_id` en un guardado real reproducido por el Tech Lead — `{"537a6e80-33e7-4085-8325-5b5a563fcd60":"4"}` (un array, no el escalar `"4"` esperado). Causa: el `$state` que llega a ese closure trae los campos `MediaUpload` en su forma interna cruda (keyeada por el UUID que Livewire usa para identificar el archivo dentro del widget) porque el cast que normalmente los limpia (`FileUploadStateCast::get()`) es parte del camino de deshidratación nativo de Filament (`->relationship()`), que este Builder no usa — usa `saveRelationshipsUsing` manual porque `Block` necesita lógica propia de creación/actualización/borrado con `tenant_id`/`sort_order`/tipo. El array corrupto se guardaba tal cual en el jsonb; `ResolvesPublicLinks` (ya blindado en fixes anteriores del mismo día) lo descarta por no ser escalar → la imagen se resuelve a `null` y desaparece del sitio público — pero en Studio el widget FileUpload la sigue mostrando bien, porque esa forma corrupta sigue siendo "un archivo cargado válido" para el propio widget (confirmado con capturas del Tech Lead: la miniatura se veía perfecta en el modal de edición, descartando un problema de hidratación). El bug pasó desapercibido toda la sesión porque el contenido sembrado por `db:seed` nunca pasa por este código — recién se manifestó la primera vez que se guardó una página real desde Studio, que coincidió con el trabajo sobre el bloque de partners/logos (de ahí la sospecha inicial del Tech Lead de que "modificar ese bloque" rompió algo — en realidad el bug es genérico a cualquier guardado con `MediaUpload`, y como se resguardan TODOS los bloques de la página junto, un solo guardado de Home corrompió `split` y `logos` a la vez). Fix: helper nuevo `PageResource::unwrapFileUploadState()` (recursivo, detecta la firma exacta por regex de UUID para no tocar por error objetos legítimos de una sola propiedad como `properties.background_color`), aplicado a `content`/`properties`/`links` de cada bloque antes de guardar. Efecto colateral bueno: como la corrupción tiene la misma forma que el fix sabe normalizar, el próximo guardado de una fila ya corrupta la autorepara. Se retiró el `Log::info()` de diagnóstico una vez confirmada la causa. El guard de "estado vacío" agregado horas antes (ver entrada de abajo) se mantiene, es una protección distinta y sigue siendo válida.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (nuevo `unwrapFileUploadState()`, aplicado en `saveRelationshipsUsing`; log de diagnóstico agregado y luego retirado), `docs/context/DECISIONS.md` (ADR-037), `docs/context/CURRENT_STATE.md`, `docs/context/TASK.md`.
- **Verificación:** Balance de paréntesis/llaves/corchetes (`php_balance.py`, comment/string-aware) en `PageResource.php` tras cada edición — cuadra (707/707, 32/32, 136/136 en el estado final). Causa raíz confirmada con datos reales del log de producción local del Tech Lead (`storage/logs/laravel.log`), no solo por lectura de código — a diferencia de los fixes anteriores del día, este SÍ tiene evidencia directa del bug, no es una red de seguridad especulativa. Sin PHP en este sandbox, no se pudo ejecutar el fix — pendiente de confirmación real por el Tech Lead.
- **Siguiente:** El Tech Lead debe: (1) correr `php artisan db:seed` (o al menos `Cliente0ContentSeeder`) para partir de datos limpios en los bloques que quedaron corruptos durante la reproducción de hoy; (2) abrir Home en Studio, guardar sin cambios, y confirmar en el sitio público que la imagen del bloque `split` y los logos de `partners` sobreviven; (3) si algo sigue roto después de esto, ya no es este bug — es un problema nuevo y hay que diagnosticar de cero. Pendiente de más largo plazo, sin urgencia: el dato legado corrupto original (ids no-escalares, `total:24 scalar:12` de los fixes de `ResolvesPublicLinks`) nunca se identificó a nivel de fila — sigue solo blindado, no limpiado, en la DB real.

## 2026-09-01 — Fix real: 500 en `GET /pages/home` — `ResolvesPublicLinks` blindado contra ids no-escalares
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead reportó el sitio `cica360` mostrando la pantalla de error de Astro para `ApiError` — mensaje "Error interno. Intentá de nuevo." (el fijo que devuelve `bootstrap/app.php` para cualquier 500). `storage/app/../storage/logs/laravel.log` (leído directo, este sandbox no tiene PHP pero SÍ acceso de archivo al log real de la app en la Mac del Tech Lead) tenía la causa exacta: `ErrorException: Array to string conversion at app/Http/Concerns/ResolvesPublicLinks.php:225`, dentro de `array_unique($mediaIds)` en `attachResolvedBlockContent()`, disparado por `PageController::show('cica360', 'home')` — 9 ocurrencias, todas hoy ~05:20, todas para la página `home`. `array_unique()` tira ese error cuando alguno de los elementos del array es a su vez un array (PHP intenta castearlo a string para comparar). Repasé el seeder (`Cliente0ContentSeeder`) — todos los `media_id`/`page_id`/`slider_id` que siembra son escalares (`Cliente0MediaSeeder::mediaId()` devuelve `?int`) — así que el dato corrupto no viene de ahí; es contenido YA guardado en la DB real (probablemente un bloque viejo con una forma de campo distinta a la actual, o un dato tocado a mano en Studio) al que no tengo acceso desde este sandbox (sin conexión a Postgres del Tech Lead) para identificar con precisión cuál. Decisión: en vez de perseguir el dato puntual sin poder verlo, blindé el método — nuevo helper `uniqueScalarIds()` filtra cualquier valor no-escalar antes de `array_unique()` (con `array_filter(..., 'is_scalar')`) y logea un `Log::warning()` cuando descarta algo, para dejar rastro sin romper el response completo. Aplicado a los 5 lugares del trait que arman ids para `whereIn()`: `mediaIds`/`pageIds`/`sliderIds` en `attachResolvedBlockContent()` y `pageIds`/`postIds` en `attachResolvedLinks()` (mismo riesgo, no había reportado error ahí todavía pero es el mismo patrón).
- **Archivos/áreas:** `app/Http/Concerns/ResolvesPublicLinks.php` (import de `Log`, helper `uniqueScalarIds()` nuevo, los 5 `array_unique()` reemplazados por el helper).
- **Verificación:** balance de llaves/paréntesis/corchetes comment-aware (`/tmp/php_balance.py`) — cuadra. Sin PHP interpreter en este sandbox — no se pudo reproducir el 500 real ni confirmar que desaparece. Grep sobre el seeder confirma que ningún `_id` que siembra es un array.
- **Siguiente:** ver entrada siguiente — el primer fix destapó un segundo síntoma del mismo dato corrupto.

## 2026-09-01 — Segunda vuelta del fix anterior: `resolveMediaRef()` también necesitaba el guard
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead reinició `npm run dev` de `cica360` para probar el fix de arriba y compartió la terminal: el `ErrorException: Array to string conversion` original ya NO aparece (confirmado en `storage/logs/laravel.log`, sin nuevas ocurrencias después de las 05:20:36), pero apareció un error nuevo, mismo minuto de la prueba (~05:25): `TypeError: array_key_exists(): Argument #1 ($key) must be a valid array offset type at vendor/.../Collection.php:496`. Mismo dato corrupto, síntoma distinto: `uniqueScalarIds()` ya protege el `whereIn()` (arma la Collection `$media` bien), pero `resolveMediaRef($mediaId, $media)` seguía pasando el id CRUDO (el array corrupto) directo a `$media->get($mediaId)` — `Collection::get()` hace `array_key_exists($key, ...)` por debajo, que no tolera `$key` array. Mismo patrón en `transformPublicLink()` (`$pages->get($link['source_id'])`, `$posts->get(...)`), en `transformBlockContent()` para `hero` modo slider (`$sliders->get($content['slider_id'])`) y para `services_grid` (`$pages->get($item['page_id'])`) — los 5 lugares del trait que hacen un lookup puntual (no un `whereIn()` masivo) tenían el mismo riesgo. Fix: helper nuevo `scalarOrNull()` (`is_scalar($value) ? $value : null`) envolviendo el valor crudo en los 5 `->get()`. Confirmado que `Collection::get()` maneja `null` de forma segura (`$key ??= ''`) — no hace falta el chequeo `$mediaId ? ... : null` que reemplazó.
- **Archivos/áreas:** `app/Http/Concerns/ResolvesPublicLinks.php` (helper `scalarOrNull()` nuevo, 5 `->get()` envueltos: `transformPublicLink` ×2, `transformBlockContent` ×2 — slider y services_grid page, `resolveMediaRef` ×1).
- **Verificación:** balance de llaves/paréntesis/corchetes comment-aware — cuadra. Sin PHP interpreter en este sandbox — no se pudo correr, pero el diagnóstico esta vez vino de un log real posterior al fix anterior (confirmando que el primer fix funcionó y aisló el segundo síntoma), no de una suposición.
- **Siguiente:** ver las 2 entradas siguientes — el segundo fix hizo que el backend devolviera 200 por primera vez, y destapó un tercer y cuarto síntoma del mismo dato legado, ya del lado del contrato de la API (arrays que salían como objeto JS).

## 2026-09-01 — Tercera vuelta: `content.items` podía salir como objeto JS en vez de array (keys no-secuenciales)
- **Agente/autor:** Claude
- **Qué se hizo:** con los dos fixes anteriores, `GET /pages/home` devolvió **200** por primera vez en esta sesión de debugging (terminal del Tech Lead: `00:29:07 [200] / 602ms`). Un segundo después: `(content.items ?? []).filter is not a function` en `Logos.astro:47`. Causa: un array PHP con keys no-secuenciales o no-enteras se serializa como objeto JS (`{}`), no array (`[]`) — el `content.items` guardado en DB para algún bloque (mismo dato legado de los 2 fixes anteriores) tiene esa forma, y `array_map()` (que preserva keys) la propaga tal cual al JSON de respuesta. Fix backend: `array_values()` envolviendo el `array_map()` de `ITEMS_MEDIA_FIELD` (features/logos/services_grid) y el de `services_grid`, más una red de seguridad general al final de `transformBlockContent()` — cualquier bloque con `content.items` (incluido `faq`, que este trait nunca transforma puntualmente y antes pasaba sin tocar) sale con `array_values()` aplicado sí o sí. Fix frontend en paralelo (defensa en profundidad, independiente del backend): `Logos.astro` cambia `content.items ?? []` por `Array.isArray(content.items) ? content.items : []`.
- **Archivos/áreas:** `app/Http/Concerns/ResolvesPublicLinks.php` (genesis: 2 `array_map()` envueltos en `array_values()` + guard general nuevo antes del `return $content`). `src/components/blocks/Logos.astro` (cica360: `Array.isArray()` guard).
- **Verificación:** balance PHP (comment-aware) y balance+tag-stack Astro (`/tmp/astro_check.py`) — cuadran los dos archivos. Diagnóstico de nuevo desde una terminal real del Tech Lead, no una suposición.
- **Siguiente:** ver entrada siguiente — el mismo Tech Lead reportó, en el mismo intento, un cuarto síntoma equivalente pero en `block.links`.

## 2026-09-01 — Cuarta vuelta: `block.links` tenía el mismo problema (`Collection::map()` sin `->values()`)
- **Agente/autor:** Claude
- **Qué se hizo:** screenshot del Tech Lead, mismo patrón: `block.links.map is not a function` en `Cta.astro:22`. Mismo bug que el de `content.items`, pero en `attachResolvedLinks()`: `collect($record->links ?? [])->map(fn (...) => ...)->all()` — `Collection::map()` también preserva las keys originales, así que si `$record->links` tenía keys no-secuenciales, el `->all()` final devolvía un array PHP con esas keys, serializado como objeto JS. Fix: `->values()` antes de `->all()`.
- **Archivos/áreas:** `app/Http/Concerns/ResolvesPublicLinks.php` (`attachResolvedLinks()`, un `->values()` agregado).
- **Verificación:** balance PHP comment-aware — cuadra.
- **Siguiente:** ver entrada siguiente — con la home ya sin 500, apareció un bug real DISTINTO (no relacionado al dato legado): `content.body` nunca se convertía de JSON a HTML.

## 2026-09-01 — Red de seguridad: `saveRelationshipsUsing` de `blocks` ya no puede borrar todos los bloques por un estado vacío
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead reportó algo serio: "si intento editar, pero no cambio nada, solo doy clic en guardar, o con cualquier cambio en Pages, se daña el json o borra el jsonb". Investigué el log real (`storage/logs/laravel.log`) — el `Log::warning` de `uniqueScalarIds()` (fix de ids no-escalares, más arriba) sigue mostrando el MISMO conteo estable (`total:24, scalar:12`) en 8 requests distintos a lo largo de 12 minutos — descarta que el guardado esté generando MÁS corrupción de ids progresivamente. No pude confirmar el mecanismo EXACTO del daño (sin PHP/DB en este sandbox para reproducir un guardado real), pero encontré un patrón genuinamente peligroso en `saveRelationshipsUsing` del `Builder` de bloques: `$record->blocks()->whereNotIn('id', $existingBlockIds)->delete();` al final — si `$state` (el estado del Builder al momento de guardar) llega vacío por CUALQUIER motivo (glitch de Livewire, timing, lo que sea — no pude confirmar la causa raíz), `$existingBlockIds` queda `[]` y esa línea borra TODOS los bloques de la página, sin importar que el usuario no haya tocado nada. Un guardado nunca debería poder destruir contenido real de esta forma. Fix: guard al principio del closure — si `$state` viene vacío y la página YA tiene bloques guardados, se aborta el guardado de bloques (se dejan los existentes intactos) y se logea un `Log::warning()` con el id/slug de la página, en vez de proceder a borrar.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (import de `Log`, guard nuevo al inicio de `saveRelationshipsUsing` del Builder de bloques).
- **Verificación:** balance de llaves/paréntesis/corchetes comment-aware — cuadra. Sin PHP interpreter en este sandbox — no se pudo reproducir el guardado real ni confirmar que este guard evita el problema reportado. **Esto es una red de seguridad, no necesariamente el fix completo** — protege contra el peor caso (pérdida total de bloques) pero no explica con certeza por qué `$state` llegaría vacío en primer lugar, ni descarta que el daño reportado sea algo más puntual (ej. un campo `content.body` específico llegando vacío en vez de la lista completa de bloques).
- **Siguiente:** el Tech Lead debe reproducir una vez más el guardado sin cambios y reportar: (1) si el problema desapareció por completo con este guard; (2) si sigue viendo algo, compartir qué campo/bloque específico queda dañado (ideal: el valor de `content` de un bloque en la DB, antes y después del guardado) y revisar `laravel.log` justo después del guardado por si aparece el nuevo warning `PageResource: saveRelationshipsUsing de "blocks" recibió estado vacío...` — eso confirmaría que el guard se activó (bloques salvados) y daría una pista real de la causa raíz para investigar a fondo.

## 2026-09-01 — Fix real: `content.body` salía como JSON TipTap crudo, nunca se convertía a HTML (`[object Object]` en pantalla)
- **Agente/autor:** Claude
- **Qué se hizo:** con la home ya devolviendo 200, el Tech Lead compartió un screenshot del sitio real: en vez del texto de cada bloque `rich_text` (y del cuerpo del Hero), la pantalla mostraba literalmente `[object Object]`. Causa, sin relación con los 4 fixes anteriores de esta sesión (ids/keys corruptos) — un bug real distinto, preexistente, que nunca se había visto porque la home nunca había renderizado completa hasta ahora: `Forms\Components\RichEditor::make('content.body')` (usado en `rich_text`, `split`, `legal_notice`, y `answer` de cada item de `faq`) en Filament 5 guarda el contenido como documento TipTap/ProseMirror — JSON estructurado (`{"type":"doc","content":[...]}`), no HTML — pero el frontend (`RichText.astro`, `Split.astro`) siempre esperó `content.body` como un string de HTML listo para `set:html`. Nunca hubo una conversión en el medio: `content.body` salía tal cual (el objeto JSON) en la respuesta de la API, y el navegador lo stringifica a `"[object Object]"` al asignarlo como HTML. Fix: helper nuevo `renderRichContent()` en `ResolvesPublicLinks`, usando `Filament\Forms\Components\RichEditor\RichContentRenderer` — el conversor OFICIAL de Filament (usa `ueberdosis/tiptap-php`, ya en `vendor/`, sin instalar nada nuevo) que convierte el JSON a HTML sanitizado (`Str::sanitizeHtml()`, protege contra XSS). Si el valor YA es un string (ej. `cta` usa `Forms\Components\Textarea::make('content.body')`, plano, no JSON), se devuelve tal cual sin tocar — el helper nunca reprocesa un string como si fuera JSON. Aplicado a `content.body` de CUALQUIER bloque (genérico, no por tipo) y a `items[].answer` de `faq` (mismo campo, mismo problema, un nivel más adentro).
- **Archivos/áreas:** `app/Http/Concerns/ResolvesPublicLinks.php` (import de `RichContentRenderer`, helper `renderRichContent()` nuevo, aplicado a `content.body` genérico y a `faq.items[].answer`).
- **Verificación:** balance de llaves/paréntesis/corchetes comment-aware — cuadra. Sin PHP interpreter en este sandbox — no se pudo correr `RichContentRenderer` real ni confirmar el HTML de salida.
- **Siguiente:** el Tech Lead debe recargar la home y confirmar que "¿Qué hacemos?"/"¿A quién nos dirigimos?" (y cualquier otro `rich_text`/`split`/`legal_notice`/`faq`) muestran el texto real, no `[object Object]`. Revisar también que las negritas/listas del contenido (marks `bold`, nodos `bulletList`, etc.) se vean bien — el HTML que produce `RichContentRenderer` debería calzar con las clases `.richtext-body` ya definidas en `RichText.astro`/`Split.astro` (mismos tags `<p>`/`<strong>`/`<ul>`/`<li>` que ya se estilaban ahí), pero no se pudo confirmar visualmente desde este sandbox. Con esto, los 5 fixes de hoy sobre `ResolvesPublicLinks.php` deberían dejar la home (y el resto del sitio) completamente funcional.

## 2026-09-01 — Regla "Repeaters collapsed by default" aplicada de verdad (antes solo auditada)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead había pedido "los sections dentro de cualquier repeat tiene que ser collapsed by default" (sesión anterior); en ese momento audité el codebase buscando `Section::make()` anidado dentro de un `Repeater` y no encontré ninguno, así que reporté "nada que corregir". Eso fue una lectura incompleta de la intención real — el Tech Lead lo confirmó de nuevo señalando el propio bloque `logos` (captura: "Empresa asociada 1"/"Empresa asociada 2" con flecha de colapso, ambas expandidas por default): el problema no era `Section` dentro de `Repeater`, era que los **`Repeater` mismos** (`->collapsible()`) no traían `->collapsed()`, así que cada item se renderiza expandido la primera vez que se abre el formulario — exactamente lo que la regla quería evitar. Corregido en TODOS los `Repeater`s de la app que tenían `->collapsible()` sin `->collapsed()`: bloque `logos` (`PageResource.php`, el que motivó el reporte), `features` (Características), `faq` (Preguntas Frecuentes), `services_grid`/Grid de servicios, `LinkSchema::make()` (compartido por CTA/Hero manual/testimonials/etc. — un solo fix cubre todos sus usos), y en `ServiceResource.php` los Repeaters de "¿Qué ofrecemos?" y "Coberturas". `MenuResource.php` y `SliderResource.php` ya cumplían (`->collapsed()`/`->collapsed(true)` ya estaban puestos) — no se tocaron.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (4 Repeaters: logos, features, faq, services_grid), `app/Filament/Schemas/LinkSchema.php` (1 Repeater compartido), `app/Filament/Resources/ServiceResource.php` (2 Repeaters: offers, coverages). Dejados sin tocar a propósito: `Section::make(...)->collapsible()` de nivel superior (no anidados dentro de un Repeater — ej. "Diseño de la sección" en Hero, "¿Por qué elegirnos?"/"Tip de ayuda" en Services) — la regla es sobre repeats, no sobre toda `Section` colapsable de la app.
- **Verificación:** balance de llaves/paréntesis/corchetes comment-aware (`/tmp/php_balance.py`) sobre los 3 archivos tocados — cuadra en los tres. Sin PHP interpreter en este sandbox — no se pudo confirmar visualmente en Studio.
- **Siguiente:** el Tech Lead debe confirmar en Studio que los Repeaters de `logos`/`features`/`faq`/`services_grid`/CTA-links/`ServiceResource` (offers/coverages) ahora arrancan colapsados al abrir el formulario. Esta regla queda como estándar para cualquier `Repeater` nuevo: siempre `->collapsible()->collapsed()` salvo pedido explícito en contra.

## 2026-09-01 — Fix real: 500 al editar/reordenar bloques de una página (`sort_order` recibía un UUID)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead reportó un `Illuminate\Database\QueryException` (`SQLSTATE[22P02]: invalid input syntax for type integer`) al guardar Contenidos en `/cica360/pages` — Postgres rechazaba un UUID (`9b5abef3-...`) como valor de la columna `sort_order` (integer) de `blocks`. Causa real en `PageResource.php`, `saveRelationshipsUsing` del `Builder` de bloques: `foreach ($state as $index => $blockData) { ... 'sort_order' => $index ... }` — el `Builder` de Filament 5 (a diferencia de `Repeater`) keyea su array de estado por el ID interno de Livewire de cada item (un string tipo UUID), no por una posición secuencial. Mientras las keys "parecían" enteros pequeños (0,1,2 por casualidad del orden de creación) el bug quedaba invisible; en cuanto un bloque se reordenó/editó de forma que Livewire le asignó una key con forma de UUID real, `$index` dejó de ser castable a integer y Postgres lo rechazó de plano (antes probablemente fallaba silenciosamente como *string numérico* válido, o nunca se había dado el caso). Fix: `foreach (array_values($state) as $index => $blockData)` — reindexa a 0,1,2... preservando el orden real en pantalla, sin depender de las keys internas del componente.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (una línea, `saveRelationshipsUsing` del Builder de bloques).
- **Verificación:** balance de llaves/paréntesis/corchetes comment-aware (`/tmp/php_balance.py`) — cuadra. Grep sobre el resto de `app/Filament/Resources/*.php` confirma que es el único lugar con este patrón (`MenuResource` y demás Repeaters no lo tienen — usan `orderColumn()` nativo de Filament, que sí maneja la posición correctamente por debajo). Sin PHP interpreter en este sandbox — no se pudo correr `php artisan migrate`/probar el guardado real.
- **Siguiente:** el Tech Lead debe confirmar en Studio que editar/reordenar/duplicar bloques de una página ya no tira el 500 — probar puntualmente reordenar por drag-and-drop (el caso que más probablemente generaba keys "no numéricas") y guardar.

## 2026-09-01 — Bloque `logos`: ampliado de 7 a 10 placeholders para probar el carousel real
- **Agente/autor:** Claude
- **Qué se hizo:** pedido explícito del Tech Lead ("generar 10 logos de partners de ejemplo en seeder"). Con exactamente 7 items el bloque `logos` de la home solo mostraba la grilla estática de una página — el modo carousel de `Logos.astro` (paginado de a 7, flechas/dots/drag) nunca se probaba en la práctica. Se generaron 3 placeholders nuevos (`cica360_media_logo_8/9/10.png`, 128×128, transparente) con Python/Pillow, en el mismo estilo visual que los 7 existentes (badge blanco translúcido + glifo abstracto gris + subrayado, sin nombre de marca real — son placeholders, el Tech Lead los reemplaza cuando tenga logos reales de socios). `Cliente0MediaSeeder::FILES` gana `logo_8`/`logo_9`/`logo_10`; `Cliente0ContentSeeder` extiende `content.items[]` del bloque `logos` de la home de 7 a 10 entradas. Con 10 > 7, `Logos.astro` ahora sí pagina en 2 páginas (7 + 3) apenas se corra el seeder — primera vez que el modo carousel del bloque se ejercita con datos reales sembrados (antes solo se había verificado por revisión de código).
- **Archivos/áreas:** `storage/app/public/media/cica360_media_logo_8.png`, `_9.png`, `_10.png` (nuevos, commiteados). `database/seeders/Cliente0MediaSeeder.php` (3 entradas nuevas en `FILES`). `database/seeders/Cliente0ContentSeeder.php` (bloque `logos` de la home, 3 items nuevos en `content.items[]`).
- **Verificación:** balance de llaves/paréntesis/corchetes (comment/string-aware, `/tmp/php_balance.py`) sobre ambos seeders — cuadra en los dos. Sin PHP interpreter en este sandbox (`php -l` no disponible) — no se pudo correr lint real ni el seeder. `Repeater::maxItems(28)` de `PageResource.php` sigue sin tocarse — 10 está cómodo bajo el tope.
- **Siguiente:** el Tech Lead debe correr `php artisan db:seed --class=Cliente0MediaSeeder --class=Cliente0ContentSeeder` (o el flujo completo) para sembrar los 3 logos nuevos y confirmar visualmente en `/` que el carousel de "Empresas con las que trabajamos" ahora pagina (7 + 3) con flechas/dots/drag funcionando.

## 2026-08-31 — Bloque `logos`: 7 logos reales sembrados + filtro grayscale/opacidad + tope de 28 items
- **Agente/autor:** Claude
- **Qué se hizo:** el bloque `logos` ("Empresas con las que trabajamos") nunca mostraba nada en el sitio real — `Cliente0ContentSeeder` lo sembraba con 5 items placeholder con `media_id: null`, y el frontend descarta en silencio cualquier item sin media. El Tech Lead subió 7 archivos reales (`cica360_media_logo_1.png`...`_7.png`, ya commiteados) y pidió terminar la integración: (1) `Cliente0MediaSeeder` gana las 7 entradas nuevas (mismo patrón de 2 pasos ya establecido: archivo commiteado + `firstOrCreate` por path); (2) `Cliente0ContentSeeder` reemplaza los 5 placeholders `null` por los 7 `media_id` reales (vía `Cliente0MediaSeeder::mediaId()`), agrega `subtitle` (el bloque nunca lo había tenido) y `properties.media_filter_grayscale => 100`/`media_opacity => 60` (filtro por defecto). (3) Filtro configurable pedido: NO se crearon properties nuevas — `media_filter_grayscale`/`media_opacity` ya existían como properties genéricas reusables (mismo set agregado para `split` el mismo día), solo se sumaron al `PropertiesSchema::make([...])` del bloque `logos` en `PageResource.php`, ahora agrupadas en su propia Section colapsable "Personalización de estilos" a 2 columnas (antes eran 2 campos sueltos sin agrupar). (4) Pregunta del Tech Lead "cuánto brands como máximo listará el api": no hay un mecanismo de límite tipo `testimonials` acá (el bloque `logos` es un `Repeater` autocontenido en `content.items[]`, no una tabla resuelta en runtime) — se agregó `->maxItems(28)` al `Repeater` (4 páginas completas de 7, ver criterio "de 7 en 7" del frontend) como tope explícito de UX/consistencia, documentado en el código. (5) `->description()` nueva en la Section "Galería de Logos" explicando el comportamiento del frontend (7 columnas por página, carousel con más de 7) para que quede claro desde Studio sin tener que mirar el código del sitio.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (bloque `logos`: Section "Galería de Logos" con `description()` + `maxItems(28)`, nueva Section "Personalización de estilos" a 2 columnas), `database/seeders/Cliente0MediaSeeder.php` (7 logos nuevos), `database/seeders/Cliente0ContentSeeder.php` (bloque `logos` de la home: `media_id`s reales, `subtitle`, `properties`).
- **Verificación:** checker PHP-aware en Python confirma balance en los 3 archivos tocados. Sin migración nueva (properties genéricas dentro del jsonb `properties` ya existente).
- **Siguiente:** el Tech Lead debe correr `php artisan db:seed` (o el flujo completo) para que los 7 logos y el subtítulo tomen efecto. El resto de la implementación (carousel paginado de a 7, filtro CSS con hover a color real) vive en `cica360` — ver su `PROGRESS.md`.

## 2026-08-31 — Bloque `testimonials`: property `item_background_opacity` + layout a 2 columnas en "Personalización de estilos" y en el enlace "Ver más"
- **Agente/autor:** Claude
- **Qué se hizo:** 3 ajustes de UX sobre el bloque `testimonials`, a pedido del Tech Lead con capturas del form real. (1) `Grid` de "Página superior/Estado/Fecha de publicación" en `PageResource` (Configuración de `pages`) — esto era sobre `pages`, no `testimonials`, ver nota abajo. (2) Los 4 campos del enlace "Ver más" (Estilo/Texto/Tipo de origen/Destino) pasan de `Grid::make(3)` a `Grid::make(2)`. (3) La Section "Personalización de estilos" del bloque `testimonials` pasa de 1 columna (todo apilado) a 2 (`->columns(2)` en el `Group` que devuelve `PropertiesSchema::make()`). (4) Nueva property `item_background_opacity` (`Forms\Components\Slider`, 0–100, default 30, mismo patrón que `overlay_opacity`) — el % de opacidad del fondo de cada tarjeta (`item_background_color`) ya NO está fijo en código (30%/50% hardcodeado en `Testimonials.astro`), ahora es configurable desde Studio; el hover sigue subiendo +20 puntos automáticamente (tope 100%), sin campo propio para eso — consumido del lado de `cica360` vía nuevas custom properties CSS `--item-bg-opacity`/`--item-bg-hover-opacity`.
- **Archivos/áreas:** `app/Filament/Schemas/PropertiesSchema.php` (`item_background_opacity` nuevo), `app/Filament/Resources/PageResource.php` (bloque `testimonials`: Grid del enlace a 2 columnas, `Personalización de estilos` a 2 columnas + incluye la property nueva; y por separado, bloque `pages`: `parent_id`+`Estado`+`Fecha de publicación` unificados en un solo `Grid::make(3)`).
- **Verificación:** checker PHP-aware en Python confirma balance en ambos archivos.
- **Siguiente:** ninguno — no requiere migración (jsonb existente) ni re-seed (el default de 30% aplica solo con el helper `?? 30` del lado del frontend si la property no está seteada).

## 2026-08-31 — Bloque `testimonials`: property `item_background_color` + colores/subtítulo reales en el seeder (corrección "expectativa vs realidad" del Tech Lead)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead mandó 2 capturas (mockup vs. lo real en CICA360) señalando diferencias de diseño en la sección "Casos de éxito". Del lado de `genesis`, lo que hacía falta: (1) property nueva `item_background_color` (`App\Filament\Schemas\PropertiesSchema`, `ColorPicker`) — fondo de CADA tarjeta de testimonio, independiente del fondo de la sección (`background_color`, ya existía); agregada al schema de "Personalización de estilos" del bloque `testimonials` en `PageResource.php`. (2) Se confirmó que el resto de los pedidos YA estaban implementados del lado de `genesis` sin necesitar cambios: el admin ya podía personalizar `pretitle`/`title`/`subtitle` del bloque (`HeadingFieldset::make()` con sus defaults), el botón "Más casos de éxito" ya apuntaba a la página interna real (`Cliente0ContentSeeder::upsertHomePage()` linkea a `pages['casos-de-exito']`), y `ResolvesPublicLinks::transformBlockContent()` ya recortaba/ordenaba los testimonios según el `content.limit`/`content.order` propio de CADA bloque (no un límite global). (3) `Cliente0ContentSeeder` actualizado: `background_color` pasa de un teal ad-hoc (`#2b7c89`) a `cicagreen-500` real (`#206576`, tomado de `cica360/src/styles/global.css`), se agrega `item_background_color` = `cicagreen-400` (`#4D919E`) en los 2 bloques `testimonials` sembrados (home y la página `casos-de-exito`), y se agrega `subtitle` ("Conectamos conocimientos, potenciamos decisiones.") al bloque de la home — el subtítulo YA se renderizaba del lado del frontend, el gap real era que nunca se había sembrado. El resto del rediseño visual (avatares más grandes, tarjetas con fondo propio, cita sin comillas, firma en una línea, carousel con swipe táctil para más de 3 testimonios) vive del lado de `cica360` — ver su `PROGRESS.md`.
- **Archivos/áreas:** `app/Filament/Schemas/PropertiesSchema.php`, `app/Filament/Resources/PageResource.php` (bloque `testimonials`), `database/seeders/Cliente0ContentSeeder.php` (`upsertHomePage()`/`upsertCasosDeExitoPage()`).
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python confirma balance de paréntesis/llaves/corchetes en los 3 archivos tocados. Sin migración nueva — `item_background_color` es solo una clave nueva dentro del jsonb `properties` ya existente, no requiere `php artisan migrate`, solo re-sembrar contenido.
- **Siguiente:** el Tech Lead debe correr `php artisan db:seed --class=Cliente0ContentSeeder` (o el flujo de seed completo) para que los 2 bloques `testimonials` ya sembrados tomen los colores/subtítulo nuevos — es `updateOrCreate`, seguro re-ejecutarlo. Confirmar visualmente contra el mockup una vez corrido.

## 2026-08-31 — Árbol de `pages` hasta 3 niveles (`parent_id`), solo organización en Studio — ver ADR-036
- **Agente/autor:** Claude
- **Qué se hizo:** pedido del Tech Lead reusando la UI de jerarquía de `MenuResource` (implementada más temprano el mismo día) como referencia: "como podemos hacer para armar arbol tree de navegacion hasta en 3 niveles, cuando una pagina tenga parent a otra pagina". Se preguntó explícitamente si el árbol debía anidar también la URL pública o quedarse solo como organización en Studio — el Tech Lead confirmó **"Solo organización (recomendado)"**. Implementado: (1) migración nueva `2026_08_31_000006_add_parent_id_to_pages_table.php` — `parent_id` autorreferenciado, nullable, `foreignId('parent_id')->constrained('pages')->nullOnDelete()`, índice compuesto `[tenant_id, parent_id]`; (2) `App\Models\Page` — `parent_id` agregado a `#[Fillable]`, relaciones nuevas `parent(): BelongsTo` / `children(): HasMany` (mismo patrón ya usado en `MenuItem`), método `depth(): int` (sube por `parent` hasta 2 veces, con guard defensivo); (3) `PageResource` — nuevo `Select::make('parent_id')` en el tab "Configuración" (justo después de `HeadingFieldset`), con `options()` que excluye la página misma (al editar), todos sus descendientes (vía nuevo helper privado `descendantPageIds()`, evita ciclos) y cualquier página que ya esté en profundidad 2 (evita un 4to nivel); `table()` ahora hace eager-load de `parent.parent` y ordena raíz-primero (`orderByRaw('parent_id IS NOT NULL')` + `orderBy('title')`), y la columna `title` indenta visualmente con `str_repeat('— ', $record->depth())` — se le quitó `->sortable()` para no romper ese orden agrupado con un clic de header.
- **Archivos/áreas:** `database/migrations/2026_08_31_000006_add_parent_id_to_pages_table.php` (nuevo), `app/Models/Page.php`, `app/Filament/Resources/PageResource.php`, `docs/context/DECISIONS.md` (ADR-036 nuevo).
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python confirma balance de paréntesis/llaves/corchetes en los 3 archivos tocados (`Page.php`: 19/19 paren, 11/11 brace, 3/3 bracket; migración: 13/13 paren, 5/5 brace, 1/1 bracket; `PageResource.php` completo: 673/673 paren, 25/25 brace, 131/131 bracket). Sin cambios en `ResolvesPublicLinks`, la API pública, ni la unicidad de `slug` (`[tenant_id, lang_iso, slug]` intacta) — confirmado por revisión manual, ningún archivo de esos tocado.
- **Siguiente:** el Tech Lead debe correr `php artisan migrate` para aplicar la columna `parent_id` antes de que el campo funcione en Studio. Gap conocido, no resuelto acá: el `Toggle::make('is_home')` del FORM (`HeadingFieldset`) todavía no impide que dos páginas del mismo tenant queden marcadas como home a la vez (esa protección solo se agregó al toggle de la TABLA en una entrada anterior).

## 2026-08-31 — `SliderResource`: columna `Activo` clickeable tipo toggle, mismo ícono en los dos estados (mismo criterio que `is_home`)
- **Agente/autor:** Claude
- **Qué se hizo:** pedido del Tech Lead extendiendo el mismo criterio de `is_home` (PageResource) a "todas las columnas Activo" — se confirmó por grep que `SliderResource` es la ÚNICA columna de tabla en toda la app etiquetada "Activo" (`MenuResource` tiene el mismo label pero solo como campo de FORM dentro del Repeater anidado de items, no como columna de tabla). Mismo patrón: `->trueIcon()`/`->falseIcon()` al mismo heroicon de check, `->falseColor('gray')` en vez del `danger` por defecto, `->action()` colgado de la `IconColumn` para togglear `is_active` con un clic. A diferencia de `is_home`, acá NO hay regla de "uno solo a la vez" — cualquier cantidad de sliders puede estar activa simultáneamente, así que el toggle es un simple flip sin lógica adicional.
- **Archivos/áreas:** `app/Filament/Resources/SliderResource.php` (columna `is_active`).
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python confirma balance. Grep confirma que no queda ninguna otra columna "Activo" sin actualizar.
- **Siguiente:** ninguno.

## 2026-08-31 — `PageResource`: columna `is_home` clickeable tipo toggle, mismo ícono en los dos estados
- **Agente/autor:** Claude
- **Qué se hizo:** pedido del Tech Lead — en vez de tilde verde / X roja para "Inicio" (`is_home`), mostrar el MISMO ícono de check en los dos estados (gris "apagado", verde "prendido") y que sea clickeable, activando la página como Home directo desde la tabla sin abrir el form. Se usó `Column::action()` (disponible en cualquier columna, no solo `ToggleColumn` — que además se ve como un switch, no como este ícono) colgado de la `IconColumn` existente: `->trueIcon()`/`->falseIcon()` al mismo heroicon, `->falseColor('gray')` en vez del `danger` por defecto de `->boolean()`. La acción del clic, además, hace cumplir la regla de negocio implícita de que solo puede haber 1 página Home por tenant a la vez (no estaba enforced en ningún lado antes — ni en el `Toggle` del form, ni a nivel de modelo/observer — así que antes de este cambio técnicamente se podían marcar 2+ páginas como Home sin que nada lo impidiera): al activar una, desactiva cualquier otra que ya estuviera marcada.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (columna `is_home`).
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python confirma balance.
- **Siguiente:** el `Toggle::make('is_home')` del FORM (`HeadingFieldset.php`) sigue sin la misma protección de "solo 1 a la vez" — si el Tech Lead lo marca desde ahí en vez de la tabla, puede volver a duplicarse. Si hace falta, aplicar la misma regla ahí (ej. `afterStateUpdated` que desmarque las demás), no se tocó en esta vuelta por no ser lo pedido.

## 2026-08-31 — `PageResource`: título+slug+tipo fusionados en 1 columna
- **Agente/autor:** Claude
- **Qué se hizo:** mismo pedido de fusión ya aplicado a Servicios/Testimonios/Publicaciones, ahora en Páginas: "de igual forma, poner debajo del titulo: el slug de la pagina - Tipo de contenido". Se eliminaron las columnas separadas `slug` y `type` del listado; la columna `title` queda con el título en negrita arriba y `"{slug} - {Tipo}"` en gris debajo (vía `->description()`), resolviendo el label de `PageTypeEnum` con `$record->type?->getLabel()`.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (`table()`).
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python confirma balance.
- **Siguiente:** ninguno.

## 2026-08-31 — Fechas amigables en todos los listados + formato absoluto `d:m:Y h:i a` (extiende ADR-021)
- **Agente/autor:** Claude
- **Qué se hizo:** pedido del Tech Lead — "en todos los listados la fechas que sean amigables para humanos y si hay que mostrar fecha que muestre d:m:Y h:i a". `App\Support\FriendlyDate` ya existía desde ADR-021 (relativo tipo "hace 5 min" para fechas de menos de 28 días, absoluto para el resto) pero solo se había aplicado a `ApiTokens`, y el formato absoluto era un shape hand-rolleado por idioma (`17 Ago 12:25 am`, con mapa de meses abreviados es/en/pt) — quedaba pendiente extenderlo al resto de Console (anotado explícitamente como pendiente en ADR-021 punto 3). Cambios: (1) `FriendlyDate::absolute()` reemplazado por el formato fijo pedido `d:m:Y h:i a` (numérico, sin depender del idioma) — se eliminó el mapa `MONTHS` que ya no hace falta, código más simple. (2) `PageResource`/`PostResource`: la columna `published_at` pasó de `->dateTime()` (timestamp crudo de Filament) a `->formatStateUsing(fn ($state) => FriendlyDate::format($state))`, mismo patrón que `ApiTokens`. Revisado el resto de los Resources de Filament (`TestimonialResource`, `ServiceResource`, `MenuResource`) — ninguno muestra una columna de fecha en su tabla (solo `ServiceResource` tiene `published_at` como campo de FORM, no de listado), así que con estos 2 cambios quedan cubiertas TODAS las columnas de fecha visibles en algún listado de Console.
- **Archivos/áreas:** `app/Support/FriendlyDate.php` (formato absoluto simplificado), `app/Filament/Resources/PageResource.php` y `PostResource.php` (columna `published_at`). Actualización en ADR-021 (`DECISIONS.md`).
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python confirma balance en los 3 archivos.
- **Siguiente:** confirmar visualmente en Studio que `published_at` en Pages/Posts se ve relativo para contenido reciente y `d:m:Y h:i a` para el resto; si en el futuro se agrega alguna columna de fecha nueva a cualquier Resource, usar `FriendlyDate::format()` desde el vamos en vez de `->dateTime()` crudo.

## 2026-08-31 — `PostResource`: título+slug fusionados, `Tenant::publicUrl()` nuevo (sin usar por ahora — el Tech Lead prefirió texto plano)
- **Agente/autor:** Claude
- **Qué se hizo:** pedido del Tech Lead — mismo patrón de fusión ya aplicado a Testimonios/Servicios para la columna `title`+`slug` de Publicaciones. Primera vuelta: el slug como LINK real a la URL pública completa — "que muestre el slug, pero como link que tenga la url completa" → "la url completa se saca del dominio de la app [tenant] + /blog/ + slug" → "recuerda que estamos frente a un servicio multitenant" (el dominio no puede ser fijo, se resuelve por el tenant dueño de cada post, vía la tabla `domains` ya existente — `tenant_id`/`domain`/`is_primary`, sin migración nueva). Se agregaron 2 helpers en `Tenant`: `primaryDomain(): ?Domain` y `publicUrl(string $path = ''): ?string` (`https://{dominio}/{path}`, `null` si el tenant no tiene dominio cargado — a propósito sin fallback a un dominio hardcodeado, por la "regla de oro" de ARCHITECTURE.md §4). Segunda vuelta, el mismo día: el Tech Lead se lo pensó de nuevo y pidió texto plano, SIN hipervínculo — "que muestre debajo URL: /blog/[slug] sin hipervinculo". Revertido el `->url()`/`->openUrlInNewTab()` de la columna; queda solo `->description()` con el texto `"URL: /blog/{slug}"`. Los helpers de `Tenant` se dejaron en el modelo (no rompen nada, sin uso actual) por si hace falta un link real en otro lugar más adelante.
- **Archivos/áreas:** `app/Models/Tenant.php` (+`primaryDomain()`, +`publicUrl()`, sin consumidor actual), `app/Filament/Resources/PostResource.php` (columna `title` fusionada con `description()` de texto plano, elimina la columna `slug` separada).
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python confirma balance en los 2 archivos.
- **Siguiente:** ninguno urgente — quedó en el estado final pedido. Si en el futuro se quiere el link real, ya está `Tenant::publicUrl()` listo para reengancharlo en la columna (recordar cargar el dominio real de CICA360 en `domains` con `is_primary = true` primero, todavía no está cargado).

## 2026-08-31 — `MenuResource`: jerarquía de 3 niveles con drag-to-sort propio por nivel (menú/submenú/sub-submenú)
- **Agente/autor:** Claude
- **Qué se hizo:** pedido del Tech Lead — "quiero hacer sort pero hasta 3 niveles, menu, sub menu y sub-submenu". El form de `MenuResource` tenía un único `Repeater` PLANO ligado a `Menu::items()` (todos los items del menú, sin distinguir nivel) con un `Select::make('parent_id')` manual para elegir el padre — funcional pero sin jerarquía visual, y el drag-to-sort mezclaba todos los niveles en una sola lista. El esquema de `menu_items` ya soportaba jerarquía real (`parent_id` autorreferenciado, índice `[menu_id, parent_id, sort_order]`, y los modelos `Menu::rootItems()`/`MenuItem::children()` ya existían) — no hizo falta ninguna migración. Se reemplazó el `Repeater` plano por 3 `Repeater`s ANIDADOS recursivamente (método `MenuResource::menuItemFields(int $depth)`, reutiliza los mismos campos en los 3 niveles): nivel 1 ligado a `Menu::rootItems()`, nivel 2 y 3 ligados a `MenuItem::children()` del item padre — cada uno con su propio `->orderColumn('sort_order')`, así el drag-to-sort de un submenú reordena SOLO sus hermanos directos, no toca los demás niveles. El `Select` de `parent_id` se eliminó: la jerarquía ahora es estructural (la posición del item dentro del Repeater anidado define el padre), Filament la resuelve sola al guardar.
- **Archivos/áreas:** `app/Filament/Resources/MenuResource.php` (`form()` reescrito + nuevo método `menuItemFields()`). Sin cambios de modelo ni migración — `Menu::rootItems()`/`MenuItem::children()` ya existían.
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python confirma balance de paréntesis/llaves/corchetes. Revisado el mecanismo interno de Filament (`Repeater::relationship()`/`saveToRelationship()`/`orderColumn()`) para confirmar que Repeaters anidados con relación propia en cada nivel resuelven su registro padre correctamente vía el contexto del item contenedor — patrón estándar de Filament, no requiere configuración extra.
- **Siguiente:** el Tech Lead debe confirmar visualmente en Studio: crear un item de nivel 1, agregarle un sub-elemento (nivel 2), agregarle un sub-sub-elemento (nivel 3), reordenar dentro de cada nivel por separado, guardar y volver a abrir para confirmar que la jerarquía persiste.

## 2026-08-31 — Fix real: `Error` al convertir `CountryEnum` a string + modal de `ServiceResource` reducido a la mitad (extiende ADR-035)
- **Agente/autor:** Claude
- **Qué se hizo:** el fix anterior de `Service::sanitizeCountries()` (que asumía que Filament siempre entrega strings crudos) seguía rompiendo, ahora con `Error: Object of class App\Enums\CountryEnum could not be converted to string` en `Service.php:64`. Causa real: cuando el `Select` de Filament usa `->options(CountryEnum::class)`, Filament hidrata el estado del campo con INSTANCIAS de `CountryEnum`, no con los strings crudos guardados en la fila — `(string) $enumInstance` rompe porque un Enum de PHP no implementa `__toString()`. Fix: `sanitizeCountries()` ahora distingue los dos casos (`$code instanceof CountryEnum` → usa `->value`; si no, castea a string) antes de normalizar. Además, pedido del Tech Lead: el slide-over de `ServiceResource` ("Editar"/"Crear", en la tabla y en el header) ocupaba casi toda la pantalla con `modalWidth('6xl')` — reducido a `modalWidth('3xl')` (aprox. la mitad: 48rem vs. 72rem) en las 3 acciones que abren el form.
- **Archivos/áreas:** `app/Models/Service.php` (`sanitizeCountries()`), `app/Filament/Resources/ServiceResource.php` (2 acciones), `app/Filament/Resources/ServiceResource/Pages/ManageServices.php` (1 acción).
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python confirma balance en los 3 archivos; confirmado que no queda ningún `6xl` en ninguno de los dos archivos de `ServiceResource`.
- **Siguiente:** confirmar en Studio que abrir/editar cualquier servicio ya no rompe, y que el modal ahora se ve proporcional (no casi fullscreen). Si `3xl` resulta muy angosto para el Tabs con 2 Repeaters, ajustar a `4xl`.

## 2026-08-31 — Fix real: 500 en `ServiceResource` por códigos de país legado + `Cliente0ServicesSeeder` ampliado a 12 (extiende ADR-035)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead reportó un 500 real al abrir/guardar una fila en `/services`: `TypeError` en `Filament\Forms\Components\Concerns\CanDisableOptions::isOptionDisabled()` — "`Argument #2 ($label) must be of type Htmlable|string, null given`". Causa: las filas sembradas antes de ADR-035 (con `Cliente0ServicesSeeder` viejo) quedaron con códigos de país en minúscula (`ar`, `uy`, ...) que el `CountryEnum` nuevo ya no reconoce; Filament no tolera que el valor guardado no exista entre las `options()` actuales del `Select` y rompe apenas intenta calcular el label para pintar el chip seleccionado — pasa con solo ABRIR el form, antes de guardar nada. Esto también explica por qué el listado mostraba el código ISO crudo en vez del nombre en algunos badges: `CountryEnum::tryFrom()` fallaba silenciosamente para esos valores viejos y caía al fallback `?? $state`. Fix de 2 partes: (1) `Service::sanitizeCountries()` nuevo (mayúscula + descarta códigos que no existan hoy en el Enum), enchufado en `ServiceResource` vía `->afterStateHydrated()` (sanea al abrir el form, evita el crash) y `->dehydrateStateUsing()` (sanea al guardar); (2) migración `2026_08_31_000005_normalize_services_countries.php` que recorre `services` con el query builder (no Eloquent — `HasTenant` necesita contexto de tenant que una migración no tiene) y normaliza cualquier fila ya sembrada con códigos viejos, directo en Postgres. Se descartó explícitamente combinar un `Attribute` mutator con el cast `'array'` ya existente para el mismo campo en el modelo, por riesgo de interacción poco clara entre ambos mecanismos de Eloquent — se centralizó la regla en un método estático simple en su lugar. De paso, pedido explícito del Tech Lead ("generar 12 servicios"): `Cliente0ServicesSeeder` ampliado de 7 a **12 servicios** — 5 nuevos sin captura de mockup (Seguros de Vida y Salud, Recursos Humanos y Gestión de Nómina, Comercio Exterior y Aduanas, Seguros Empresariales y Riesgos Corporativos, Turismo y Asistencia al Viajero), contenido redactado en el mismo tono/estructura, dentro del rubro real de CICA360, a revisar por el Tech Lead. `image_id` sigue `null` en los 12.
- **Archivos/áreas:** `app/Models/Service.php` (+`sanitizeCountries()`), `app/Filament/Resources/ServiceResource.php` (`countries`: `afterStateHydrated()`/`dehydrateStateUsing()`), `database/migrations/2026_08_31_000005_normalize_services_countries.php` (nueva), `database/seeders/Cliente0ServicesSeeder.php` (7 → 12 servicios).
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python confirma balance de paréntesis/llaves/corchetes en los 4 archivos; conteo confirma 12 servicios (37 ocurrencias de `'title' =>` = 12×3 + 1 del `foreach`), y los 12 usan códigos ISO en mayúscula válidos.
- **Siguiente:** el Tech Lead debe correr `php artisan migrate` (aplica la migración de normalización sobre los 7 servicios ya sembrados) seguido de `php artisan db:seed --class=Cliente0ServicesSeeder` (para que entren los 5 nuevos), luego `vendor/bin/pint --dirty --format agent`, y confirmar en Studio que: (a) ya no rompe al abrir/editar ningún servicio, (b) el listado muestra el nombre completo del país en los badges, no el código, (c) los 12 servicios aparecen en `/services`.

## 2026-08-31 — `CountryEnum`: listado ISO 3166-1 completo (249 países), campo opcional, reutilizable por cualquier tenant (ADR-035)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead pidió repensar `countries` para que (1) no sea requerido, (2) cubra todos los países del mundo con ISO => NAME (no solo los 6 de CICA360), y (3) sea "reutilizado por cualquier tenant" — también mencionó como alternativa un campo custom tipo tags con autocompletado cruzando otros servicios. Resuelto con el catálogo ISO en vez de tags libres: país tiene una respuesta objetivamente correcta y finita, freeform no da un código limpio para mapear a bandera. `App\Enums\CountryEnum` reescrito: 6 casos → 249 (listado ISO 3166-1 alpha-2 completo, nombres en español vía CLDR) + `Global`. Valores en MAYÚSCULA (`PE`, `AR`, ...) — misma clave que usará la convención de banderas del frontend `media/flags/flag_{ISO}.webp`. Nuevos helpers pensados para cuando se construya la API pública de `services`: `toApiArray()` (`['iso' => ..., 'name' => ...]`), `resolveMany(array $codes)`, `flagPath()`; `Service::countriesResolved()` expone lo mismo a nivel de modelo. En `ServiceResource`: `countries` ya no `->required()`, `->searchable()` agregado al form y al filtro de tabla (imprescindible con 249 opciones). Un `Enum` PHP es por definición un catálogo compartido por todo el código, no una tabla `tenant_id`-scoped — cubrir TODOS los países ya lo hace reutilizable por cualquier tenant futuro sin tocar código ni sembrar datos por cliente. La idea de tags libres queda anotada en el ADR como solución razonable para un campo *distinto* (clasificación abierta propia de cada tenant, sin catálogo universal) si en el futuro se pide algo así — no implementada ahora.
- **Archivos/áreas:** `app/Enums/CountryEnum.php` (reescrito completo), `app/Models/Service.php` (+`countriesResolved()`), `app/Filament/Resources/ServiceResource.php` (`countries` no requerido + `searchable()` en form y filtro), `database/seeders/Cliente0ServicesSeeder.php` (7 entradas: códigos de país pasados a mayúscula). ADR-035 en `DECISIONS.md`, nota de superseded parcial agregada a ADR-034.
- **Verificación:** sin PHP en este sandbox — checker PHP-aware en Python (ignora comentarios/strings) confirma balance de paréntesis/llaves/corchetes en los 4 archivos tocados; conteo confirma 249 casos ISO + `Global` = 250, sin identificadores de caso duplicados.
- **Siguiente:** el Tech Lead debe correr `php artisan db:seed --class=Cliente0ServicesSeeder` de nuevo (los 7 servicios ya sembrados quedaron con códigos en minúscula que el Enum nuevo ya no reconoce — `updateOrCreate` hace que re-correrlo sea seguro), luego `vendor/bin/pint --dirty --format agent` y confirmar visualmente en Studio que el selector de país ahora es buscable y opcional. Pendiente, sin pedido concreto todavía: subir los archivos de bandera reales a `media/flags/` en el frontend, y el sistema de tags genérico por tenant si llega a pedirse.

## 2026-08-31 — Fix real: `TypeError` en la columna `countries` de `ServiceResource` (extiende ADR-034)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead reportó un 500 real al abrir `/services` en Studio: `formatStateUsing(): Argument #1 ($state) must be of type ?array, string given`. Causa: con `->badge()` activo sobre una columna cuyo estado es un array (`countries`), Filament itera el array y llama `formatStateUsing` UNA VEZ POR CADA VALOR para pintar un badge por país — no recibe el array completo como asumí al tipar `fn (?array $state)`. Fix: la closure pasa a `fn (?string $state) => CountryEnum::tryFrom($state ?? '')?->getLabel() ?? $state`, un país a la vez.
- **Archivos/áreas:** `app/Filament/Resources/ServiceResource.php` (columna `countries`).
- **Verificación:** sin PHP en este sandbox — parser PHP-aware en Python confirma balance de paréntesis/llaves/corchetes.
- **Siguiente:** confirmar en Studio que `/services` carga sin error y las banderas se ven correctas por fila.

## 2026-08-31 — Módulo de Servicios: tabla `services` + `ServiceResource` + 7 servicios sembrados (ADR-034)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead compartió capturas del catálogo público "Servicios" (9 cards, 2 duplicadas — 7 títulos reales) y el detalle completo de "Seguros generales" (banner, intro, tabs "¿Qué ofrecemos?"/"Coberturas", "¿Por qué elegirnos?", tip), pidiendo "similar a páginas... una tabla de servicios con contenido en JSONB y links, meta y properties también... un recurso bien elaborado". Se creó: (1) tabla `services` con el mismo esqueleto que `pages` (pretitle/title/subtitle/slug/status/meta/links/properties/published_at) más `image_id`, `countries` (jsonb) y `content` (jsonb: intro/offers/coverages/why_choose_us/tip). (2) `App\Enums\CountryEnum` nuevo (ar/uy/py/ec/us/global). (3) `App\Filament\Resources\ServiceResource`: Tabs de 3 pestañas (Configuración/Contenido/SEO-Enlaces) mismo patrón que `PageResource`, con 2 Repeaters en Contenido (`offers`, `coverages` con `TagsInput` para el detalle opcional tipo "Automotores → Cubrimos daños por: ..."). Importante: NO se reutilizó `HeadingFieldset::make(hasSlug: true)` porque ese modo valida unicidad de slug hardcodeado contra `Page::class` — se usó `afterTitleUpdated` + un `TextInput::make('slug')` propio con `unique(Service::class, ...)`. `->modalWidth('6xl')` en las 3 acciones (mismo criterio aprendido con `TestimonialResource` este mismo día: dar el ancho real que el layout necesita). (4) `Cliente0ServicesSeeder`: 7 servicios (no 9 — las 2 cards duplicadas de la captura eran relleno de grilla de demo). Solo "Seguros generales" tenía contenido de detalle real en la captura, sembrado verbatim (incluye "Estudio Jurídico Mosquera – Perticaro & Abogados", "Patente N° 11 – SSN Argentina"); los otros 6 llevan contenido de ejemplo razonable a revisar por el Tech Lead. `image_id` null en los 7 (fotos vienen después).
- **Archivos/áreas:** `database/migrations/2026_08_31_000004_create_services_table.php`, `app/Enums/CountryEnum.php`, `app/Models/Service.php`, `app/Filament/Resources/ServiceResource.php` + `Pages/ManageServices.php`, `database/seeders/Cliente0ServicesSeeder.php` (nuevo), `database/seeders/DatabaseSeeder.php`. ADR-034 en `DECISIONS.md`.
- **Verificación:** sin PHP en este sandbox — parser PHP-aware en Python confirma balance de paréntesis/llaves/corchetes en todos los archivos tocados; conteo confirma 7 servicios sembrados.
- **Siguiente:** correr en local `php artisan migrate && php artisan db:seed`, `vendor/bin/pint --dirty --format agent`, confirmar visualmente el form en Studio. Fuera de esta vuelta a propósito (el Tech Lead no lo pidió todavía): exponer `services` en la API pública (`GET /v1/{tenant}/services`, `/services/{slug}`), decidir si `services_grid` pasa a consumir esta tabla (mismo tratamiento que `testimonials` en ADR-033), y construir el catálogo/detalle en `cica360` — pendiente además de que lleguen las imágenes reales de los 7 servicios.

## 2026-08-31 — Dataset de ejemplo ampliado de 4 a 12 testimonios, 5 fotos reutilizadas al azar (extiende ADR-033)
- **Agente/autor:** Claude
- **Qué se hizo:** pedido explícito del Tech Lead — "generar 12 testimonios random y reutilizar de forma aleatoria las 5 fotos almacenadas". `Cliente0TestimonialsSeeder` pasa de 4 a 12 entradas (se mantienen los 4 originales sin cambios, se suman 8 nuevos con roles variados — contadora, industria textil, salud, agro, arquitectura, comercio, docencia universitaria, RRHH/logística — para reflejar mejor el rango real de servicios de CICA360). Con solo 5 fotos reales disponibles para 12 personas, cada `avatar_file` se asignó a mano en un orden mezclado que reutiliza cada foto 2-3 veces sin repetir en testimonios consecutivos — a propósito NO generado con `rand()` en cada corrida, para no romper la idempotencia esperada del seeder. `Cliente0MediaSeeder`: los `name`/`alt` de los 5 registros `Media` se generalizaron ("Avatar Testimonio N" en vez de atados a una persona puntual, ya que cada foto ahora es compartida). `Cliente0ContentSeeder`: comentario del bloque `testimonials` de "Casos de éxito" actualizado (ya no dice "los trae todos" — ahora trae 4 de 12, límite sin cambios).
- **Archivos/áreas:** `database/seeders/Cliente0TestimonialsSeeder.php` (12 entradas), `database/seeders/Cliente0MediaSeeder.php` (comentarios/nombres de los 5 avatares), `database/seeders/Cliente0ContentSeeder.php` (comentario). Actualización 3 de ADR-033 en `DECISIONS.md`.
- **Verificación:** sin PHP en este sandbox — parser PHP-aware en Python confirma balance de paréntesis/llaves/corchetes en los 3 archivos; conteo de entradas confirma 12 `name`/12 `avatar_file` en el seeder.
- **Siguiente:** correr en local `php artisan db:seed --class=Cliente0TestimonialsSeeder` (ya corrido `Cliente0MediaSeeder` con los 5 avatares) y confirmar visualmente en `TestimonialResource` que los 12 testimonios aparecen con la foto correspondiente asignada.

## 2026-08-31 — `TestimonialResource`: fix real de fullwidth — `Group`/`Grid` anidados aplanados a campos directos de la Section (extiende ADR-033)
- **Agente/autor:** Claude
- **Qué se hizo:** el fix anterior (Section a 3 columnas + 2 `Group` internos, uno con `Grid::make(2)` adentro) no resolvió el problema real — el Tech Lead mandó una captura nueva mostrando la Section TODAVÍA más angosta, con mucho espacio vacío a la derecha pese al `modalWidth('2xl')` ya aplicado. Causa identificada: grids de Filament anidados (`Section` con columnas → `Group` → otro `Grid` con columnas) pueden colapsar el contenedor intermedio a un ancho "shrink-to-fit" en vez de estirarse al 100% de su celda. Fix real: aplanada la estructura — los 5 campos (`avatar_id`, `name`, `role`, `quote`, `is_visible`) pasan a ser hijos DIRECTOS de la única `Section::make()->columns(3)`, cada uno con su propio `columnSpan()`, sin `Group` ni `Grid` intermedios; se sumó `->extraAttributes(['class' => 'w-full'])` a la Section como refuerzo. Import de `Grid`/`Group` (ya sin uso) eliminado del archivo.
- **Archivos/áreas:** `app/Filament/Resources/TestimonialResource.php` (`form()`). Actualización 2 de ADR-033 en `DECISIONS.md`.
- **Verificación:** sin PHP en este sandbox — parser PHP-aware en Python confirma balance de paréntesis/llaves/corchetes.
- **Siguiente:** confirmar visualmente en Studio que la Section ahora sí ocupa el ancho completo del modal; si el problema persiste, revisar si hay CSS custom (`public/css/filament/*.css`) pisando el layout de este Resource en particular.

## 2026-08-31 — `TestimonialResource`: form reorganizado a 2 columnas (avatar+datos) + modal más ancho + avatares reales sembrados (extiende ADR-033)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead mandó 2 capturas del `TestimonialResource` recién creado: (1) el árbol de archivos mostrando 5 fotos nuevas subidas a `storage/app/public/media/` (`cica360_media_testimony-{1..5}.webp`), y (2) el modal "Editar Testimonio" con el form de una sola columna viéndose angosto y con mucho espacio desperdiciado a los costados dentro del slide-over. Dos fixes: (1) **UX del form** — `Section` pasa de una columna a `columns(3)`: avatar en su propio `Group` (1 columna, angosta), Nombre+Puesto+Frase en otro `Group` (2 columnas, ancha real), Visible a ancho completo al pie. Las 3 acciones que abren el form (`EditAction`, ambos `CreateAction`) ganan `->modalWidth('2xl')` — el slide-over usaba un ancho angosto por default sin importar cuánto espacio interno pidiera el schema. (2) **Avatares reales** — las 5 fotos se agregaron a `Cliente0MediaSeeder::FILES` (mismo patrón que slides/splits); `Cliente0TestimonialsSeeder` ahora asigna `avatar_id` a cada uno de los 4 testimonios vía `Cliente0MediaSeeder::mediaId()`. La 5ta queda sembrada sin asignar, disponible para un testimonio futuro.
- **Archivos/áreas:** `app/Filament/Resources/TestimonialResource.php` (form + `modalWidth` en tabla), `app/Filament/Resources/TestimonialResource/Pages/ManageTestimonials.php` (`modalWidth` en el `CreateAction` del header), `database/seeders/Cliente0MediaSeeder.php` (+5 entradas), `database/seeders/Cliente0TestimonialsSeeder.php` (`avatar_id` real por testimonio). Actualización de ADR-033 en `DECISIONS.md`.
- **Verificación:** sin PHP en este sandbox — parser PHP-aware en Python confirma balance de paréntesis/llaves/corchetes en los 4 archivos tocados.
- **Siguiente:** correr en local `php artisan db:seed --class=Cliente0MediaSeeder && php artisan db:seed --class=Cliente0TestimonialsSeeder` (o `db:seed` completo), `vendor/bin/pint --dirty --format agent`, y confirmar visualmente que el modal se ve bien y los avatares reales cargan (ya no debería verse el fallback de iniciales en Cliente 0, salvo que se agregue un 5to testimonio sin asignarle la foto ya sembrada).

## 2026-08-31 — Módulo propio de Testimonios/Casos de Éxito: tabla + `TestimonialResource` + bloque reducido a filtro (ADR-033)
- **Agente/autor:** Claude
- **Qué se hizo:** el bloque `testimonials` guardaba los testimonios inline en un `Repeater` (`content.items`), sin gestión centralizada ni forma de ocultar uno puntual sin borrarlo, y con el mismo bug de campos duplicados de ADR-031/032 (`TextInput::make('title')`/`('subtitle')` sueltos junto a `HeadingFieldset::make()`, que ya los provee). A pedido del Tech Lead ("quiero una gestión mediante un recurso Filamente 5 profesional con mucho UX"): (1) nueva tabla `testimonials` (`name`/`role`/`quote`/`avatar_id`/`is_visible`/`sort_order`, tenant-scoped) + modelo `Testimonial`. (2) Nuevo `TestimonialResource` (patrón `ManageRecords` de página única, avatar circular vía `MediaUpload::circleCropper()`, toggle de visibilidad inline, filtro de visibilidad, acciones masivas mostrar/ocultar, drag-reorder por `sort_order`). (3) El bloque `testimonials` de `PageResource.php` se reescribió: ahora es solo encabezado (`HeadingFieldset`, sin duplicados) + filtro (`content.limit`, `content.order` asc/desc por fecha) + un enlace único opcional (mismo patrón `LinkSchema::makeSingle()` de `rich_text`, con su `properties.show_link`) + "Personalización de estilos". (4) `ResolvesPublicLinks::attachResolvedBlockContent()` gana la resolución en runtime: una sola query de todos los testimonios visibles del tenant (compartida entre bloques, evita N+1), recortada/ordenada en memoria por cada bloque según su propio filtro, entregada en la MISMA forma que ya consumía el frontend (`content.items[]` con `name`/`role`/`quote`/`avatar`) — `limit`/`order` nunca salen en la response pública. (5) Nuevo `Cliente0TestimonialsSeeder` (los 4 testimonios de ejemplo, antes duplicados entre 2 bloques, ahora una sola fuente) registrado junto a `Cliente0MediaSeeder`; los 2 bloques `testimonials` sembrados (home y página "casos-de-exito") pasan de `content.items` a `content.limit`/`content.order` + fondo teal (`#2b7c89`, del design system real de CICA360). (6) `cica360/src/components/blocks/Testimonials.astro` reescrito: corrige el bug real (`TestimonialItem.author` nunca coincidía con el `name` que mandaba el backend — los nombres jamás se renderizaron) y reemplaza el stub sin estilos por el diseño del mockup (fondo sólido, tarjetas con avatar circular + fallback de iniciales, frase/nombre en itálica, botón "ver más" con ícono de ojo).
- **Archivos/áreas:** `database/migrations/2026_08_31_000003_create_testimonials_table.php`, `app/Models/Testimonial.php`, `app/Filament/Resources/TestimonialResource.php` + `Pages/ManageTestimonials.php`, `app/Filament/Resources/PageResource.php` (bloque `testimonials`), `app/Http/Concerns/ResolvesPublicLinks.php`, `database/seeders/Cliente0TestimonialsSeeder.php` (nuevo), `database/seeders/DatabaseSeeder.php`, `database/seeders/Cliente0ContentSeeder.php`, `cica360/src/components/blocks/Testimonials.astro`, `cica360/docs/context/api/stamless-api-v1.md`. ADR-033 en `DECISIONS.md`.
- **Verificación:** sin PHP/npm en este sandbox — parser PHP-aware en Python confirma balance de paréntesis/llaves/corchetes en todos los archivos PHP tocados; tag-balance + brace-count en `Testimonials.astro` también cuadra.
- **Siguiente:** correr en local `php artisan migrate && php artisan db:seed`, `vendor/bin/pint --dirty --format agent`, y confirmar visualmente el bloque contra el mockup (avatares con fallback de iniciales hasta cargar fotos reales vía Studio — ningún testimonio sembrado trae `avatar_id`).

## 2026-08-31 — Revertido `properties.list_icon` (íconos de lista por bloque) — el Tech Lead pidió algo más simple, resuelto 100% en frontend
- **Agente/autor:** Claude
- **Qué se hizo:** empecé a implementar un selector de ícono para los `<li>` del cuerpo de texto (`properties.list_icon`: tag/plus-circle/check-circle) — nuevo componente en `PropertiesSchema`, campo en el Fieldset "Sección" de `split` en `PageResource.php`, valores sembrados en los 2 bloques `Split` del home (`Cliente0ContentSeeder`). El Tech Lead lo frenó ("para no hacerlo muy complicado") y pidió en cambio una viñeta DOT más grande, sin selector — no requiere ninguna property nueva, se resuelve 100% en CSS del lado de `cica360` (ver `cica360/docs/context/PROGRESS.md`). Se revirtieron los 3 cambios de este lado por completo (sin dejar el campo "por las dudas" — el Tech Lead ya lo descartó explícitamente, dejarlo muerto en el schema hubiera sido el mismo anti-patrón de "property sin consumidor" que se corrigió varias veces esta sesión).
- **Archivos/áreas:** `app/Filament/Schemas/PropertiesSchema.php` (queda en 41 componentes, sin cambio neto), `app/Filament/Resources/PageResource.php` (bloques `split` y `rich_text`, vuelven a su estado previo), `database/seeders/Cliente0ContentSeeder.php` (los 2 `Split` del home vuelven a solo `content_width: 'full'`).
- **Verificación:** sin PHP en este sandbox — parser PHP-aware en Python confirma balance de paréntesis/llaves/corchetes en los 3 archivos.
- **Siguiente:** ninguno de este lado — el ajuste real (tamaño del punto) se confirma visualmente contra `cica360`.

## 2026-08-31 — Seeder: `content_width: 'full'` sembrado en los 2 `split` del home (extiende ADR-032)
- **Agente/autor:** Claude
- **Qué se hizo:** los 2 bloques `split` de la home ("¿Qué hacemos?"/"¿A quién nos dirigimos?") no tenían `properties` en absoluto — el frontend caía al fallback `?? 'boxed'`, no al fullwidth bleed que el diseño real usa (mismo diseño que motivó agregar `content_width` en la entrada anterior). Se sembró `'properties' => ['content_width' => 'full']` explícito en ambos, para no depender del fallback.
- **Archivos/áreas:** `database/seeders/Cliente0ContentSeeder.php` (`upsertHomePage()`, los 2 bloques `Split`).
- **Verificación:** sin PHP en este sandbox — parser PHP-aware en Python confirma balance de paréntesis/llaves/corchetes.
- **Siguiente:** correr `php artisan db:seed --class=Cliente0ContentSeeder` (o `db:seed` completo) en local y confirmar visualmente que ambos splits del home quedan fullwidth.

## 2026-08-31 — `split`: `content_width` fullwidth asimétrico (imagen bleed + texto con padding) + `media_position` reubicado (extiende ADR-032)
- **Agente/autor:** Claude
- **Qué se hizo:** dos correcciones más sobre el sitio real corriendo. (1) "Posición de la imagen" se movió de arriba (junto a `is_visible`) hacia adentro de "Personalización de estilos" > "Sección" — solo reubicación de UI, el campo sigue siendo `content.media_position` (no se duplicó a `properties`, eso hubiera reabierto el bug de ADR-031). (2) El Tech Lead mandó una captura del sitio ya corriendo: para `split`, "fullwidth" significa que la imagen llega hasta el borde real del viewport (bleed) mientras el texto conserva el padding estándar de 80px solo del lado exterior — asimétrico, distinto al `full` simétrico de `rich_text`. Se agregó `content_width` (`full`/`boxed`/`narrow`) al Fieldset "Sección" de `split`. `Split.astro`: `boxed`/`narrow` usan un contenedor centrado simétrico (mismo patrón que `rich_text`); `full` no lleva padding en el contenedor — la imagen bleedea y la columna de texto recibe el padding de 80px calculado dinámicamente según de qué lado está la imagen (`mediaOnRight`).
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (bloque `split`), `cica360/src/components/blocks/Split.astro`, `cica360/docs/context/api/stamless-api-v1.md`. Actualización 2 de ADR-032 en `DECISIONS.md`.
- **Verificación:** sin PHP en este sandbox — parser PHP-aware en Python confirma balance de paréntesis/llaves/corchetes en `PageResource.php`; tag-balance + brace-count en `Split.astro` también cuadra; los 10 bloques JSON de `stamless-api-v1.md` siguen parseando OK.
- **Siguiente:** correr en local `vendor/bin/pint --dirty --format agent` y confirmar visualmente en el sitio real que `content_width: full` reproduce exactamente el bleed de la captura que mandó el Tech Lead.

## 2026-08-31 — `split`: `MediaUpload`+`HeadingFieldset` a 2 columnas, sección "Personalización de estilos" con filtros/blend de imagen (extiende ADR-032)
- **Agente/autor:** Claude
- **Qué se hizo:** dos rondas más de feedback del Tech Lead sobre capturas reales del bloque `split` en Studio, seguidas a la entrada anterior de este mismo día. (1) "Imagen / Elemento Multimedia" y "Encabezado (Opcional)" quedaban cada uno en su propia fila completa — envueltos en `Grid::make(2)` para que compartan fila. (2) Los 4 campos de estilo (`Color de fondo`, `Color de texto`, `Espaciado vertical`, `Animación de entrada`) estaban apilados sueltos debajo de "Enlaces" — el Tech Lead pidió "una sección a 2 columnas, algo más profesional" y de paso "filtros y blends para la imagen bien ordenados". Se creó una `Section::make('Personalización de estilos')` colapsable con 2 `Fieldset` a 2 columnas: "Sección" (los mismos 4 campos de antes) e "Imagen: filtros y efectos" (10 campos NUEVOS: `media_blend_mode`, `media_brightness`, `media_opacity`, `media_radius` + los 6 filtros CSS clásicos — mismo set que ya tenía el fondo del Slide en ADR-027, generalizado en `PropertiesSchema` bajo el prefijo `media_*` en vez de `slide_background_*` para que cualquier bloque futuro con `MediaUpload` lo pueda reusar). `Split.astro` los consume de inmediato con el mismo patrón de `Hero.astro` (`filter`/`opacity`/`mix-blend-mode` combinados en un `style`, más una clase `rounded-*` para `media_radius`) — evitando a propósito el anti-patrón de property "registrada pero sin consumidor" que se corrigió varias veces esta sesión.
- **Archivos/áreas:** `app/Filament/Schemas/PropertiesSchema.php` (+10 componentes: de 31 a 41), `app/Filament/Resources/PageResource.php` (bloque `split`), `cica360/src/components/blocks/Split.astro`. Actualización de ADR-032 en `DECISIONS.md`.
- **Verificación:** sin PHP en este sandbox — parser PHP-aware en Python confirma balance de paréntesis/llaves/corchetes en ambos archivos PHP; tag-balance + brace-count en `Split.astro` también cuadra.
- **Siguiente:** correr en local `vendor/bin/pint --dirty --format agent`, confirmar visualmente en Studio que "Personalización de estilos" queda colapsada por default y los filtros de imagen se ven en Split.astro contra contenido real.

## 2026-08-31 — Fix orden de seeders (imagen faltante en `split`), reorg del form `split` y mensajes de validación en español
- **Agente/autor:** Claude
- **Qué se hizo:** 3 issues reportados por el Tech Lead sobre una captura de Studio con el bloque `split`. (1) **"no hay imagen inyectada"**: bug propio — al agregar `Cliente0MediaSeeder::mediaId()` dentro de `Cliente0ContentSeeder::upsertHomePage()` (entrada anterior) no se movió `Cliente0MediaSeeder` antes en el orden de `DatabaseSeeder::run()`; corría DESPUÉS de `Cliente0ContentSeeder`, así que las filas `Media` de los splits todavía no existían y `mediaId()` devolvía `null` siempre. Reordenado: `Cliente0Seeder → Cliente0MediaSeeder → Cliente0ContentSeeder → Cliente0HomeSlidesSeeder → Cliente0PostsSeeder`. (2) **UX del form `split`**: el `Grid::make(2)` con `[Hidden lang_iso, Toggle is_visible, MediaUpload media_id, Select media_position]` dejaba una celda vacía (3 campos visibles sobre grid de 2 columnas). Reorganizado: `Hidden` sale del grid, `Toggle is_visible` + `Select content.media_position` llenan la fila pareja (posición se decide antes de subir el archivo, como pidió el Tech Lead), y `MediaUpload` pasa a su propia fila con `->columnSpanFull()` (más ancho, con preview — tiene sentido que no comparta fila). El tipo de archivo permitido lo sigue limitando el propio `MediaUpload`, sin cambios ahí. (3) **`is_visible` sin explicación**: es funcional (confirmado por grep — `PageController::show()` filtra `->where('is_visible', true)` antes de exponer bloques por API), pero no lo decía en ningún lado del form. Se agregó `->helperText('Oculta el bloque en el sitio público sin borrarlo del editor.')` a las 5 ocurrencias de `Toggle::make('is_visible')` en `PageResource.php` (no solo `split`, por consistencia). (4) **Mensajes de validación en inglés pese a `APP_LOCALE=es`**: el proyecto nunca tuvo carpeta `lang/` propia — Laravel 11+ no la scaffoldea por defecto y cae al inglés embebido en `vendor/laravel/framework`. Creados `lang/es/validation.php`, `auth.php`, `passwords.php`, `pagination.php` (traducciones completas, misma estructura de claves que el vendor en inglés) — Filament ya traía sus propios `es` para tablas/forms/notificaciones, pero el core de Laravel no.
- **Archivos/áreas:** `database/seeders/DatabaseSeeder.php` (orden + docblock), `app/Filament/Resources/PageResource.php` (bloque `split` reorganizado, `helperText` en 5 Toggles `is_visible`), `lang/es/{validation,auth,passwords,pagination}.php` (nuevos).
- **Verificación:** sin PHP en este sandbox — parser PHP-aware en Python (respeta comillas/escapes/comentarios reales, no regex ingenuo) confirma balance exacto de paréntesis en `PageResource.php` tras el edit; balance de llaves/corchetes también cuadra en los demás archivos.
- **Siguiente:** correr en local `php artisan migrate:fresh --seed` (o al menos `db:seed`) y confirmar visualmente que el bloque `split` trae imagen; `php artisan config:clear` si el locale sigue viéndose en inglés (por si hay `config:cache` viejo pisando `.env`); `vendor/bin/pint --dirty --format agent`.

## 2026-08-31 — `split`: `->cloneable()`, duplicado eliminado, contenido real del home + `Split.astro` implementado (ADR-032)
- **Qué se hizo:** el Tech Lead preguntó si se puede clonar bloques en Studio — sí, Filament 5 lo trae nativo (`->cloneable()` en el `Builder`, ya agregado). De paso, revisando el bloque `split` para armar el seeder del contenido real de la home ("¿Qué hacemos?"/"¿A quién nos dirigimos?", mockup compartido), encontré el mismo bug de ADR-031: `properties.media_position` duplicaba `content.media_position` (que es el real, required, resuelto por el backend) — eliminado. El seeder tenía "¿Qué hacemos?" como bloque `Features` (no matchea el mockup) y "¿A quién nos dirigimos?" con `media_id: null` — se convirtió el primero a `Split` y se completaron ambos con contenido real + 2 imágenes nuevas (`cica360_media_split_1.webp`/`_2.webp`, mismo patrón `Cliente0MediaSeeder` de ADR-030). `Split.astro` (frontend, antes stub) se implementó completo: grid de 2 columnas con `media_position` alternando el orden en desktop.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php`, `database/seeders/Cliente0MediaSeeder.php`, `database/seeders/Cliente0ContentSeeder.php`. Ver `cica360/docs/context/PROGRESS.md` para `Split.astro`.
- **Verificación:** sin PHP en este sandbox — balance de llaves/paréntesis/corchetes vía script Python en los 3 archivos, cuadra.
- **Siguiente:** `php artisan migrate && php artisan db:seed` en local y confirmar visualmente contra el mockup.

## 2026-08-31 — Hero: `HeadingFieldset` también movido a modo Manual (extiende ADR-031)
- **Qué se hizo:** siguiendo el mismo criterio de la entrada anterior, el Tech Lead notó que "Encabezado (Opcional)" (pretitle/título/subtítulo del Block) quedaba visible siempre, "por las puras" en modo Slider — cada Slide ya trae su propio pretitle/título/subtítulo, el front nunca lee el del Block salvo en el fallback manual. Se movió adentro de "Configuración Manual". En modo Slider el bloque `hero` queda solo con "Modo del Hero" + "Seleccionar Slider". También se recortaron los comentarios inline largos que había dejado en `PageResource.php` a un puntero corto a este ADR (el Tech Lead los encontró redundantes con lo ya escrito acá).
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php`.
- **Verificación:** balance de llaves/paréntesis/corchetes vía script Python — cuadra (20/20, 614/614, 115/115).

## 2026-08-31 — Hero: campos de modo Manual agrupados bajo un solo `->visible()` + duplicado eliminado (ADR-031)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead marcó con captura que "Botón de acción (CTA)", "Color de fondo", "Color de texto", "Opacidad del overlay", "Alineación del texto", "Espaciado vertical (Padding)" y "Animación de entrada" se mostraban siempre en el bloque `hero`, sin importar `content.mode` — en modo Slider no significan nada. Se movió `LinkSchema::make('links', ...)` + los componentes de `PropertiesSchema` (antes sueltos al final del bloque) DENTRO de la Section "Configuración Manual" ya existente (que ya tenía su propio `->visible()` para modo manual) — un solo gate para todo el grupo. Reorganizado en `Grid::make(2)` dentro de una nueva sub-Section "Diseño de la sección" en vez de lista plana. Al reorganizar apareció un duplicado real: `content.overlay_opacity`/`content.align` (campos viejos, sueltos) pisaban el mismo concepto que `properties.overlay_opacity`/`properties.text_align` de `PropertiesSchema` — confirmado por grep que no se leen en ningún otro lado (ni backend ni `Hero.astro`), se eliminaron.
- **Archivos/áreas:** `app/Filament/Resources/PageResource.php` (bloque `hero` completo). ADR-031 en `DECISIONS.md`.
- **Verificación:** sin PHP en este sandbox — balance de llaves/paréntesis/corchetes vía script Python, cuadra (20/20, 612/612, 114/114).
- **Nota pendiente, fuera de alcance de este cambio:** `properties.overlay_opacity` sigue sin consumidor en el frontend — `Hero.astro` en modo Manual usa un overlay de legibilidad fijo (gradiente hardcodeado), no dinámico. El campo en Studio ya existe si se quiere conectar a futuro.
- **Siguiente:** confirmar visualmente el nuevo layout de 2 columnas en Studio real, y que ocultar/mostrar según `content.mode` funciona como se espera.

## 2026-08-31 — Nuevo patrón: `Cliente0MediaSeeder` siembra `media` desde archivos commiteados, seeders de contenido resuelven la FK (ADR-030)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead corrió `php artisan migrate && php artisan db:seed` y notó que las 3 slides del home perdieron los fondos subidos a mano por Studio (2 repetían la misma imagen, una quedó sin fondo). Se revisó todo el código de los seeders/migración de esta sesión y ninguno escribe a `image_desktop_id`/`image_tablet_id`/`image_mobile_id` ni toca `media` — no se pudo confirmar la causa exacta desde este sandbox (sin acceso a la BD real). El Tech Lead optó por resetear los datos y pidió, en cambio, que el contenido inicial (datos Y media) quede 100% reproducible desde seeders, sin volver a subir archivos a mano — "inyectar en media primero y luego la relación con slides", patrón a extender "a los demás contenidos o secciones". Implementado: `Cliente0MediaSeeder` (nuevo) crea los 3 registros `Media` de las slides del home desde archivos YA COMMITEADOS en `storage/app/public/media/` (`cica360_media_slide{1,2,3}.webp` — confirmados en disco, no están en `.gitignore`), idempotente (`firstOrCreate` por `tenant_id`+`path`), con un helper estático `mediaId()` reusable. `Cliente0HomeSlidesSeeder` ahora resuelve cada fondo vía ese helper y asigna el mismo id a `image_desktop_id`/`image_tablet_id`/`image_mobile_id` (un solo fondo por breakpoint por ahora, pedido explícito) — si el `Media` no existe, la FK se omite del payload en vez de forzar `null` (nunca pisa una elección manual en Studio).
- **Archivos/áreas:** `database/seeders/Cliente0MediaSeeder.php` (nuevo), `database/seeders/Cliente0HomeSlidesSeeder.php` (`SLIDES` gana `background_file`, `run()` resuelve la FK condicionalmente), `database/seeders/DatabaseSeeder.php` (orden: `Cliente0MediaSeeder` entre `Cliente0ContentSeeder` y `Cliente0HomeSlidesSeeder`). ADR-030 en `DECISIONS.md`.
- **Verificación:** sin PHP en este sandbox — balance de llaves/paréntesis/corchetes vía script Python en los 3 archivos, todo cuadra. Confirmado por `ls`/`Storage::exists` lógico que los 3 `.webp` existen físicamente en `storage/app/public/media/` (46KB/116KB/172KB).
- **Siguiente:** correr `php artisan db:seed` (datos ya reseteados por el Tech Lead) y confirmar visualmente que cada slide trae el fondo correcto según su posición (slide 1 → `cica360_media_slide1.webp`, etc.). Extender el mismo patrón a `Cliente0ContentSeeder` cuando haya featured images de pages/posts que sembrar.

## 2026-08-31 — Hero: `show_scroll_indicator` corregido a nivel Slider (no por slide)
- **Agente/autor:** Claude
- **Qué se hizo:** corrección, mismo día, sobre una primera pasada de `show_scroll_indicator` en el Hero que lo había agregado como property POR SLIDE (mismo patrón que `decorator_bottom`). El Tech Lead aclaró: "las propiedades del slider en general, no dentro de cada slide, solo una vez desde el slider para sobreponerse sobre el decorador... para todos los slides detrás" — y, para el modo Manual del Hero (sin Slider asociado), que el toggle equivalente debe vivir en el propio bloque, visible solo cuando `content.mode === 'manual'`. Cambios: (1) `sliders` gana su primera columna `properties` (jsonb, nullable) — no la tenía, solo title/slug/is_active — vía nueva migración. (2) `Slider` model: `properties` a `$fillable` y cast `array`. (3) `SliderResource.php`: el toggle se movió de adentro del `Repeater` de slides (fieldset "Decorador inferior", revertido a sus 3 campos originales) a la sección "General" a nivel Slider, fieldset nuevo "Flecha de scroll". (4) `PageResource.php`: el bloque `hero` gana el mismo toggle (reusado de `PropertiesSchema`), visible solo si `content.mode === 'manual'` — oculto en modo slider porque ahí el control real pasa al Slider elegido. (5) Seed: se retiró de `Cliente0HomeSlidesSeeder::baseProperties()` (ya no por slide) y se agregó una sola vez en `Cliente0Seeder::upsertPlaceholderSlider()` (`properties: ['show_scroll_indicator' => true]` en el Slider `home`).
- **Archivos/áreas:** `database/migrations/2026_08_31_000002_add_properties_to_sliders_table.php` (nueva), `app/Models/Slider.php`, `app/Filament/Resources/SliderResource.php`, `app/Filament/Resources/PageResource.php` (bloque `hero`), `database/seeders/Cliente0HomeSlidesSeeder.php`, `database/seeders/Cliente0Seeder.php`. ADR-027 en `DECISIONS.md` corregido (no se agregó una "Actualización" nueva encima de la errónea — se reescribió esa misma entrada para reflejar el diseño final).
- **Verificación:** sin PHP en este sandbox — balance de llaves/paréntesis/corchetes vía script Python en los 6 archivos tocados, todo cuadra. Ver `cica360/docs/context/PROGRESS.md` para la mitad frontend (`Hero.astro`, `types.ts`, `stamless-api-v1.md`).
- **Siguiente:** correr en local `php artisan migrate`, `vendor/bin/pint --dirty --format agent`, `php artisan test`, y `php artisan db:seed` (o `migrate:fresh --seed`) para que el Slider `home` quede con `properties.show_scroll_indicator = true` en la BD real.

## 2026-08-31 — `rich_text`: `link_radius` + `link_size` (el botón dejó de tener bordes/tamaño fijos)
- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead mandó una captura del botón "CONOCE +" real (esquinas redondeadas moderadas, no pill) y notó que el `rounded-full` había quedado hardcodeado en el frontend — pidió una property para elegirlo (`xs`/`sm`/`md`/`lg`/`xl`/`full`) y, en el mismo mensaje, otra para el tamaño (`small`/`normal`/`large` — el botón actual quedaba fijo en "large"). Se agregaron `link_radius` y `link_size` a `PropertiesSchema`, ambas con `default('lg')` para no romper el aspecto de nada ya sembrado.
- **Archivos/áreas:** `app/Filament/Schemas/PropertiesSchema.php` (+2 componentes, de 29 a 31), `app/Filament/Resources/PageResource.php` (grid de 3 columnas en la sección "Enlace" de `rich_text`: `show_link`/`link_radius`/`link_size`), `database/seeders/Cliente0ContentSeeder.php` (bloque de home siembra `link_radius: 'lg'`/`link_size: 'lg'` explícitos). Detalle completo en la "Actualización" de ADR-029 en `DECISIONS.md`.
- **Verificación:** balance de llaves/paréntesis/corchetes vía script Python en los 3 archivos — todo cuadra. Sin PHP en este sandbox.
- **Siguiente:** correr `php artisan db:seed --class=Cliente0ContentSeeder` en local y confirmar visualmente contra la captura del Tech Lead. Ver `cica360/docs/context/PROGRESS.md` para la mitad frontend (`RichText.astro`: `LINK_RADIUS_CLASSES`/`LINK_SIZE_CLASSES`).

## 2026-08-31 — Bloque `rich_text`: personalización visual completa + enlace único opcional (ADR-029)
- **Agente/autor:** Claude
- **Qué se hizo:** a pedido directo del Tech Lead (con mockup de referencia), se reesquematizó el bloque `rich_text` en `PageResource.php` para exponer fondo, color de texto, alineación, ancho de contenido, padding, decoradores arriba/abajo (con color y opacidad — se agregó `decorator_top_opacity` por simetría con el inferior, que ya la tenía), flecha indicadora de scroll, y un enlace "ver más" único y opcional (no un Repeater — reusa `LinkSchema::makeSingle()`, ya existente desde ADR-027) gateado por un toggle `properties.show_link` para poder ocultar el botón sin perder lo cargado. El form quedó reorganizado en 2 `Section` colapsables en vez de una lista plana de campos.
- **Archivos/áreas:** `app/Filament/Schemas/PropertiesSchema.php` (+3 componentes: `show_scroll_indicator`, `show_link`, `decorator_top_opacity`), `app/Filament/Resources/PageResource.php` (bloque `rich_text` reescrito, `$richTextLinkFields` local var, import de `Fieldset`), `database/seeders/Cliente0ContentSeeder.php` (los 4 bloques `rich_text` existentes siembran `properties` completo; el de home además siembra `links[0]` con el link "Conoce más" → `sobre-cica`, `type: outline`).
- **Verificación:** sin PHP en este sandbox — balance de llaves/paréntesis/corchetes verificado vía script Python (código real, ignorando comentarios `//` que abren/cierran paréntesis en distinta línea) en los 3 archivos tocados, todo cuadra.
- **Siguiente:** correr en local `vendor/bin/pint --dirty --format agent`, `php artisan test`, y `php artisan db:seed --class=Cliente0ContentSeeder` para confirmar que el seeder corre limpio contra la BD real. Ver también `cica360/docs/context/PROGRESS.md` para la mitad frontend de este mismo cambio (`RichText.astro` reescrito, docs del API actualizados).

---

## 2026-08-30 — `MediaUpload`: reemplazo de `MediaSelect` (dropdown+modal) por subida directa con preview real

- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead planteó, con capturas comparando el Studio actual (campos "Imagen Desktop/Tablet/Móvil" mostrando solo el nombre del archivo elegido en un `Select`) contra un sitio de referencia (subida directa con preview grande, texto de dimensiones recomendadas, reproductor de video inline), que el patrón actual de `MediaSelect` — un `Select` que obliga a abrir un modal para subir y luego elegir de una lista — "no lo veo usable". Tras análisis (confirmado: la tabla `media` centralizada sigue teniendo sentido para no duplicar archivos entre campos/tenants; la API (`MediaResource`) ya expone `url`/`uuid`, nunca el `id` interno — no hacía falta ADR nuevo para eso) se propuso reemplazar solo la UX del campo, aprobado por el Tech Lead ("adelante, es mejor cambiar la experiencia visual").
  - Nuevo `App\Filament\Schemas\MediaUpload::make(string $name, string $label, string $accept = 'image')` — es un `Filament\Forms\Components\FileUpload` real (drag&drop, preview nativo de imagen o video vía FilePond, sin pasar por modal), pero su estado NO es la ruta en disco (lo que un `FileUpload` espera de forma nativa) sino el `id` interno del registro `Media` creado al subir — así se sigue asignando directo a las FKs existentes (`image_desktop_id`, `video_desktop_id`, etc.) sin migraciones ni cambios de contrato en la API.
  - Mecanismo: `fetchFileInformation(false)` (el estado no es una ruta real, así que se desactiva el chequeo nativo `Storage::exists()`), `saveUploadedFileUsing()` reutiliza `$component->saveUploadedFile($file)` (lógica de storage nativa de Filament: directorio/disco/visibilidad) y crea el `Media` a partir de la ruta resultante, devolviendo `(string) $media->id`; `getUploadedFileUsing()` resuelve ese id a `{name, size, type, url}` vía `Media::find()->url()` (preview real, incluye video porque FilePond decide `<img>`/`<video>`/ícono según el `type` mime devuelto — no hizo falta lógica especial para video); `deleteUploadedFileUsing()` borra el archivo del disco del `Media` y el registro.
  - `accept: 'image'` (default) aplica `->image()->imageEditor()->maxSize(5120)`; `accept: 'video'` aplica `->acceptedFileTypes([...])->maxSize(51200)`; `accept: 'any'` solo limita tamaño.
  - Reemplazados los 24 call sites de `MediaSelect::make(...)` en `SliderResource.php` (5, incluye los 2 campos de video con `accept: 'video'`), `PageResource.php` (14) y `PostResource.php` (5) — mismo nombre/label/`->required()`/`->visible()` condicionales preservados sin tocar.
  - `MediaSelect.php` queda sin uso (marcado `@deprecated` en el propio archivo) — no se pudo borrar en este sandbox (el folder de trabajo del usuario no permite `rm`), seguro de eliminar en un PR normal.
- **Trade-off consciente, no resuelto:** el viejo `MediaSelect` tenía un `TextInput` de `alt_text` dentro del modal de creación; `MediaUpload` no tiene ningún campo para capturarlo (coherente con el pedido de simplificar a "solo arrastrar el archivo", y con el mockup de referencia que tampoco lo mostraba) — si se necesita alt text editable por imagen, hace falta un campo aparte junto al `MediaUpload` en cada Resource. No implementado — a definir con el Tech Lead si hace falta.
- **Archivos/áreas:** `app/Filament/Schemas/MediaUpload.php` (nuevo), `app/Filament/Schemas/MediaSelect.php` (deprecado, sin borrar), `app/Filament/Resources/{SliderResource,PageResource,PostResource}.php`.
- **Pendiente de verificación:** este sandbox no tiene PHP disponible — no se pudo correr `php artisan test`, `vendor/bin/pint --dirty` ni probar en vivo la subida/preview/borrado en Studio. Falta: (1) confirmar que `imageEditor()` no requiera una dependencia JS/paquete no instalado en este proyecto; (2) probar el flujo completo (subir, ver preview, editar registro existente con FK ya poblada — el id viejo debe resolver a preview correctamente vía `getUploadedFileUsing`, guardar de nuevo sin re-subir, quitar el archivo y confirmar que borra el `Media` y el archivo del disco).
- **Siguiente:** correr Pint + tests en local; probar el form de Studio contra el pedido; decidir si se necesita un campo de `alt_text` aparte.

**Fix same-day #2 (UX del Repeater de Slides):** el Tech Lead pidió 3 ajustes menores tras ver el form en Studio: (a) "Diapositivas" → **"Slides"** (se deja el anglicismo a propósito — "Diapositivas" se confunde con PowerPoint/Google Slides); (b) el Repeater de slides venía envuelto en un `Section::make('Diapositivas')` redundante (Section "Slides" conteniendo un campo también llamado "Slides") — se quitó el `Section` wrapper, el `Repeater::make('slides')` queda directo en el nivel superior del form; (c) los items del Repeater (cada slide) ahora arrancan **colapsados por default** (`->collapsed()` en el Repeater, además de `->collapsible()` que ya tenía). `itemLabel` fallback también actualizado de "Nueva diapositiva" a "Nuevo slide" por consistencia.
- **Archivos/áreas:** `app/Filament/Resources/SliderResource.php`.

**Fix same-day (reportado por el Tech Lead probando en vivo):** tras subir un archivo el preview se veía bien (misma sesión Livewire), pero al recargar el navegador y volver a editar el registro, el campo quedaba pegado en "Cargando... Esperando tamaño" para siempre. Causa: `getUploadedFileUsing()` armaba la URL de preview con `Media::url()`, que a propósito fuerza el host de la API pública (`stamless.urls.api` → `api.stamless.host`) para que la respuesta del API sea consumible desde un frontend en otro dominio (cica360). Studio vive en su propio host (`stamless.urls.studio` → `studio.stamless.host`) — FilePond, para archivos previsualizables (imagen/video), hace su propio `fetch()` contra esa URL para medir tamaño/tipo (no recibe esos datos del backend de entrada), y esa request cross-origin quedaba bloqueada porque `config/cors.php` solo cubre `v1/*`, no `storage/*`. Fix: nuevo helper privado `MediaUpload::previewUrl()` que resuelve la URL directo del driver del disco (`Storage::disk(...)->url($path)`) sin pasar por el host-completion de `Media::url()` — con disco local/public da una ruta relativa (mismo origen que Studio, sin CORS), y con R2/S3 el driver ya devuelve una URL absoluta propia (sin cambios de comportamiento ahí). `Media::url()` (usado por la API pública) no se tocó.

## 2026-08-30 — Ajustes de UX de Studio sobre ADR-027: CTA único por slide, reorganización de tabs, corrección de posiciones sembradas

- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead revisó el form real de Slides en Studio (screenshot del tab "Enlaces y Propiedades") y los 3 mockups finales de lanzamiento, y pidió 3 ajustes:
  1. **Un solo CTA por slide, sin Repeater.** Nuevo `LinkSchema::makeSingle(string $name = 'links')` — bindea los mismos campos que la variante `Repeater` (`LinkSchema::make()`, que **no se tocó**, sigue siendo usada por `PageResource`/`PostResource` para bloques que sí necesitan múltiples enlaces) directo a `"links.0.*"` vía dot-path, mismo patrón que `properties.*` ya usado en `PropertiesSchema`. `links` sigue siendo array en DB/API (un solo elemento) — no cambia el contrato público, solo la UX de edición (ya no se puede "agregar otro enlace" desde un slide).
  2. **Reorganización de tabs del form de Slides:** "Fondo y Multimedia" → "Fondo". "Enlaces y Propiedades" → "Enlaces" (CTA único a la izquierda junto al toggle de video; fieldset "Propiedades" —target de apertura/alt SEO/clase CSS/id HTML— a la derecha, en el espacio que dejó libre "Posición y Contenido" al mudarse). El tab de decoradores/efectos gana la sección "Posición y Contenido" y se renombra "Estilos" (elegido en vez de "Propiedades" —la otra opción que dio el Tech Lead— para no repetir el nombre del fieldset nuevo del tab "Enlaces").
  3. **Corrección de los valores sembrados de `position_container`:** al revisar de nuevo los 3 mockups (ahora provistos como imágenes individuales de alta resolución, "datos finales para lanzamiento"), el contenido de texto/CTA de las 3 slides está claramente anclado ABAJO (con bastante espacio vacío arriba, no centrado verticalmente) — se había sembrado `middle-left`/`middle-left`/`middle-center` en la vuelta anterior (ADR-027), corregido a `bottom-left`/`bottom-left`/`bottom-center` (la alineación horizontal `left`/`left`/`center` ya estaba correcta).
  - Detalle completo de la decisión (por qué `makeSingle()` en vez de tocar `make()`, por qué "Estilos" y no "Propiedades") documentado como actualización de **ADR-027** en `DECISIONS.md`.
- **Archivos/áreas:** `app/Filament/Schemas/LinkSchema.php` (método nuevo `makeSingle()`), `app/Filament/Resources/SliderResource.php` (tabs reorganizados), `database/seeders/Cliente0HomeSlidesSeeder.php` (posiciones corregidas).
- **Pendiente de verificación:** este sandbox no tiene PHP disponible. Falta correr `php artisan db:seed --class=Cliente0HomeSlidesSeeder` (o el seeder completo) para que los valores corregidos se reflejen en una BD ya sembrada, y confirmar visualmente en Studio que el tab "Enlaces" edita/guarda correctamente el CTA único (probar creación Y edición de un slide existente que ya tenía `links` como array — el dot-path `links.0.*` debería leer/escribir sobre el mismo primer elemento sin problema, pero no se pudo probar en vivo).
- **Siguiente:** correr seeders/tests/Pint en local; confirmar visualmente el form de Studio contra el pedido.

## 2026-08-30 — ADR-027: Slide gana `position_container`, `align_content`, decorador inferior configurable y efectos visuales de fondo

- **Agente/autor:** Claude
- **Qué se hizo:** el Tech Lead pidió, para el Hero de cica360 (ver `HERO-3-SLIDES-IMPORTANTS.png`), un set grande de campos de diseño configurables por slide — posición del contenedor de texto/CTA (9 combinaciones), alineación interna del contenido, un decorador SVG inferior (shape + color + opacidad-como-gradiente) y efectos visuales completos sobre la imagen de fondo (color, brillo, opacidad, blend-mode, 6 filtros CSS). Antes de migrar se auditó `SliderResource.php` y se encontró que el proyecto ya resuelve este tipo de campo (posición, alineación, decoradores con color) vía `properties` jsonb + `App\Filament\Schemas\PropertiesSchema` — así que los ~15 campos nuevos se agregaron como claves de `properties`, **no** como columnas dedicadas. Detalle completo de la decisión en **ADR-027** (`DECISIONS.md`). De paso, se eliminó `slides.description` (columna de texto libre sin uso real, a pedido explícito del Tech Lead) — esa sí requirió una migración real (`DROP COLUMN`).
  - 4 Enums PHP nuevos: `PositionContainerEnum`, `AlignContentEnum`, `DecoratorShapeEnum`, `BlendModeEnum` (todos `HasLabel`).
  - `PropertiesSchema::makeComponents()` pasa de 12 a 26 componentes disponibles (agrega `position_container`, `align_content`, `decorator_bottom_opacity` y los 10 `slide_background_*`; migra `decorator_top`/`decorator_bottom` de arrays hardcodeados a `DecoratorShapeEnum::class`).
  - `SliderResource.php`: quitado el campo `description` del tab "Contenido"; nuevo tab "Decorador y Efectos" en el form de Slides.
  - `Slide.php`: quitado `description` de `Fillable`. `SlideResource.php` (API): quitado `description` de la respuesta.
  - `Cliente0HomeSlidesSeeder.php`: las 3 slides del home ahora seedean `properties` completo (`position_container`/`align_content` específicos por slide, según la lectura de `HERO-3-SLIDES-IMPORTANTS.png`: slides 1-2 `middle-left`/`left`, slide 3 `middle-center`/`center`; decorador `wave` blanco sólido; resto de efectos en sus defaults).
  - `docs/context/api/stamless-api-v1.md` (repo cica360) actualizado con la forma completa de `properties` de un Slide y sus defaults.
- **Archivos/áreas:** `app/Enums/{PositionContainerEnum,AlignContentEnum,DecoratorShapeEnum,BlendModeEnum}.php` (nuevos), `app/Filament/Schemas/PropertiesSchema.php`, `app/Filament/Resources/SliderResource.php`, `app/Models/Slide.php`, `app/Http/Resources/Api/V1/SlideResource.php`, `database/migrations/2026_08_30_000001_drop_description_from_slides_table.php` (nuevo), `database/seeders/Cliente0HomeSlidesSeeder.php`. Consumido en el repo cica360: `src/lib/types.ts` (nuevo `SlideProperties`), `src/components/blocks/Hero.astro` (posición/alineación/decorador/efectos + animación de entrada fija, no configurable).
- **Pendiente de verificación:** este sandbox no tiene PHP disponible — no se pudo correr `php artisan migrate`, `php artisan test` ni `vendor/bin/pint --dirty`. Falta correr la migración nueva y la suite completa en local antes de dar esto por cerrado (revisar en particular que `SliderResource`/`SlideResource` sigan pasando con la columna `description` fuera).
- **Siguiente:** correr migración + tests + Pint en un entorno con PHP. El botón "play video" / `has_presentation_video` mencionado por el Tech Lead ("y boton play a video mas adelante") queda explícitamente fuera de esta vuelta — no implementado todavía en `Hero.astro`.

## 2026-08-30 — `MenuItem` API expone `is_home` resuelto

- **Agente/autor:** Claude
- **Qué se hizo:** el front (cica360) necesitaba excluir el link "Home" del menú de navegación (el logo ya enlaza a `/`), pero comparar `href === '/'` no es seguro — un item de menú puede apuntar a la home con un título/slug distinto ("Inicio", "Portada"). Se agregó `is_home` a la respuesta pública de `GET /menus/{slug}`, resuelto desde `Page.is_home` (la misma fuente que ya se administra en el Studio):
  - `MenuController::attachResolvedHrefs()` ahora también setea un atributo transitorio `resolved_is_home` en el mismo batch de queries que ya resolvía `href` (sin queries extra — reusa el `Page::whereIn(...)->get(['id', 'slug', 'is_home'])` existente).
  - `MenuItemResource` expone `'is_home' => (bool) $this->resolved_is_home`.
  - Siempre `false` para items `post`/`external`/`custom`.
  - Test nuevo: `tests/Feature/Api/V1/MenuApiTest.php` — cubre un item de home con título distinto ("Inicio"), un item normal, y un item custom, verificando `is_home` en los tres casos.
- **Archivos/áreas:** `app/Http/Controllers/Api/V1/MenuController.php`, `app/Http/Resources/Api/V1/MenuItemResource.php`, `tests/Feature/Api/V1/MenuApiTest.php`. Documentado también en `docs/context/api/stamless-api-v1.md` (repo cica360) y consumido en `src/lib/types.ts`/`src/components/Header.astro` (repo cica360).
- **Pendiente de verificación:** este sandbox no tiene PHP disponible — no se pudo correr `php artisan test` ni `vendor/bin/pint --dirty`. Falta correr `php artisan test --compact tests/Feature/Api/V1/MenuApiTest.php` y Pint localmente antes de dar el cambio por cerrado.
- **Siguiente:** correr el test y Pint en un entorno con PHP; si el test falla, revisar el binding de `is_active` en `MenuController::show` (el scope `with(['items' => ...])` filtra por `is_active`, no debería afectar este test ya que no seteamos esa columna explícitamente y su default es `true`).

## 2026-08-28 — HeadingFieldset reutilizable + CSS groupeado en Filament 5

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Creado `app/Filament/Schemas/HeadingFieldset.php` — clase reutilizable que encapsula `pretitle / title / subtitle` con el fieldset groupeado. Acepta `required: bool` y `label: string`.
  - Documentado DOM real de Filament 5: `fieldset.fi-sc-fieldset > div.fi-sc > div.fi-grid-col > div.fi-fo-field > div.fi-input-wrp`. Las clases de Filament 3 (`fi-fo-component-ctn`) no existen en v5.
  - CSS final en `public/css/filament/api-console.css`: esquinas redondeadas arriba (`:first-child`), abajo (`:last-child`), plano en medio.
  - Inyección CSS en Filament 5 resuelta con `->renderHook(PanelsRenderHook::HEAD_END, ...)`. Nota: `->viteTheme()` en Filament 5 **reemplaza** el tema (diferente a v3).
- **Archivos:** `HeadingFieldset.php` (nuevo), `SliderResource.php`, `PanelCmsProvider.php`, `api-console.css`
- **Siguiente:** ajuste fino de borders divisorios (usuario); integración WhatsApp CloudAPI.

---

## 2026-08-27 — Fix: `Media::url()` devolvía ruta relativa (404 en el frontend headless)

- **Agente/autor:** Claude, a pedido del Tech Lead (bug real reportado desde CICA360: `Failed to load resource... 404` en `localhost:4321/storage/media/*.webp`).
- **Qué se hizo:**
  - Causa raíz: `config/filesystems.php`, disco `public`, tiene `'url' => '/storage'` (relativo, default del skeleton de Laravel 13). `Media::url()` delegaba directo en `Storage::disk(...)->url($path)`, así que para media en disco `local`/`public` (el fallback de desarrollo, ver `MediaDiskEnum`) devolvía `/storage/media/xxx.webp` sin host — funciona para consumidores same-origin (previews dentro de Studio), pero es inútil para un frontend headless en otro origen por completo (CICA360/Astro corriendo en `localhost:4321`): el navegador resolvía esa ruta relativa contra su propio origen, no contra el backend, de ahí el 404.
  - Fix en `app/Models/Media.php`: `url()` ahora detecta si el resultado del disco ya es absoluto (`http://`/`https://`, caso de R2/S3 en producción — no se toca) y, si no lo es, lo completa con `config('stamless.urls.api')` (fallback a `config('app.url')`). En local, ambos hosts sirven físicamente el mismo `public/storage` (monolito único, múltiples vhosts sobre el mismo docroot — ver ARCHITECTURE.md §4), así que resuelve sin cambios de infraestructura.
  - Único call site real de `Media::url()` en código de la app: `App\Http\Resources\Api\V1\MediaResource` — no hay tests que aserten el valor exacto de esa URL, y no se tocó ningún otro consumidor.
  - No se pudo correr `php artisan test` en este sandbox (sin PHP) — pendiente de confirmación del humano.
- **Archivos/áreas:** `app/Models/Media.php`.
- **Siguiente:** correr la suite de tests localmente y confirmar en el navegador que las imágenes de CICA360 cargan desde `api.stamless.host/storage/media/...` en vez de `localhost:4321/storage/media/...`.

---

## 2026-08-27 — Rebrand completo Genesisly → Stamless (ADR-026)

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Nuevo `config/stamless.php`: claves `api`, `studio`, `platform`, `graphql`. `config/genesis.php` convertido en alias deprecated.
  - `.env.example`: `APP_NAME=Stamless`, URLs `stamless.host`, renombradas `APP_URL_CONSOLE`→`APP_URL_STUDIO` / `APP_URL_MANAGER`→`APP_URL_PLATFORM`. `SANCTUM_STATEFUL_DOMAINS` actualizado.
  - Todos los callsites `config('genesis.urls.*')` en código ejecutable reemplazados por `config('stamless.urls.*')`: `bootstrap/app.php` (×3), `routes/web.php` (×3), `routes/api.php` (×1), `PanelCmsProvider` (×1), `ApiPlayground.php` (×2), `api-documentation.blade.php` (×1), `api-playground.blade.php` (×2), `TestCase.php` (×1), `ExampleTest.php` (×1).
  - `PanelManagerProvider.php` → nuevo `PanelPlatformProvider.php` (`->id('platform')`, domain `stamless.urls.platform`, discover paths `Filament/Platform/`). Registrado en `bootstrap/providers.php`.
  - `PanelCmsProvider`: dominio actualizado a `stamless.urls.studio`.
  - Landing `home.blade.php`: `<title>Stamless</title>`, `<h1>Stamless</h1>`.
  - Seeder `PlatformSeeder`: `admin@genesisly.host` → `admin@stamless.com`.
  - Docs API (`v1.md`, `openapi.v1.yaml`): todos los hosts a `api.stamless.host` / `studio.stamless.host`.
  - Nueva ruta: `Route::domain(graphql.stamless.host)` → 404 vacío en cualquier path (host reservado, sin Lighthouse).
  - Ruta `genesis.home` → `stamless.home`.
  - `ARCHITECTURE.md`: tabla de hosts (×2) con las 5 filas Stamless, texto "Genesis CMS" → "Stamless", panel descriptions.
  - `DECISIONS.md`: ADR-022 marcado `Superseded by ADR-026`; ADR-026 agregado completo al índice y al body.
  - `CURRENT_STATE.md`: actualizado timestamp, estado de salud, referencias de host.
  - `tests/Feature/Filament/ApiTokensRegenerateTest.php`: comentario `console.genesisly.host` → `studio.stamless.host`.
  - `cors.php`: comentario actualizado.
  - `AppServiceProvider.php`: comentario actualizado.
  - **Verificación grep final**: 0 resultados `genesisly` en `app/ config/ resources/ database/seeders/ tests/ routes/ bootstrap/ .env.example`.
- **Archivos/áreas:** `config/`, `bootstrap/`, `routes/`, `app/Providers/`, `app/Filament/`, `resources/views/`, `database/seeders/`, `tests/`, `docs/`, `.env.example`
- **Siguiente:**
  - Actualizar `.env` local con las nuevas variables (`APP_URL_STUDIO`, `APP_URL_PLATFORM`).
  - Agregar 5 hosts en `/etc/hosts` y 5 vhosts SSL en MAMP PRO.
  - Regenerar token Sanctum de prueba local (el host cambió).
  - Correr `php artisan test --compact` para confirmar que todo pasa.
  - Continuar con frontend CICA360 (Astro vs Next.js — ADR pendiente).

---

## 2026-08-20 — Corrección final: método estático makeComponents() en PropertiesSchema

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Creado y expuesto el método estático `makeComponents()` en `PropertiesSchema.php` que retorna el listado crudo de campos de propiedades en formato de array.
  - Reemplazada la llamada `make()->getComponents()` en `SliderResource.php` por `makeComponents()`. Esto corrige la excepción `BadMethodCallException` puesto que `Group` en esta versión de Filament no expone un getter público de componentes en su interfaz macroable.
- **Archivos/áreas:**
  - `app/Filament/Resources/SliderResource.php`
  - `app/Filament/Schemas/PropertiesSchema.php`
- **Siguiente:**
  - Foco único en el frontend CICA360.

---

## 2026-08-20 — Corrección de ancho de campos en el Fieldset de Diseño y Posición

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Desempaquetado el array de componentes de `PropertiesSchema` directamente sobre el `Fieldset` utilizando `getComponents()`.
  - Esto eliminó el wrapper intermedio del componente `Group`, forzando a los campos internos (ColorPicker, Selects, Slider) a expandirse a todo lo ancho de las columnas asignadas dentro del Fieldset.
- **Archivos/áreas:**
  - `app/Filament/Resources/SliderResource.php`
- **Siguiente:**
  - Continuar con el desarrollo/maquetación del front de CICA360.

---

## 2026-08-20 — Refactor de Propiedades de diseño: Fieldset y reordenación de content_position

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Envueltas las propiedades de diseño de la derecha bajo un componente `Fieldset` con la leyenda "Diseño y Posición".
  - Reubicado el campo de selección `properties.content_position` para que se posicione después de la alineación del texto (`text_align`).
  - Configurado `content_position` para ocupar 1 columna (removiendo `columnSpanFull`), permitiendo que todos los campos del panel de propiedades se muestren en un grid balanceado de 2 columnas.
- **Archivos/áreas:**
  - `app/Filament/Resources/SliderResource.php`
  - `app/Filament/Schemas/PropertiesSchema.php`
- **Siguiente:**
  - Continuar con el desarrollo/maquetación del front de CICA360.

---

## 2026-08-20 — Refactor de LinkSchema: layout de 2 columnas y uso de Fieldset

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Modificado el esquema de Enlaces/CTAs (`LinkSchema.php`) para estructurar todos los campos del formulario en un layout limpio de 2 columnas.
  - Agrupados los campos de metadatos avanzados (Destino de apertura, Texto Alt SEO, Clase CSS, ID HTML) dentro de un componente `Fieldset` con la leyenda "Propiedades del enlace", separándolos visualmente y en 2 columnas internas.
- **Archivos/áreas:**
  - `app/Filament/Schemas/LinkSchema.php`
- **Siguiente:**
  - Foco único en el frontend CICA360.

---

## 2026-08-20 — Refactor final y compactación de la pestaña de Enlaces y Propiedades en el Slider

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Rediseñado el tab de **Enlaces y Propiedades** en una grilla de 4 columnas para aliviar la saturación visual.
  - Mitad izquierda (2 columnas) asignada al listado de Enlaces/CTAs (`LinkSchema`) y al interruptor/ID de video de presentación.
  - Mitad derecha (2 columnas) asignada a los campos de personalización de diseño (`PropertiesSchema`) dispuestos en una grilla interna de 2 columnas.
  - Creada e integrada la nueva propiedad de diseño `properties.content_position` en `PropertiesSchema.php` con opciones de alineación vertical y horizontal para posicionar el contenedor de títulos + botón (ej. `left-top`, `center-middle`, `right-bottom`, etc.).
  - Configurado `collapsible()->collapsed()` en las secciones principales del formulario (`General` y `Diapositivas`) para que comiencen contraídas por defecto en el panel.
- **Archivos/áreas:**
  - `app/Filament/Resources/SliderResource.php`
  - `app/Filament/Schemas/PropertiesSchema.php`
- **Siguiente:**
  - Continuar con el desarrollo/maquetación del front de CICA360.

---

## 2026-08-20 — Refactor de SliderResource para optimización de UX

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Optimizado el formulario de `SliderResource` para mejorar su UX en modo `slideOver()`.
  - Implementado un diseño en Grid de 3 columnas para separar el formulario "General" (1/3) de las "Diapositivas" (2/3) y evitar colisiones visuales.
  - Reemplazadas las secciones anidadas y pesadas dentro de cada diapositiva (Repeater) por un componente de pestañas (`Tabs` con pestañas para `Contenido`, `Fondo y Multimedia`, y `Enlaces y Propiedades`), simplificando drásticamente el espacio vertical y el orden mental.
  - Ampliado el ancho del slideover modal a `FiveExtraLarge` en las acciones `EditAction` y `CreateAction` (tanto en la tabla como en la cabecera de la página) para dar espacio cómodo a los campos.
- **Archivos/áreas:**
  - `app/Filament/Resources/SliderResource.php`
  - `app/Filament/Resources/SliderResource/Pages/ManageSliders.php`
- **Siguiente:**
  - Continuar con las tareas de maquetación del frontend CICA360.

---

## 2026-08-20 — Cierre de lado producto (API); foco único = front CICA360

- **Agente/autor:** Grok CLI (handoff explícito del humano)
- **Qué se hizo:** El humano declara **cerrado el lado producto/API**. No hay más trabajo de backend en esta fase. Documentado el único foco de la próxima sesión.
- **Cerrado de producto:**
  - REST v1 (`/v1/{tenant_slug}/...`, sin prefijo `/api`, host API Genesisly)
  - Auth Sanctum (Bearer, abilities `content:read` / `forms:submit`)
  - Envelope de error formalizado
  - Seeds CICA360 (tenant, contenido, menú, slider home, form contacto)
  - Playground + docs en Console
  - Hosts Genesisly (`api` / `console` / `manager` / landing)
- **Único foco al volver:** frontend CICA360 en shared hosting, consumiendo:
  - `GET /v1/cica360/pages`
  - `GET /v1/cica360/pages/{slug}`
  - `GET /v1/cica360/menus/menu-principal`
  - `GET /v1/cica360/sliders/home`
  - `POST /v1/cica360/forms/contacto/submit`
- **Fuera hasta que el sitio esté en el aire:** GraphQL, marca fina, polish de Console (Contacts Resource, FriendlyDate resto).
- **Archivos/áreas:** `docs/context/CURRENT_STATE.md`, `docs/context/TASK.md`, este log.
- **Siguiente:** P2 #11 — elegir Astro vs Next (static export) + ADR + scaffold.

---

## 2026-08-20 — Regenerar API Tokens (Sanctum) en Console

- **Agente/autor:** Claude (ticket: "Regenerar API Tokens (Sanctum) en Console").
- **Qué se hizo:** `App\Filament\Pages\ApiTokens` gana una acción de tabla "Regenerar" junto a "Revocar" (icono refresh, color `warning`). Al confirmar ("La clave anterior dejará de funcionar de inmediato."): borra el `PersonalAccessToken` actual, crea uno nuevo con `$user->createToken($name, $abilities, $expiresAt)` clonando `name`/`abilities` exactos; `expires_at` se clona tal cual si el token no había vencido, o se pide elegir una nueva ventana (Nunca/1/30/90/365 días, mismo `Select` que usa "Crear token") si ya estaba expirado — el form de la acción es condicional según `$record->expires_at?->isPast()`. El plaintext nuevo se muestra una sola vez reutilizando el mismo banner Alpine (`$plainTextToken`) que ya usaba "Crear token" — sin cambios de vista. Scoping: `abort_unless($record->tokenable_type === User::class && $record->tokenable_id === auth()->id(), 403)` dentro del `action()` — un user no puede regenerar el token de otro user del mismo tenant (403); un token de otro tenant ni siquiera resuelve como record (`getTableQuery()` ya scopea por tenant), así que da 404 antes de llegar al check de ownership.
- **Tests:** `tests/Feature/Filament/ApiTokensRegenerateTest.php` (nuevo, primer test de este repo que ejercita un `Filament\Pages\Page` custom vía `Livewire::test()` + `callTableAction()` — patrón: `actingAs($user)` + `Filament::setCurrentPanel(Filament::getPanel('cms'))` + `Filament::setTenant($tenant)`, sin necesidad de simular el dominio real de Console porque `Livewire::test()` monta el componente directo, no pasa por el router). 6 tests: token viejo deja de autenticar (`PersonalAccessToken::findToken()` devuelve `null`) y el nuevo sí resuelve "limpio" (`last_used_at = null`); abilities no cambian; expiración se clona si no había vencido; expiración vencida exige elegir una nueva (falla de validación si no se manda, éxito si se manda); un user no regenera el token de otro user del mismo tenant (403); un user no regenera un token de otro tenant (`ActionNotResolvableException`, ver abajo). Se verificaron a mano las firmas exactas de los helpers de testing de Filament/Livewire/Sanctum contra el código real en `vendor/` (presente en el repo) antes de escribir los tests, en vez de asumir la API de memoria — pero no había forma de ejecutar la suite en este sandbox (sin PHP/Composer).
- **Corrección tras la corrida real del humano**: `php artisan test --filter=ApiTokensRegenerateTest` dio **5/6 en verde**; el único fallo fue `a user cannot regenerate a token from a different tenant`, que esperaba un 404 HTTP limpio pero el framework tiraba `Filament\Actions\Exceptions\ActionNotResolvableException` (excepción PHP interna, no HTTP) — Filament no logra resolver el `record` cuando no está dentro de `getTableQuery()` (que ya scopea por tenant) y aborta ahí mismo, **antes** de llegar al `action()` closure donde vive el check de ownership de esta feature. No era un bug de la feature (el token de otro tenant nunca se toca, el aislamiento funciona) sino una expectativa incorrecta del test — corregido para esperar `ActionNotResolvableException` explícitamente (con `try/catch` + `$this->fail()` si no se lanza) en vez de un status 404. **Pendiente**: re-correr `php artisan test --filter=ApiTokensRegenerateTest` para confirmar 6/6 en verde tras este fix (no se pudo re-ejecutar desde este sandbox).
- **No se tocó:** ninguna ruta de API/GraphQL/landing, ningún seed de contenido, ninguna migración (se reutilizan las columnas `last_four`/`abilities`/`expires_at` ya existentes de ADR-018/019) — no hizo falta ADR nuevo, es una Action de Filament sobre el modelo ya aceptado.
- **Archivos/áreas:** `app/Filament/Pages/ApiTokens.php`, `tests/Feature/Filament/ApiTokensRegenerateTest.php` (nuevo).
- **Siguiente:** correr `php artisan test` localmente y confirmar los 6 tests nuevos en verde junto con el resto de la suite; probar manualmente en `console.genesisly.host` → Desarrolladores → API Tokens.

---

## 2026-08-20 — Auditoría y consolidación del ecosistema de dominios/rutas/seguridad/excepciones

- **Agente/autor:** Claude (Tech Lead pidió revisar todos los cambios directos de arquitectura/infraestructura hechos en dominios/subdominios, convención de rutas, seguridad y excepciones — varios de esos cambios los había hecho otro agente, Antigravity/Gemini, en la misma jornada, ver entrada de abajo — para dejar el estado 100% documentado y consistente para cualquier agente futuro).
- **Qué se hizo:** Auditoría cruzada de código real vs. docs (no se asumió nada, se leyó cada archivo fuente): `routes/api.php`, `routes/web.php`, `bootstrap/app.php`, `config/genesis.php`, `config/cors.php`, `app/Http/Middleware/ResolveTenant.php`, `PanelCmsProvider`/`PanelManagerProvider`, `.env.example`, `ApiPlayground.php`, `tests/TestCase.php` + tests de `Api/V1`, `DECISIONS.md` (ADR-012 a ADR-025), `docs/api/v1.md`, `docs/api/openapi.v1.yaml`. Confirmado: la migración a `Route::domain()` + `apiPrefix: ''` (ADR-025, sin prefijo `/api`) ya estaba completa y consistente en código y tests (`TestCase::prepareUrlForRequest()` resuelve el host de la API automáticamente para cualquier request a `/v1/...`). Se encontraron y corrigieron 6 inconsistencias reales:
  1. **Bug funcional real**: `config/cors.php` seguía con `'paths' => ['api/*']` después de adoptar ADR-025 — como `HandleCors` matchea por path (no por Host) y la API ya no tiene ese prefijo, CORS había dejado de aplicarse a la API **en silencio**, sin error visible. Corregido a `'paths' => ['v1/*']`.
  2. `routes/api.php`: comentario de cabecera todavía decía "vive bajo `/api/v1/{tenant_slug}`" — corregido.
  3. `docs/context/ARCHITECTURE.md` §4: URL del health check documentada como `https://api.genesisly.host/api/v1/health` (con `/api` de más) — corregida a `/v1/health`. También corregida en `CURRENT_STATE.md`.
  4. `docs/context/ARCHITECTURE.md`: quedaban restos muy desactualizados de una versión pre-Sanctum/pre-Laravel-13 del documento (tabla de stack con "Auth: Sanctum/Passport (TBD)", "Laravel 11/12", fecha de última actualización 2026-08-11, sección §9 con un borrador de API sin auth ni `{tenant_slug}`) — actualizados/reescritos para reflejar el estado real, con cross-links explícitos a ADR-012/016/018/020/023/024/025.
  5. `docs/api/v1.md` y `docs/api/openapi.v1.yaml`: los ejemplos de response de error (403, 422) todavía mostraban el formato **pre-ADR-024** (mensaje `"El token no pertenece a este tenant."` en vez del mensaje fijo genérico "No tenés permiso para este recurso.", y `errors.detail` de texto libre en vez de `errors.code`+`errors.fields` para 422) — corregidos en ambos archivos para que coincidan exactamente con lo que devuelve `bootstrap/app.php` hoy. También se agregó `errors.code` a los ejemplos 401/404/429 del OpenAPI que no lo tenían.
  6. `docs/context/ARCHITECTURE.md` §"Seguridad y Excepciones": expandida con la regla crítica de "mensaje fijo por status, nunca `$e->getMessage()`" (el bug de `prepareException()`/Sanctum documentado en ADR-024) para que ningún agente futuro reintroduzca ese bug al tocar el handler.
- **Verificado, no solo asumido:** se confirmó con un script de balance de paréntesis/llaves/corchetes (sin PHP en este sandbox) que `config/cors.php`, `routes/api.php`, `routes/web.php` y `bootstrap/app.php` quedaron sintácticamente balanceados tras los edits.
- **No se tocó**: ningún código de negocio, ninguna decisión de arquitectura nueva (todo lo corregido ya estaba decidido en ADRs existentes, especialmente ADR-025) — no se creó ADR nuevo a propósito, esto es un pase de consistencia, no una decisión.
- **Archivos/áreas:** `config/cors.php`, `routes/api.php`, `docs/context/ARCHITECTURE.md`, `docs/context/CURRENT_STATE.md`, `docs/api/v1.md`, `docs/api/openapi.v1.yaml`.
- **Siguiente:** el estado de dominios/rutas/seguridad/excepciones ya está consolidado y verificado end-to-end (código + tests + docs se dicen lo mismo). Retomar el Filament Resource de Contacts o decidir el frontend Cliente 0.

---

## 2026-08-20 — Eliminación de dominios hardcodeados en todo el proyecto

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Removidos **todos** los dominios/subdominios hardcodeados fuera de `.env` y comentarios de documentación:
    - `routes/web.php` L5: `'genesisly.host'` → `parse_url(config('app.url'), PHP_URL_HOST)` (mismo patrón que ya usaban las demás route groups del archivo).
    - `config/cors.php`: `allowed_origins` (array estático con `http/https://genesisly.host`) y `allowed_origins_patterns` (regex hardcodeada) → ambos derivados dinámicamente de `config('app.url')`.
    - `resources/views/public/home.blade.php` footer: texto literal `genesisly.host` → `{{ parse_url(config('app.url'), PHP_URL_HOST) }}`.
    - `database/seeders/Cliente0Seeder.php`: constante `CONSOLE_DOMAIN = 'console.genesisly.host'` → método estático `consoleDomain()` que lee `config('genesis.urls.console')`.
    - `tests/Feature/ExampleTest.php`: todos los URLs absolutos con dominio hardcodeado → helpers privados `landingUrl()` / `apiUrl()` que derivan de `config('app.url')` y `config('genesis.urls.api')`.
    - `tests/TestCase.php`: fallback literal `'api.genesisly.host'` en `prepareUrlForRequest()` → `config('genesis.urls.api', config('app.url'))`.
  - Configurado `composer dev` para excluir el proceso `server` (`php artisan serve`) via `DevCommands::except('server')` en `AppServiceProvider::boot()` — necesario porque MAMP PRO ya sirve la app en `https://genesisly.host` y el proceso `server` conflictuaba.
  - Instalado paquete `fontaine` (npm) para eliminar warning de Vite sobre fallbacks de fuente optimizados.
- **Archivos/áreas:**
  - `routes/web.php`
  - `config/cors.php`
  - `resources/views/public/home.blade.php`
  - `database/seeders/Cliente0Seeder.php`
  - `tests/Feature/ExampleTest.php`
  - `tests/TestCase.php`
  - `app/Providers/AppServiceProvider.php`
  - `package.json` / `package-lock.json` (fontaine)
- **Regla establecida:** Ningún dominio o subdominio puede estar hardcodeado en código ejecutable. Toda referencia a dominios debe ir a través de `config('app.url')`, `config('genesis.urls.api')`, `config('genesis.urls.console')`, o `config('genesis.urls.manager')`, que a su vez leen de `APP_URL`, `APP_URL_API`, `APP_URL_CONSOLE`, `APP_URL_MANAGER` en `.env`.
- **Siguiente:**
  - Correr `php artisan test` para confirmar que los tests refactorizados siguen en verde.
  - Continuar con las tareas pendientes de TASK.md.

## 2026-08-20 — Ocultamiento de bienvenida de Laravel en subdominios y API root segura

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Corregido el problema de visualización de la pantalla de bienvenida por defecto de Laravel ("Let's get started") al acceder a la raíz del subdominio de API (`api.genesisly.host`).
  - La raíz `/` del subdominio de API ahora retorna una respuesta HTTP `404 Not Found` completamente vacía (`response('', 404)`), haciendo que la raíz de la API luzca como si no estuviera configurada (silencio total de cara a bots y escáneres).
  - Implementado el endpoint público `/api/v1/health` en `api.genesisly.host` para realizar pruebas de conexión a la base de datos y proveer un mecanismo estándar para ping-pong y monitoreo de uptime.
  - Modificado el fallback genérico de la ruta raíz `/` de Laravel para redirigir de manera segura a la landing page principal (`genesisly.host`), y cualquier intento de acceder a `/api/*` o `/graphql/*` en el dominio principal (`genesisly.host`) o a `/graphql/*` en el de la API (`api.genesisly.host`) es capturado para devolver una respuesta `404` completamente vacía.
  - Creados nuevos tests automatizados en `tests/Feature/ExampleTest.php` para validar el enrutamiento correcto de los dominios principal, API (404 root, 200 health, 404 graphql), rutas `/api` y `/graphql` en landing domain, y fallbacks no mapeados.
- **Archivos/áreas:**
  - `routes/web.php`
  - `tests/Feature/ExampleTest.php`
- **Siguiente:**
  - Continuar con el Filament Resource de Contacts o la API v1.

## 2026-08-19 — Envelope de error API formalizado (ADR-024, extiende ADR-023)

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - Ticket explícito: formalizar el envelope de error de `/api/*` con una tabla de mapeo completa (excepción → status → `errors.code` snake_case → mensaje), reutilizando el helper existente (`ApiResponds`) en vez de duplicar lógica en el handler global, y agregar cobertura de test para los casos principales.
  - Nueva clase `App\Support\Api\ErrorEnvelope` (estática, no trait): el handler de excepciones vive en un `Closure` de `bootstrap/app.php` sin `$this`, así que no puede usar un trait — pero sí un método estático. `App\Http\Concerns\ApiResponds::error()` ahora delega en `ErrorEnvelope::make()` en vez de armar el array a mano — un error devuelto por un controller y uno armado por una excepción no capturada tienen garantizado el mismo shape exacto. (Se descartó explícitamente intentar llamar un método estático de un trait directamente como `ApiResponds::metodo()` sin una clase que lo use — la semántica de PHP para eso es ambigua y no se pudo verificar en este sandbox sin PHP; una clase estática dedicada es inequívoca.)
  - `bootstrap/app.php` reescrito: mapa completo `status` → `errors.code` → `message` para `AuthenticationException` (401, distingue `unauthenticated` sin token de `token_invalid` con token presente pero inválido — antes, ADR-023, ambos casos compartían el mismo `errors.code`), `AuthorizationException`/abilities de Sanctum (403, `forbidden`, siempre mensaje genérico en español — el default de Sanctum es `"Invalid ability provided."` en inglés y nunca debe llegar al cliente tal cual), `ModelNotFoundException`/`NotFoundHttpException` (404, `not_found`, preserva mensajes custom propios como `"Tenant no encontrado."` de `ResolvesTenant`/ADR-018 cuando existen), `ValidationException` (422, `validation`, con `errors.fields`), `ThrottleRequestsException` (429, `too_many_requests`, agregado explícito — antes caía por el fallback genérico de `HttpExceptionInterface`), y `Throwable` no controlado (500, `server_error`, `errors.detail` con el mensaje real **solo si `APP_DEBUG=true`**, nunca un stack trace, nunca en producción).
  - `POST forms/{slug}/submit`: los campos requeridos de un `Form` son datos configurables por tenant (`FormField.is_required`), no un rule set estático de Laravel — así que no hay un `FormRequest`/`Validator` real detrás, y `ContactSubmissionService::assertRequiredFieldsPresent()` tiraba un `InvalidArgumentException` genérico con un string libre (`"Faltan campos requeridos: email, message"`), sin desglose por campo. Se creó `App\Exceptions\Api\MissingRequiredFieldsException` (extiende `InvalidArgumentException` A PROPÓSITO, no `ValidationException`) con un método `fields()` que arma `{ campo: [mensajes] }`. Se evaluó y descartó migrar a `Illuminate\Validation\Validator` real: hubiera roto `ContactSubmissionServiceTest::test_it_throws_when_a_required_field_is_missing()` (hace `expectException(InvalidArgumentException::class)`, y `ValidationException` no extiende esa clase) y dependía de archivos de idioma `resources/lang/es/validation.php` no confirmados en este sandbox. `FormSubmissionController::store()` gana un catch específico para la nueva excepción (antes del catch genérico de `InvalidArgumentException`, que se mantiene como fallback).
  - Tests: reforzados `ApiAuthTest` (mensaje/código exacto del 401 sin token dropeando "el header" del texto — ver nota de wording más abajo —, `errors.code: token_invalid` para token inválido, `errors.code: forbidden` + mensaje genérico para token de otro tenant y para ability faltante) y `PageApiTest` (`errors.code: not_found` en tenant inexistente/inactivo). Test file nuevo `tests/Feature/Api/V1/FormSubmissionApiTest.php` — `forms/submit` no tenía NINGUNA cobertura a nivel API todavía (solo a nivel de servicio en `ContactSubmissionServiceTest`): cubre cuerpo vacío → 422 con `errors.fields` completo, y el happy path 201.
  - Wording: el mensaje 401 "sin token" pasó de "No autenticado. Enviá un token Bearer en el header Authorization." (ADR-023) a "No autenticado. Enviá un token Bearer en Authorization." (ADR-024, texto pedido explícitamente en el ticket) — actualizado en el test y en `docs/v1.md`.
  - `docs/v1.md` (tabla de errores, client-facing): agregada columna `errors.code`, actualizados todos los ejemplos de respuesta para incluir el `errors.code` real de cada caso, agregada nota de que `errors.code` es estable para lógica de cliente y `message` puede cambiar de texto.
  - Extra (no pedido explícitamente, pero directamente en el espíritu del ticket — "formalizar el envelope para TODA la API"): los 404 que devuelven los controllers directamente sin pasar por una excepción (`PageController`/`MenuController`/`MediaController`/`SliderController`/`PostController`/`FormSubmissionController`, cada uno con su `$this->error('X no encontrada.', 404)`) ahora también incluyen `errors.code: 'not_found'` — antes solo los 404 que SÍ pasaban por una excepción (vía `ResolvesTenant`) tenían `errors.code`; los explícitos no tenían `errors` en absoluto. Cambio mecánico y seguro (agregar un tercer argumento a una llamada ya existente), ningún test dependía de la ausencia de esa clave.
- **Archivos/áreas:** `bootstrap/app.php`, `app/Support/Api/ErrorEnvelope.php` (nuevo), `app/Http/Concerns/ApiResponds.php`, `app/Exceptions/Api/MissingRequiredFieldsException.php` (nuevo), `app/Services/ContactSubmissionService.php`, `app/Http/Controllers/Api/V1/{FormSubmissionController,PageController,MenuController,MediaController,SliderController,PostController}.php`, `tests/Feature/Api/V1/{ApiAuthTest,PageApiTest}.php`, `tests/Feature/Api/V1/FormSubmissionApiTest.php` (nuevo), `docs/v1.md`, `docs/context/DECISIONS.md` (ADR-024)
- **Siguiente:** correr `php artisan test` — no se pudo ejecutar desde este sandbox (sin PHP disponible). Prestar atención especial a que `ContactSubmissionServiceTest` siga pasando sin cambios (la nueva excepción es un `InvalidArgumentException` a propósito, no debería romper nada, pero es la garantía más frágil de este cambio).

### Follow-up (mismo día): bug real encontrado por el humano al correr `php artisan test`

El humano corrió la suite completa y mandó screenshot: 25/26 en verde, 1 falla real —
`ApiAuthTest::test_token_without_the_required_ability_is_forbidden` esperaba el mensaje
genérico `'No tenés permiso para este recurso.'` y recibió `'Invalid ability provided.'`
(el mensaje default de Sanctum, en inglés, filtrado tal cual).

Causa raíz (leída del vendor, no supuesta): `Illuminate\Foundation\Exceptions\Handler::render()`
llama a `prepareException($e)` **antes** de correr cualquier callback registrado con
`$exceptions->render()`, y `prepareException()` convierte incondicionalmente TODA
`AuthorizationException` (incluida `Laravel\Sanctum\Exceptions\MissingAbilityException`,
que extiende esa clase) en `AccessDeniedHttpException` — una clase completamente
distinta que ya NO es `instanceof AuthorizationException`. Mi código original tenía
`$e instanceof AuthorizationException => 'No tenés permiso...'` como caso especial
ANTES del fallback genérico `$e->getMessage() ?: '...'`, pero esa rama nunca podía
matchear (el tipo real ya cambió), así que siempre caía al fallback, que sí preservaba
`$e->getMessage()` — y ese mensaje resultó ser el default crudo de Sanctum.

Fix: se sacó la dependencia de `$e->getMessage()` como fallback para 401/403/404/429 —
ahora el `message` es **fijo por status**, sin excepciones, tal como pedía la tabla
original del ticket. El detalle específico de la excepción (cuando existe) solo se
expone en `errors.detail`, y únicamente para `500` bajo `APP_DEBUG=true` — no para
403/404, para no arriesgar filtrar texto de librerías de terceros en esos casos
tampoco. Se agregó un comentario extenso en `bootstrap/app.php` explicando el
comportamiento de `prepareException()` para que no se vuelva a asumir que
`instanceof AuthorizationException` funciona ahí adentro.

- **Archivos/áreas:** `bootstrap/app.php`
- **Siguiente:** re-correr `php artisan test` — debería quedar 26/26 en verde ahora (no se pudo confirmar desde este sandbox, sin PHP).

---

## 2026-08-19 — Fix: 401 de la API filtraba `route('login')` (ADR-023)

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - Ticket reportado con repro exacto: `GET /v1/{tenant}/pages` sin `Authorization` devolvía `422` con `{"success":false,"message":"Route [login] not defined.","status_code":422}` en vez de un `401` limpio.
  - Investigado leyendo el vendor de Laravel directamente (no supuesto): `Illuminate\Foundation\Configuration\ApplicationBuilder::withMiddleware()` registra por defecto `redirectGuestsTo(fn () => route('login'))` **antes** de correr el callback de `bootstrap/app.php`. Esta app es 100% headless y nunca definió una ruta `login`. Cuando `Illuminate\Auth\Middleware\Authenticate::unauthenticated()` evalúa `$request->expectsJson() ? null : $this->redirectTo($request)` y `expectsJson()` da `false` (clientes que no mandan `Accept: application/json` — curl liso, herramientas que no lo declaran), intenta resolver `route('login')`, que no existe, y tira `Symfony\Component\Routing\Exception\RouteNotFoundException` (subclase de `\InvalidArgumentException`) **antes** de que la `AuthenticationException` real llegue a construirse. Nuestro handler de `bootstrap/app.php` clasifica esa `InvalidArgumentException` como `422` (correcto para otros casos), de ahí el código y mensaje equivocados. Los tests existentes de `ApiAuthTest` no lo detectaban porque todos usan `getJson()`, que fuerza `Accept: application/json` y evita el bug — confirmado que es un gap real de cobertura, no una casualidad.
  - Fix en `bootstrap/app.php`: `$middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('login'))` — para `/api/*` el guest nunca intenta redirigir a nada, sin importar el header Accept, así que `AuthenticationException` se construye normal y la maneja el `$exceptions->render()` que ya existía (ADR-018).
  - De paso, mejora pedida explícitamente: el envelope 401 ahora distingue "no mandaste token" ("No autenticado. Enviá un token Bearer en el header Authorization.") de "mandaste un token pero es inválido/expiró/fue revocado" ("Token inválido o expirado.") — mirando si el request trae `Authorization: Bearer ...` (Sanctum no lanza excepciones distintas para cada caso, así que esa es la única señal disponible). Ambos casos agregan `errors: {"code": "unauthenticated"}` al payload. `AuthorizationException` sigue devolviendo `403` sin cambios — no se tocó nada de eso ni las abilities de Sanctum.
  - 3 tests nuevos en `ApiAuthTest`: (1) reproduce el escenario exacto del bug usando `$this->get()` (no `getJson()`, a propósito, para no mandar `Accept: application/json`) y confirma `401` sin la palabra "login" en el body; (2) confirma el mensaje distinto para token inválido (`Bearer token-que-no-existe`); (3) el test original de "sin token" ahora también verifica `message` y `errors.code`.
  - No se creó ninguna ruta `login` web (a propósito, según lo pedido) — el mismo problema late para rutas `web/*` protegidas por auth si algún día existieran, pero hoy `routes/web.php` no tiene ninguna, así que queda fuera de alcance.
  - Escrito ADR-023 (corto) documentando causa raíz, decisión y alternativas descartadas (crear ruta login dummy; parchear el mensaje de la excepción en vez de evitar que ocurra).
- **Archivos/áreas:** `bootstrap/app.php`, `tests/Feature/Api/V1/ApiAuthTest.php`, `docs/v1.md` (tabla de errores 401 actualizada a los dos mensajes reales), `docs/context/DECISIONS.md` (ADR-023)
- **Siguiente:** correr `php artisan test` — no se pudo ejecutar desde este sandbox (sin PHP disponible), pendiente de confirmación del humano. Si algo no compila, revisar primero `bootstrap/app.php` (es el archivo más sensible de este fix).

---

## 2026-08-18 — Landing page pública tipo teaser (estilo Apple) en genesisly.host

- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Rediseñada la vista `public.home` en `resources/views/public/home.blade.php` para actuar como un teaser estilo Apple extremadamente minimalista (silencio visual completo sin cajas ni bordes, alineación óptica ligeramente elevada, tipografías e incrementos de tamaño fluidos, animaciones sutiles de entrada, fondo oscuro #0B0C0E y footer dividido en los extremos: 'genesisly.host' y 'Pronto' en dorado).
  - Registrada la ruta en `routes/web.php` condicionada al dominio `genesisly.host` para evitar conflictos con otras rutas y asegurar que el dominio principal sirva la landing.
  - Verificada la compatibilidad y correcto funcionamiento ejecutando la suite de pruebas `php artisan test` con resultado exitoso al 100% (26 tests pasados).
- **Archivos/áreas:**
  - `resources/views/public/home.blade.php`
  - `routes/web.php`
- **Siguiente:**
  - Retomar el Filament Resource de Contacts (#19b) o extender FriendlyDate.

## 2026-08-17 — Fix: falso "sombreado de selección" en bloques de código de API Documentation (modo oscuro)

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - El humano reportó (con screenshot) que el JSON de ejemplo en **API Documentation** se veía con una caja redondeada de fondo por cada línea, como si el texto estuviera seleccionado — confuso para el usuario.
  - Investigado leyendo el CSS propio (no había ninguna regla de `background` en `.gnss-json-*` ni en `.token.*` de Prism) y descartando `::selection` (ni nuestro CSS ni el bundle de Filament definen uno que aplique acá — los únicos `::selection` de Filament son de CodeMirror, no relevantes). La causa real: especificidad CSS. `.gnss-prose pre code { background: none; ... }` (especificidad 0,1,2) está pensada para resetear el fondo del código inline (`.gnss-prose code`, pensado para texto entre backticks sueltos) cuando ese `<code>` es en realidad el que envuelve un fence ```` ```json ```` completo dentro de un `<pre>`. Pero `html.dark .gnss-prose code { background: rgb(255 255 255 / 0.08); ... }` (especificidad 0,2,2) es MÁS específica, así que en modo oscuro gana ella igual, sin importar el orden en el archivo. Al ser `<code>` un elemento inline envolviendo contenido de varias líneas, ese fondo se renderiza como una caja redondeada separada por cada línea visual — visualmente indistinguible de una selección de texto.
  - Fix: nueva regla `html.dark .gnss-prose pre code { background: none; }`, con la misma especificidad exacta que la regla que ganaba, para neutralizarla específicamente dentro de bloques `<pre>` sin tocar el estilo de código inline normal (que sigue viéndose bien fuera de los fences).
- **Archivos/áreas:** `public/css/filament/api-console.css`
- **Siguiente:** confirmar en browser (modo oscuro) que el JSON de ejemplo ya no muestra esa caja por línea.

---

## 2026-08-17 — Limpieza de lenguaje interno en la documentación pública de la API

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - El humano señaló que `docs/v1.md` (mostrado tal cual dentro de Console → **API Documentation**, es lo que lee el desarrollador del frontend del tenant) tenía frases como *"Está pensada para un único idioma por MVP"* y *"Desde ADR-018..."* — jerga interna de gestión de proyecto que un cliente final no entiende, y que además sugiere que la plataforma está en una "etapa de prueba" (riesgo real: el cliente no quiere dejar sus datos en algo que suena a beta/incompleto).
  - Barrido completo de `docs/v1.md` y `docs/api/openapi.v1.yaml` (el spec OpenAPI vinculado desde la misma página) sacando: toda mención a "MVP", toda referencia a `ADR-XXX`, y el link/mención a `CURRENT_STATE.md` (documento interno de gestión). Reescrito manteniendo el contenido técnico real, solo sin el envoltorio de "esto es un plan interno" (ej. "Está pensada para un único idioma por MVP" → "Actualmente opera en un único idioma"; "Desde ADR-018, toda la API requiere..." → "Toda la API requiere...").
  - La sección "Pendientes conocidos (honestidad ante todo)" se renombró a "Limitaciones actuales" y se le sacaron los ítems que revelaban roadmap/bloqueadores internos (ej. "bloqueador de Cloudflare R2", "cambio de contrato público, pendiente como su propio paso") — quedan solo limitaciones técnicas reales y neutras (sin rotación automática de tokens, sin validación de `mime_type`, campos de media en `null` hasta que se cargue el archivo).
  - De paso se encontró y corrigió una inconsistencia real (no cosmética): los ejemplos de cURL/fetch y la sección `servers` del OpenAPI usaban `.../v1/...` sin el prefijo `/api`, mientras que la sección "Base URL" del mismo documento (y las rutas reales de `routes/api.php`) sí lo llevan (`.../v1/...`). Un desarrollador copiando esos ejemplos tal cual habría pegado contra una URL que no existe. Unificado a `/v1/...` en los tres lugares.
  - **Alcance de la limpieza**: solo se tocaron los dos archivos client-facing (`docs/v1.md`, `docs/api/openapi.v1.yaml`). Los docblocks de PHP en `app/Filament/Pages/*.php` (`ApiTokens`, `ApiPlayground`, `ApiDocumentation`, `Preferences`) siguen citando ADRs a propósito — son comentarios de código para desarrolladores/agentes, nunca se renderizan para el usuario final, así que no aplica la misma regla.
- **Archivos/áreas:** `docs/v1.md`, `docs/api/openapi.v1.yaml`
- **Siguiente:** tener esta regla presente para cualquier documentación nueva de cara al cliente (nunca "MVP"/ADRs/docs internos ahí) — ya quedó anotada en el handoff de `TASK.md`.

---

## 2026-08-17 — Fix: link a openapi.v1.yaml roto (404) en API Documentation

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - El humano reportó un 404 en `console.genesisly.host/cica360/openapi.v1.yaml` al clickear un link dentro de **API Documentation**.
  - Causa: `docs/v1.md` (la fuente de la documentación) tiene un link relativo `[docs/api/openapi.v1.yaml](./openapi.v1.yaml)` — correcto para navegar el repo (GitHub, editor), pero `App\Filament\Pages\ApiDocumentation` renderiza ese markdown DENTRO de una page Filament servida en `/{tenant}/api-documentation`, así que el browser resolvía el relativo contra esa URL en vez de contra la raíz del repo → `/{tenant}/openapi.v1.yaml`, ruta que nunca existió.
  - Fix en dos partes: (1) nueva ruta `GET /openapi.v1.yaml` en `routes/web.php`, scopeada al dominio de Console vía `config('genesis.urls.console')`, sirviendo el contenido crudo de `docs/api/openapi.v1.yaml` con `Content-Type: application/yaml` — sin autenticación a propósito, es un contrato de API público pensado para cargarse en Swagger UI/Postman, no datos de tenant. (2) `ApiDocumentation::getMarkdownHtml()` ahora reescribe `href="./openapi.v1.yaml"` → `href="{{ route('docs.openapi-yaml') }}"` después de convertir el markdown a HTML.
- **Archivos/áreas:** `routes/web.php`, `app/Filament/Pages/ApiDocumentation.php`
- **Siguiente:** confirmar en browser que el link ahora descarga/muestra el YAML en vez de 404.

---

## 2026-08-17 — Menú del avatar: "Preferencias" y "Cambiar contraseña"

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - Pedido del humano: en el dropdown del avatar (que hasta ahora solo tenía el toggle de tema claro/oscuro/sistema y "Salir"), agregar accesos directos a "Preferencias" y a un cambio de contraseña.
  - Creada `App\Filament\Pages\ChangePassword`: page nueva con `$shouldRegisterNavigation = false` (a propósito no aparece en el sidebar — su único acceso es el dropdown del avatar, tal como se pidió). Formulario con `current_password` (`->currentPassword()`, valida contra el guard correcto vía Filament), `password` (`Password::default()` + `->confirmed()`) y `password_confirmation`. Guarda con `$user->update(['password' => $data['password']])`, apoyándose en el cast `'hashed'` que ya tiene `User` — no hace falta `Hash::make()` manual.
  - `PanelCmsProvider::panel()` gana `->userMenuItems([...])` con dos `Filament\Navigation\MenuItem`: "Preferencias" (ícono `heroicon-o-adjustments-horizontal`, URL vía `Preferences::getUrl()`) y "Cambiar contraseña" (ícono `heroicon-o-lock-closed`, URL vía `ChangePassword::getUrl()`). Se agregan a los ítems nativos de Filament (toggle de tema, Salir) sin reemplazarlos.
  - Verificado el API real de Filament antes de escribir código (no se asumió nada): `Panel::userMenuItems(array $items)` acepta `Action | Closure | MenuItem`; `Filament\Navigation\MenuItem` tiene `label()`/`icon()`/`url()`/`sort()` confirmados por lectura directa de `vendor/filament/filament/src/Navigation/MenuItem.php`; `TextInput::currentPassword()` confirmado en `vendor/filament/forms/src/Components/TextInput.php:66`.
  - El formulario/vista sigue el mismo patrón ya establecido por `Preferences` (misma sesión, ADR-021): `HasForms`/`InteractsWithForms`, `Schema` con `->statePath('data')`, blade mínimo con `<x-filament-panels::page>` + `.gnss-card`.
- **Archivos/áreas:** `app/Filament/Pages/ChangePassword.php` (nuevo), `resources/views/filament/pages/change-password.blade.php` (nuevo), `app/Providers/Filament/PanelCmsProvider.php` (`->userMenuItems()`), `docs/context/DECISIONS.md` (nota agregada a ADR-021)
- **Siguiente:** correr `php artisan migrate` (pendiente de la vuelta anterior); verificar visualmente en browser que el dropdown del avatar muestra ambas opciones nuevas y que ambos flujos (guardar preferencias, cambiar contraseña) funcionan end-to-end.
- **Follow-up mismo día:** el humano pidió sacar "Preferencias" del sidebar (grupo "Cuenta") porque quedó duplicada con el dropdown del avatar. Fix: `App\Filament\Pages\Preferences` gana `$shouldRegisterNavigation = false` — la page sigue existiendo igual, solo deja de listarse en el menú principal; el único acceso ahora es el dropdown del avatar.

---

## 2026-08-17 — Preferencias de usuario (idioma/zona horaria) + fechas amigables + fix de bugs en ApiTokens

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - Reporte del humano: en **API Tokens**, la columna "Token" se veía completamente vacía en las 3 filas, y los tokens sin expiración no mostraban "Nunca" en la columna "Expira". Investigado a fondo (sin poder ejecutar PHP, solo lectura de código fuente):
    - `last_four` nunca se persistía: `Laravel\Sanctum\PersonalAccessToken` define `$fillable = ['name', 'token', 'abilities', 'expires_at']` — `last_four` (columna agregada en una migración nuestra aparte) NO está ahí, así que `$model->update(['last_four' => ...])` lo descartaba en silencio por protección de mass-assignment, dejando la columna NULL para siempre. Fix: `forceFill(['last_four' => ...])->save()` en `ApiTokens::getHeaderActions()` y `ApiPlayground::getHeaderActions()` (ambos crean tokens).
    - `expires_at` con valor `null` no mostraba "Nunca": el patrón que SÍ funcionaba en la misma tabla (`last_used_at` con `->placeholder('Nunca usado')`) reveló que Filament no evalúa `formatStateUsing` para decidir si mostrar el placeholder — hay que declarar `->placeholder()` explícitamente en vez de manejar el `null` a mano dentro del formatter. Aplicado a `last_four` (`placeholder('—')`) y `expires_at` (`placeholder('Nunca')`).
  - Pedido explícito del humano: fechas "amigables y abreviadas" en toda Console, respetando idioma/zona horaria elegidos por el usuario (dio como ejemplo `America/Lima` y el formato `17 Ago 12:25 am`), con un recurso de settings para configurarlo.
    - Migración `users.locale` (default `es`) + `users.timezone` (default `America/Lima`).
    - `App\Support\FriendlyDate::format($date, ?User $user = null)`: relativo natural localizado si la fecha está a menos de 28 días de "ahora" (`Carbon::diffForHumans()`), absoluto abreviado con shape fijo (`17 Ago 12:25 am`, año solo si difiere del actual) en caso contrario — con un mapa de meses abreviados propio (es/en/pt) en vez de depender del meridiano/abreviaturas de Carbon por locale, para que el shape sea siempre igual.
    - `App\Filament\Pages\Preferences` (grupo **Cuenta**): Select de idioma (reusa `LanguageEnum`) + Select de zona horaria (`DateTimeZone::listIdentifiers()`, searchable), guarda en `auth()->user()`.
    - Aplicado `FriendlyDate` a las 3 columnas de fecha de `ApiTokens` (`last_used_at`, `expires_at`, `created_at`).
  - **Alcance explícito**: solo `ApiTokens` usa `FriendlyDate` por ahora — extenderlo a Pages/Posts/Media/Contacts y demás Resources queda como tarea de seguimiento (#19b en TASK.md), no se tocó todo Console de una.
- **Archivos/áreas:** `database/migrations/2026_08_17_090000_add_locale_and_timezone_to_users_table.php`, `app/Models/User.php`, `app/Support/FriendlyDate.php` (nuevo), `app/Filament/Pages/{Preferences,ApiTokens,ApiPlayground}.php`, `resources/views/filament/pages/preferences.blade.php` (nuevo), `docs/context/DECISIONS.md` (ADR-021)
- **Siguiente:** correr `php artisan migrate` (columna nueva en `users`) antes de abrir Preferencias; confirmar visualmente que Token/Expira/fechas de `ApiTokens` ahora se ven bien.

---

## 2026-08-17 — Fix: SSL local en el Playground (self-request a APP_URL_API)

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:** el Playground ahora le pega correctamente a `https://api.genesisly.host/...` (gracias al fix de dominios de la vuelta anterior), pero eso es un *self-request* del propio server Laravel hacia otro subdominio del mismo monolito — y en local ese dominio resuelve contra un certificado autofirmado (MAMP PRO/Herd/Valet) que el cURL de PHP no confía por defecto, tirando `cURL error 60: SSL certificate problem: unable to get local issuer certificate`. Se agregó `$request->withoutVerifying()` en `ApiPlayground::sendRequest()`, condicionado estrictamente a `app()->isLocal()` — nunca se desactiva la verificación TLS en producción.
- **Archivos/áreas:** `app/Filament/Pages/ApiPlayground.php`
- **Siguiente:** confirmar que el Playground ahora sí completa el request contra CICA360 en local.

---

## 2026-08-17 — Syntax highlighting real en los bloques de código de Documentation

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:** los bloques de código de `docs/v1.md` renderizados en Console se veían en un solo color (gris plano), sin distinguir claves/strings/números como sí hace el Playground. Se agregó **Prism.js vía CDN** (`prism-core` + `clike`/`javascript`/`typescript`/`json`/`bash`, sin build step ni bundler — igual que el resto de este panel) en `api-documentation.blade.php`, cargado al final del body en `<script>` planos (sin `defer`) para que corran sincrónicamente antes de que Alpine inicialice el `x-init` de la página. Los fences ` ```jsonc ` de `v1.md` (JSON con comentarios `//`) no son un lenguaje real de Prism — se extiende el grammar de `json` en runtime (`Prism.languages.extend('json', {comment: ...})`) en vez de traer un componente que no existe en el CDN. Los colores de los tokens (`public/css/filament/api-console.css`) reutilizan las mismas variables semánticas de Filament que ya usa el highlighter de JSON del Playground (`--info-*` para claves, `--success-*` para strings, `--warning-*` para números), para que un JSON se vea igual en ambas páginas.
- **Archivos/áreas:** `resources/views/filament/pages/api-documentation.blade.php`, `public/css/filament/api-console.css`
- **Siguiente:** confirmar en el navegador que los bloques ` ```json `/` ```jsonc `/` ```bash `/` ```ts ` de la documentación ahora se ven coloreados.

---

## 2026-08-17 — Fix: contenido de Documentation cortado detrás del margen derecho

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:** screenshot del humano mostraba texto cortado en seco contra el borde derecho del navegador (sin scroll, sin wrap), tanto en prosa normal como en los chips del banner. Causa: `.gnss-layout` usaba `grid-template-columns: 260px 1fr` — por spec CSS, un track `1fr` se resuelve como `minmax(auto, 1fr)`, y `auto` no puede achicarse por debajo del contenido "no quebrable" más ancho de sus hijos. Los chips `gnss-chip--mono` (URLs largas tipo `https://api.genesisly.host/v1/{tenant_slug}/...`) tenían `white-space: nowrap`, dándoles un ancho mínimo enorme que se propagaba hacia arriba — y como `.gnss-layout`/`.gnss-banner` son a su vez items dentro del grid `.fi-page-content` de Filament, terminaba empujando la página entera más ancha que el viewport. Fix: `minmax(0, 1fr)` en el track del grid, `min-width: 0` en cada nivel intermedio (`.gnss-layout`, `.gnss-stack`, `.gnss-sticky`, `.gnss-banner`, `.gnss-card`, `.gnss-prose`), y los chips mono ahora quiebran (`white-space: normal; word-break: break-all`) en vez de forzar el ancho. De paso: confirmado por lectura del código fuente de Filament (`Pages\Concerns\HasMaxWidth` solo lo consumen `SimplePage`/`EditProfile`, no las páginas normales) que **no existe ningún max-width propio de Filament limitando el ancho de una page custom** — el contenido ya ocupa el 100% del área disponible por defecto; no hacía falta ninguna opción de "fullwidth" adicional, solo corregir el overflow.
- **Archivos/áreas:** `public/css/filament/api-console.css`
- **Siguiente:** confirmar en el navegador que el contenido ahora respeta el ancho del viewport y usa el 100% del área disponible sin cortes.

---

## 2026-08-17 — Dominios base del monolito centralizados desde .env

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:** el humano ya tenía `APP_URL_API`/`APP_URL_CONSOLE`/`APP_URL_MANAGER` cargados en `.env`/`.env.example` (junto a `APP_URL`, reservado para la landing) y pidió que el código los usara en vez de tener dominios hardcodeados sueltos. Se agregó `config/genesis.php` (`urls.api`/`urls.console`/`urls.manager`, con fallback a `APP_URL`) y se corrigieron los tres puntos que tenían esto mal resuelto: `PanelCmsProvider`/`PanelManagerProvider` tenían el dominio de `->domain(...)` escrito como string literal (`'console.genesisly.host'`, `'manager.genesisly.host'`) en vez de leerlo de config; `ApiPlayground` y `api-documentation.blade.php` usaban `config('app.url')` (el dominio de la **landing**) para armar la URL real del request y para mostrarla en el Playground/Docs, cuando debería ser el dominio de la **API**. Documentado como ADR-020, incluyendo el pendiente honesto de que la API sigue viviendo bajo `/v1/...` (no `/v1/...`) porque separar eso requiere un `Route::domain()` dedicado — cambio de contrato público, no forzado en esta vuelta.
- **Archivos/áreas:** `config/genesis.php` (nuevo), `app/Providers/Filament/{PanelCmsProvider,PanelManagerProvider}.php`, `app/Filament/Pages/ApiPlayground.php`, `resources/views/filament/pages/{api-playground,api-documentation}.blade.php`, `docs/v1.md`, `docs/context/DECISIONS.md` (ADR-020)
- **Siguiente:** confirmar en el navegador que Console/Manager siguen resolviendo por dominio correctamente (no debería cambiar nada visible, es el mismo valor, solo leído de otro lugar).

---

## 2026-08-16 — Rediseño de Playground/Documentation para calzar con el look & feel real de Filament

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:** el fix anterior (mismo día) resolvió que los íconos y colores se vieran, pero el resultado visual inventaba una paleta y una estética propia (badges tipo pill, banner con gradiente, bloques de código en navy) que no calzaba con el resto de Console — feedback directo: "no encaja con los estilos de Filament, se ve horrible y no genera confianza". Se reescribió `public/css/filament/api-console.css` desde cero para que en vez de definir colores propios, **consuma directamente las variables CSS en tiempo de ejecución que el propio Filament expone en `:root`** (`--primary-*`, `--gray-*`, `--success-*`, `--warning-*`, `--danger-*`, `--info-*` — generadas por `Filament\Support\Assets\AssetManager::renderStyles()` a partir de los colores configurados en `PanelCmsProvider`, hoy `Color::Amber` de primary), y calcando los patrones reales de los componentes de Filament (`vendor/filament/support/resources/css/components/{section,badge,tabs,callout,button}.css`): cards con sombra tipo "ring" en vez de borde visible (`box-shadow: 0 0 0 1px rgb(3 7 18/.05)`, igual que `.fi-section`), badges `rounded-md` con `ring` sutil (igual que `.fi-badge`) en vez de pills, tabs con fondo pill activo (igual que `.fi-tabs-item`) en vez de underline, botones sólidos `var(--primary-600)` sin gradiente, y bloques de código usando el propio `var(--gray-950)` del panel en vez de un navy inventado.
  - De paso se corrigió un bug real: `.gnss-method--get { composes: gnss-badge--info; }` usaba sintaxis de CSS Modules (`composes`), que no existe en CSS plano de navegador — no tenía ningún efecto. Se combinaron los selectores directamente.
  - Se eliminó código muerto: `ApiPlayground::METHOD_COLORS` y `methodBadgeClasses()` (reemplazados por las clases `gnss-method--{método}` del CSS, ya no se llamaban desde el blade actual).
  - Verificación estática añadida: script que extrae todas las clases `gnss-*` usadas en los 3 blade y confirma que cada una tiene una regla definida en el CSS (detectó y corrigió 2 clases faltantes: `gnss-chip--muted`, `gnss-icon-muted`).
- **Archivos/áreas:** `public/css/filament/api-console.css`, `app/Filament/Pages/ApiPlayground.php`
- **Siguiente:** el humano debe recargar Console y confirmar que ahora sí se siente parte del mismo panel (mismos grises, mismo primary configurado, mismos radios/sombras que el resto de Filament) en vez de una herramienta con estética propia.

---

## 2026-08-16 — Fix: iconos gigantes / sin color en Playground y Documentation

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:** el rediseño anterior (mismo día) se veía roto en el navegador real: íconos Heroicon renderizando a tamaño gigante (cientos de px) y tarjetas/badges sin color ni bordes. Causa raíz: este panel de Filament **no tiene un tema custom con pipeline de Vite/Tailwind** que escanee `resources/views/filament/**` ni `app/Filament/**` — corre sobre el CSS pre-compilado del paquete `filament/filament`, que solo incluye las clases utilitarias que el propio Filament usa en sus vistas core. Cualquier clase Tailwind que yo escribiera a mano en mis blade custom (`h-3.5`, `bg-sky-100`, `lg:grid-cols-[240px_1fr]`, etc.) no tenía ninguna regla CSS asociada — de ahí que las imágenes SVG (sin `width`/`height` propios) se renderizaran a tamaño intrínseco del navegador y las cards quedaran sin fondo/bordes/colores.
  - Fix: se escribió `public/css/filament/api-console.css` — CSS plano, sin Tailwind ni build step, con paleta propia (variables CSS, con overrides para `html.dark`), cubriendo layout (grid del sidebar), badges de método HTTP coloreados, tabs de response, syntax highlighting de JSON, tabla de headers, banner con acento en gradiente, TOC de la documentación, prose del markdown, y el banner de "guardá este token ahora" de `ApiTokens`.
  - Registrado como asset de Filament vía `FilamentAsset::register([Css::make('api-console', asset(...))])` en `PanelCmsProvider::boot()` (antes el provider solo tenía `panel()`, sin `boot()`).
  - Los 3 blade views afectados (`api-playground`, `api-documentation`, `api-tokens`) se reescribieron reemplazando las clases Tailwind por clases semánticas `gnss-*` del nuevo CSS. La lógica Livewire/Alpine (wire:click, x-data, x-show, IntersectionObserver del scrollspy) no cambió.
  - El highlighter de JSON en `ApiPlayground::highlightJson()` también se actualizó para emitir clases `gnss-json-*` en vez de `text-sky-400`/`text-emerald-400`/etc.
- **Archivos/áreas:** `public/css/filament/api-console.css` (nuevo), `app/Providers/Filament/PanelCmsProvider.php`, `app/Filament/Pages/ApiPlayground.php`, `resources/views/filament/pages/{api-playground,api-documentation,api-tokens}.blade.php`
- **Siguiente:** el humano debe recargar Console (puede necesitar limpiar caché del navegador o hacer un hard refresh) y confirmar visualmente que ahora se ven bien. No requiere `npm run build` ni ningún paso de compilación — el CSS ya está en su ubicación final servible (`public/css/filament/api-console.css`).

---

## 2026-08-16 — Rediseño visual de API Playground y API Documentation

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:** las dos páginas de Console del grupo "Desarrolladores" eran funcionales pero visualmente planas (un form + una caja de texto). Se rediseñaron sin agregar dependencias nuevas:
  - **API Playground:** sidebar sticky con los 8 endpoints de ejemplo agrupados por recurso, cada uno con badge de método coloreado (GET/POST/PUT/PATCH/DELETE); response con tabs Pretty/Raw/Headers; JSON con syntax highlighting real (hecho server-side con regex sobre el string ya escaneado, sin librerías JS externas); botones de copiar con feedback ("¡Copiado!") vía Alpine; status badge con ícono (check/x) y duración con ícono de reloj; card de "destino real" mostrando `config('app.url')`.
  - **API Documentation:** banner superior con badges (versión, tipo de auth, base URL) y botón directo a "Abrir Playground"; sidebar sticky con índice navegable (H2/H3 extraídos del markdown crudo) con scrollspy vía `IntersectionObserver` y buscador que filtra el índice en vivo; anchors (`id`) inyectados en los headings del HTML renderizado; botón de copiar auto-inyectado en cada bloque de código del markdown.
  - Bug propio detectado y corregido durante la implementación: el highlighter usaba `e()` (que escapa comillas a `&quot;`) antes de aplicar los regex de resaltado, lo que rompía el matching — cambiado a `htmlspecialchars(..., ENT_NOQUOTES)` ya que el output va dentro de `<pre><code>`, no de un atributo HTML.
- **Archivos/áreas:** `app/Filament/Pages/{ApiPlayground,ApiDocumentation}.php`, `resources/views/filament/pages/{api-playground,api-documentation}.blade.php`
- **Siguiente:** el humano debe abrir ambas páginas en Console (con el servidor ya corriendo tras cerrar la vuelta de Sanctum) y confirmar visualmente que el sidebar, los tabs y el scrollspy se comportan bien — esto no se pudo probar en navegador real desde este entorno, solo verificación estática (balance de llaves/paréntesis, nombres de íconos Heroicon confirmados contra `vendor/blade-ui-kit/blade-heroicons`).

---

## 2026-08-16 — Fix: alias de middleware `abilities` faltante en bootstrap/app.php

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:** El humano corrió por primera vez el ciclo completo en su máquina: `composer require laravel/sanctum:^4.0` (el `composer install` inicial no alcanzaba porque `composer.lock` no tenía la dependencia nueva), `php artisan migrate` (25+2 migraciones, incluidas las de Sanctum) y `php artisan db:seed` — todo corrió en verde. `php artisan test` corrió por primera vez con Sanctum realmente instalado y expuso un bug real (no de entorno): **16 de 26 tests fallaban con `BindingResolutionException: Target class [abilities] does not exist.`** — el middleware `abilities:*` usado en `routes/api.php` nunca se registró como alias. A diferencia de lo asumido en ADR-018, Sanctum **no** auto-registra `CheckAbilities`/`CheckForAnyAbility` como alias de ruta en apps Laravel 11+ sin `Http/Kernel.php`; hay que declararlos a mano en `bootstrap/app.php` vía `$middleware->alias([...])`. Corregido agregando `'abilities' => CheckAbilities::class` y `'ability' => CheckForAnyAbility::class` al `withMiddleware()`.
- **Archivos/áreas:** `bootstrap/app.php`
- **Siguiente:** el humano debe re-correr `php artisan test` para confirmar que los 16 tests que fallaban por este alias ahora pasan (los otros 10 ya pasaban: `TenantIsolationTest`, `ContactSubmissionServiceTest`, `ExampleTest`, `Tests\Unit\ExampleTest`).

**Actualización (misma sesión):** el re-run mostró 25/26 en verde; el único fallo restante fue `ApiAuthTest::test_revoked_token_returns_401_on_subsequent_requests` (esperaba 401, recibió 200). Causa: no es un bug de producción — es que el `RequestGuard` de Sanctum cachea el usuario resuelto en la instancia de guard, y el test hace dos requests HTTP simuladas dentro del mismo método sin reiniciar la `Application`, así que la segunda seguía viendo el usuario resuelto por la primera aunque el token ya estaba borrado. Fix: agregado `$this->app['auth']->forgetGuards();` entre las dos requests del test (patrón documentado del framework, `AuthManager::forgetGuards()` existe justo para esto). Archivo: `tests/Feature/Api/V1/ApiAuthTest.php`. Pendiente: confirmar 26/26 con un último re-run.

---

## 2026-08-16 — Cierre de gaps de ADR-018: ids anidados en content.items[] + expiración de tokens

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - Verificado explícitamente (de nuevo) que este sandbox no puede correr `composer install`/`php artisan *`: sin PHP, sin Composer, sin `sudo`/apt funcional, sin docker, y con la red bloqueada por allowlist hacia todo host externo relevante (`github.com`, `packagist.org`, `getcomposer.org`, `deb.debian.org`, `archive.ubuntu.com` — todos probados y bloqueados). Esto queda documentado explícitamente para que quede claro que no es una omisión sino una limitación real del entorno.
  - `App\Http\Concerns\ResolvesPublicLinks::attachResolvedHeroContent()` renombrado y expandido a `attachResolvedBlockContent()`: ahora resuelve TODOS los ids internos de `content` — `heading`/`image`/`split`/`hero`(manual) a nivel bloque, y `image_id`/`avatar_id`/`media_id`/`page_id` dentro de `content.items[]` de `features`/`testimonials`/`logos`/`services_grid` — en 3 queries batched (Media/Page/Slider), sin importar cuántos bloques/items tenga la página.
  - `App\Filament\Pages\ApiTokens` gana selector de expiración al crear el token (Nunca/1/30/90/365 días) + columna `expires_at` en el listado (con color danger si ya venció). Sanctum rechaza automáticamente tokens vencidos, sin lógica de enforcement extra.
  - `App\Filament\Pages\ApiPlayground` usa el mismo helper para que sus tokens de prueba expiren en 24hs por defecto.
  - Test nuevo (`PageApiTest::test_page_response_resolves_nested_media_and_page_ids_inside_block_items`) cubriendo un `services_grid` con `image_id` y `page_id` simultáneos.
  - Actualizado `docs/v1.md` (tabla de mapeo id-interno → campo público, sección de expiración, "Pendientes conocidos" reescrita) y `docs/api/openapi.v1.yaml`.
- **Archivos/áreas:**
  - `app/Http/Concerns/ResolvesPublicLinks.php`, `app/Http/Controllers/Api/V1/PageController.php`
  - `app/Filament/Pages/{ApiTokens,ApiPlayground}.php`
  - `tests/Feature/Api/V1/PageApiTest.php`
  - `docs/api/{v1.md,openapi.v1.yaml}`, `docs/context/DECISIONS.md` (ADR-019)
- **Siguiente:**
  - `composer install` + `php artisan migrate` + `php artisan db:seed` + `php artisan test` — bloqueante, tiene que correrlo el humano en su máquina.
  - Probar el Playground end-to-end con un token real.
  - Filament Resource de Contacts; decisión de frontend.
- **Estado del código de aplicación:** Ambos gaps cerrados a nivel de código, verificado estáticamente. Sigue pendiente la ejecución real (composer/migrate/seed/test) porque ningún agente de este proyecto tuvo, hasta ahora, un entorno con PHP/Composer disponible.

---

## 2026-08-16 — Seguridad API por tokens (Sanctum) + Playground + Docs + Contrato público sin ids internos

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - Protegida toda `/v1/{tenant_slug}/*` con Laravel Sanctum (Bearer tokens, guard `sanctum`, abilities `content:read`/`forms:submit`). `ResolvesTenant` valida ownership del token contra el tenant del path (403 si no coincide, sin filtrar si el tenant existe).
  - `App\Filament\Pages\ApiTokens`: crear token (nombre + abilities), plaintext visible una sola vez con advertencia, listado enmascarado (últimos 4 caracteres), revocar inmediato. Scoped al tenant actual.
  - `App\Filament\Pages\ApiPlayground`: arma y ejecuta requests HTTP reales contra la API del entorno (endpoint precargado agrupado, método, path, headers, body JSON), botón para generar un token de prueba desechable, muestra status/duración/JSON pretty, copiar response/cURL.
  - `App\Filament\Pages\ApiDocumentation`: renderiza `docs/v1.md` (Markdown → HTML) dentro de Console.
  - `docs/v1.md` (documentación completa: auth, envelope, errores, cada endpoint con ejemplos reales) + `docs/api/openapi.v1.yaml`.
  - Corregido el contrato público de responses: `App\Http\Concerns\ResolvesPublicLinks` resuelve `links[].source_id` → `source_slug`+`href` (batcheado, sin N+1) y `hero.content.slider_id` → `content.slider_slug`; `App\Http\Resources\Api\V1\Concerns\NormalizesJsonFields` fuerza `properties`/`content`/`meta` vacíos a `{}` en vez de `[]`. Aplicado en Page/Block/Post/Slide Resources.
  - `Cliente0PostsSeeder`: 3 posts publicados de prueba para CICA360, idempotente.
  - Tests: `ApiAuthTest` (401 sin token, 200 con token válido, 403 token de otro tenant, 403 sin ability, 401 tras revocar), `PageApiTest` extendido (test de que la response no expone `source_id`/`slider_id`), `PostApiTest` (posts index incluye los seeded, post por slug).
- **Archivos/áreas:**
  - `composer.json` (agrega `laravel/sanctum`), `config/auth.php` (guard `sanctum`), `app/Models/User.php` (`HasApiTokens`)
  - `database/migrations/2026_08_16_130000_create_personal_access_tokens_table.php`, `..._130100_add_display_fields_...php`
  - `app/Http/Concerns/{ResolvesTenant,ResolvesPublicLinks}.php`, `app/Http/Resources/Api/V1/Concerns/NormalizesJsonFields.php`
  - `app/Http/Resources/Api/V1/{BlockResource,PageResource,PostResource,SlideResource}.php`
  - `app/Http/Controllers/Api/V1/{Controller,PageController,PostController,SliderController}.php`
  - `routes/api.php`, `bootstrap/app.php` (401/403 en el envelope)
  - `app/Filament/Pages/{ApiTokens,ApiPlayground,ApiDocumentation}.php` + sus blade views
  - `database/seeders/{Cliente0PostsSeeder,DatabaseSeeder}.php`
  - `docs/api/{v1.md,openapi.v1.yaml}`
  - `tests/Feature/Api/V1/{PageApiTest,ApiAuthTest,PostApiTest}.php`
  - `docs/context/DECISIONS.md` (ADR-018)
- **Siguiente:**
  - `composer install` + `php artisan migrate` + `php artisan db:seed` + `php artisan test` contra la BD real.
  - Generar un token real en Console y probar el Playground end-to-end.
  - Filament Resource de Contacts; decisión de frontend.
- **Estado del código de aplicación:** Bloque completo a nivel de código, pendiente de instalación real de `laravel/sanctum` (`composer install`) y ejecución de migraciones/seeders/tests — no ejecutable en el sandbox de este agente (sin PHP/Composer).

---

## 2026-08-16 — Contenido mínimo real de CICA360 (seeder)

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - Creado `Database\Seeders\Cliente0ContentSeeder`: 5 páginas publicadas con bloques (`home`, `sobre-cica`, `servicios`, `casos-de-exito`, `contacto`), menú `menu-principal` (5 items → páginas reales) y formulario "Contacto principal" (campos name/email/phone/message, reutilizando las `FormFieldDefinition` globales de ADR-014).
  - Los bloques usan exactamente el schema `content`/`links` de los Resources de Filament ya construidos (`LinkSchema`, claves `content.*` por tipo de bloque en `PageResource`): hero (modo `slider` → referencia al slider `home`), rich_text, features, split, testimonials, logos, cta, services_grid, contact_form.
  - Actualizado `Cliente0HomeSlidesSeeder`: los CTA de las 3 slides ahora resuelven a páginas reales (`source_type = page`) en vez de un placeholder `#`, con fallback automático si las páginas todavía no existen.
  - Reordenado `DatabaseSeeder`: `Cliente0ContentSeeder` corre antes que `Cliente0HomeSlidesSeeder` para que la resolución de CTAs funcione en una corrida limpia.
  - Todo idempotente (`updateOrCreate` + poda de filas sobrantes por `sort_order`), sin depender de media real (todos los `*_id` de imagen quedan `null`).
  - Confirmado por el humano: `php artisan test` corre en verde (16 tests Feature + 1 Unit, 67 aserciones); el warning de PHP que aparecía en cada test ("use statement... Throwable") era opcache/file-cache obsoleto local, no un problema de `bootstrap/app.php`.
- **Archivos/áreas:**
  - `database/seeders/Cliente0ContentSeeder.php` (nuevo)
  - `database/seeders/Cliente0HomeSlidesSeeder.php` (CTAs → páginas reales)
  - `database/seeders/DatabaseSeeder.php` (orden de ejecución)
  - `docs/context/DECISIONS.md` (ADR-017)
- **Siguiente:**
  - Correr `php artisan db:seed` contra la BD real y verificar visualmente en Filament.
  - Probar el API v1 contra `cica360` con datos reales.
  - Filament Resource de Contacts; decisión de frontend.
- **Estado del código de aplicación:** Seeder completo a nivel de código, pendiente de ejecución real contra la BD (no ejecutable en el sandbox de este agente).

---

## 2026-08-16 — API REST pública v1 (Headless MVP)

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - Implementado el API REST público `/v1/{tenant_slug}/...` para el MVP Headless: `GET pages`, `pages/{slug}`, `posts`, `posts/{slug}`, `menus/{slug}`, `sliders/{slug}`, `media/{uuid}`, `POST forms/{slug}/submit`.
  - Envoltura JSON estándar (ADR-009) vía trait `ApiResponds` (`success`/`error`/`paginated`), con `meta`/`links` de paginación.
  - Resolución de tenant explícita por controller (trait `ResolvesTenant`, reutiliza `TenantManager::setTenant()`) en vez de middleware — investigado y documentado el motivo (timing del pipeline de Laravel) en ADR-016.
  - `pages/{slug}` con blocks visibles ordenados; `menus/{slug}` con árbol `parent_id` armado en memoria (cero N+1) y resolución batched de `href` para items Page/Post; `sliders/{slug}` con slides activos y las 5 relaciones de media resueltas.
  - `forms/{slug}/submit` reutiliza `ContactSubmissionService` (ADR-015) sin duplicar lógica de dominio; nunca expone datos sensibles.
  - CORS (`config/cors.php`), rate limiting (`RateLimiter::for('api')` 60/min, `for('forms')` 10/min) y excepciones de `api/*` normalizadas a la envoltura JSON en `bootstrap/app.php`.
  - Tests de aislamiento tenant + page-by-slug + paginación en `tests/Feature/Api/V1/PageApiTest.php` (7 casos).
- **Archivos/áreas:**
  - `routes/api.php`, `bootstrap/app.php`, `config/cors.php`, `app/Providers/AppServiceProvider.php`
  - `app/Http/Concerns/{ApiResponds,ResolvesTenant}.php`
  - `app/Http/Controllers/Api/V1/{Controller,PageController,PostController,MenuController,SliderController,MediaController,FormSubmissionController}.php`
  - `app/Http/Resources/Api/V1/{MediaResource,BlockResource,PageResource,PageSummaryResource,PostResource,PostSummaryResource,MenuItemResource,SliderResource,SlideResource}.php`
  - `tests/Feature/Api/V1/PageApiTest.php`
  - `docs/context/DECISIONS.md` (ADR-016)
- **Siguiente:**
  - Correr `php artisan test` contra la BD real (no ejecutable en el sandbox de este agente).
  - Sembrar contenido real de CICA360 (pages/blocks/slider/menu) en Filament para probar el API con datos reales.
  - Filament Resource de Contacts; decisión de auth avanzado del DBML; arranque del frontend Cliente 0.
- **Estado del código de aplicación:** API v1 completa a nivel de código (routing/controllers/resources/seguridad básica), pendiente de ejecución real de tests y de contenido de prueba en CICA360.

---

## 2026-08-16 — MediaSelect modal, Sliders interactivos, Decoradores Top/Bottom y Hero Responsivo
- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Cambiada la URL del disco público en `config/filesystems.php` a la ruta relativa `/storage` para evitar errores CORS y cargas infinitas de Livewire.
  - Corregida la reactividad de `SliderResource` y `MenuResource` para resolver correctamente los objetos Enum PHP en la UI de Filament 5.
  - Agregado el componente `MediaSelect::make()` para permitir la carga directa modal o reutilización de imágenes en posts, sliders, páginas y bloques.
  - Implementado el bloque `heading` en el builder de `PageResource` con soporte para 3 imágenes responsivas (Desktop/Tablet/Mobile) y múltiples propiedades visuales de estilo (decoradores independientes, brillo, contraste, color, opacidad).
  - Rediseñada la sección SEO en `PageResource` y `PostResource` separando Metadata SEO de Open Graph con soporte responsivo para imágenes rectangular y cuadrada.
  - Actualizados los campos de opacidad, brillo, contraste y transparencia en `PropertiesSchema` y el bloque `heading` para usar componentes `Slider` de Filament con indicadores de valor interactivos (`tooltips()`).
  - Separados los decoradores superiores e inferiores en `PropertiesSchema` y el bloque `heading` con controles de color reactivos e independientes.
  - Añadido soporte para 3 imágenes responsivas (Desktop, Tablet, Mobile) en la configuración manual del bloque `hero` en `PageResource`.
- **Archivos/áreas:**
  - `config/filesystems.php`
  - `app/Enums/BlockTypeEnum.php`
  - `app/Filament/Schemas/PropertiesSchema.php`
  - `app/Filament/Resources/PageResource.php`
  - `app/Filament/Resources/SliderResource.php`
  - `app/Filament/Resources/MenuResource.php`
  - `app/Filament/Resources/PostResource.php`
- **Siguiente:**
  - Desarrollar el Filament Resource de Contacts o la API REST pública `/v1`.

## 2026-08-14 — Ocultado de Idioma (lang_iso) + Corrección de Upload Disk
- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Ocultados todos los selectores de idioma (`lang_iso`) en los formularios y sub-repetidores del panel Console (`PageResource`, `SliderResource`, `MenuResource`, `PostResource` y todos sus bloques y sub-esquemas).
  - Eliminadas las columnas de idioma y los filtros de idioma de los listados/tablas de Filament Console.
  - Asegurada la inyección de `'es'` de manera invisible y transparente mediante campos ocultos con `.default('es')`.
  - Corregido el guardado de la columna `disk` de multimedia en `MediaResource`: agregada asignación dinámica en el evento `afterStateUpdated` del componente `FileUpload` para que apunte al disco real utilizado (`public` en local/desarrollo), lo que habilita la visualización y previsualización correctas de imágenes en el listado.
  - Solucionados los namespaces incompatibles de `Grid` y `Group` en `LinkSchema.php` y `PropertiesSchema.php` para adaptarlos a Filament 5.
- **Archivos/áreas:**
  - `app/Filament/Schemas/LinkSchema.php`
  - `app/Filament/Schemas/PropertiesSchema.php`
  - `app/Filament/Resources/MediaResource.php`
  - `app/Filament/Resources/PageResource.php`
  - `app/Filament/Resources/SliderResource.php`
  - `app/Filament/Resources/MenuResource.php`
  - `app/Filament/Resources/PostResource.php`
- **Siguiente:**
  - Continuar con el Filament Resource de `Contact`.

## 2026-08-14 — Re-esquematización de Filament Resources Console
- **Agente/autor:** Antigravity (Gemini)
- **Qué se hizo:**
  - Diseñados y creados esquemas reutilizables `LinkSchema` y `PropertiesSchema` para estructurar la columna de enlaces `links` (con soporte para tipo de destino: página, entrada, URL externa, personalizado) y propiedades tipadas de visualización `properties` (color de fondo/texto, opacidad, alineación, anchos, rellenos, animaciones).
  - Refactorizado `PageResource.php` para incorporar 12 tipos distintos de bloques en el `Builder` (Hero con soporte de slider/manual, RichText, Image, CTA, Features, FAQ, ContactForm, LegalNotice, Split, Testimonials, Logos, ServicesGrid) estructurado bajo un sistema de tres pestañas (Contenido, Configuración, SEO / Enlaces) que almacena metadatos SEO tipados dentro de `meta`.
  - Refactorizado `SliderResource.php` en su sección de slides para soportar selección condicional de imágenes o videos responsivos según el tipo de fondo, toggles dinámicos de video de presentación de YouTube, y la integración de `LinkSchema` y `PropertiesSchema`.
  - Refactorizado `MenuResource.php` para unificar el campo de referencia (`reference_id`) e incorporar resolución reactiva de enlaces (vaciado de estado al cambiar entre Página, Post o URL).
  - Refactorizado `PostResource.php` para re-estructurar los metadatos SEO tipados y los componentes de enlace y propiedades.
  - Ejecutada exitosamente la suite de pruebas unitarias (`php artisan test`), verificando que los 10 tests unitarios y de integración y las 36 aserciones de aislamiento y negocio se mantengan al 100% verdes.
- **Archivos/áreas:**
  - `app/Filament/Schemas/LinkSchema.php` (Nuevo)
  - `app/Filament/Schemas/PropertiesSchema.php` (Nuevo)
  - `app/Filament/Resources/PageResource.php`
  - `app/Filament/Resources/SliderResource.php`
  - `app/Filament/Resources/MenuResource.php`
  - `app/Filament/Resources/PostResource.php`
- **Siguiente:**
  - Construir el Filament Resource de Contacts.
  - Desarrollar endpoints públicos en `/v1/...` para páginas, menús y settings.

## 2026-08-13 — Dominio y seguridad de Forms + Contacts

- **Agente/autor:** Tech Lead backend (Claude)
- **Qué se hizo:**
  - Bloque acotado explícitamente a dominio/seguridad de Forms + Contacts, en paralelo a otro agente construyendo los Filament Resources de contenido (Pages/Blocks/Sliders/Menus/Posts/Media) — sin tocar ni duplicar ese trabajo.
  - `App\Services\ContactSubmissionService`: único punto de entrada para guardar un envío de `Form`. Mapea `name`/`email`/`phone`/`company` a las columnas dedicadas de `Contact` (ya cifradas vía cast `encrypted`); cualquier otro campo va a `Contact::data` (jsonb), cifrado individualmente (`Crypt::encryptString`) si su `FormField::is_encrypted` es `true`, en texto plano si no. Valida presencia de campos requeridos (`InvalidArgumentException` si falta alguno). Expone `decryptData(Contact $contact)` que descifra selectivamente usando `FormField.is_encrypted` (por `name`) como única fuente de verdad — no se duplica esa metadata dentro del jsonb.
  - `App\Mail\ContactFormSubmitted`: Mailable Markdown limpio (sin lógica de cifrado ni de negocio, recibe el payload ya descifrado por el service) que notifica a `Form.notification_email`. El envío es best-effort: si falla, se loguea (`Log::warning`) pero el `Contact` ya guardado nunca se pierde. Vista en `resources/views/emails/contacts/form-submitted.blade.php`.
  - `App\Http\Resources\ContactResource`: transformer whitelist (uuid, name, status, source, assigned_to, timestamps) que nunca incluye email/phone/company/data/notes/ip/user_agent — listo para cuando exista la API pública/admin.
  - `App\Policies\ContactPolicy`: autorización tenant-aware (`viewAny`/`view`/`viewSensitive`/`create`/`update`/`delete`), resuelta por convención de Laravel sin registro manual. `viewSensitive` incluye TODO explícito para exigir re-auth antes de exports/listados masivos (fuera de alcance de este bloque, tal cual se pidió).
  - `App\Services\DataMasker`: helpers estáticos de enmascarado (`email()`, `phone()`, `value()`) para previsualización en UI/logs sin descifrar el valor completo.
  - `tests/Feature/ContactSubmissionServiceTest.php`: 3 tests — cifrado a nivel de fila cruda (bypass del cast de Eloquent) para email/phone/campo dinámico marcado, que un campo no marcado (`message`) queda legible en el jsonb crudo, `decryptData()` descifra correctamente, validación de campos requeridos, y aislamiento por tenant (mismo patrón que `TenantIsolationTest`).
  - Verificación: sin PHP en el sandbox del agente (igual que sesiones anteriores), revisión estática — balance de llaves, clase↔archivo, cada array de datos cruzado contra el `#[Fillable]` real de `Form`/`FormField`/`Contact`/`ContactActivity`, y confirmación en `vendor/` de que `Illuminate\Mail\Mailables\{Envelope,Content}`, `Illuminate\Http\Resources\Json\JsonResource` y `Illuminate\Contracts\Encryption\DecryptException` existen. Se confirmó además que Laravel resuelve `ContactPolicy` por convención (`Gate::guessPolicyName`) sin necesidad de `AuthServiceProvider` (el proyecto no tiene uno, es normal en Laravel 13).
  - Nueva decisión registrada en `DECISIONS.md` (ADR-015), incluyendo qué queda explícitamente fuera de alcance: honeypot/reCAPTCHA sin aplicar todavía (los flags ya existen en el esquema desde ADR-013), re-auth para exports, y el Filament Resource de Contacts en sí.
- **Archivos/áreas:**
  - `app/Services/{ContactSubmissionService,DataMasker}.php`
  - `app/Mail/ContactFormSubmitted.php`, `resources/views/emails/contacts/form-submitted.blade.php`
  - `app/Http/Resources/ContactResource.php`
  - `app/Policies/ContactPolicy.php`
  - `tests/Feature/ContactSubmissionServiceTest.php`
  - `docs/context/DECISIONS.md` (ADR-015)
- **Siguiente:**
  - Correr `php artisan test` en el entorno local real.
  - Construir el Filament Resource de Contacts sobre esta base (sin reinventar cifrado/autorización).
  - Decidir cuándo aplicar honeypot/reCAPTCHA y el flujo de re-auth para exports.
- **Estado del código de aplicación:** Service, Mail, Resource, Policy y tests completos y revisados estáticamente; pendiente de ejecución real (`php artisan test`).

---

## 2026-08-13 — Seeders mínimos idempotentes del MVP + Cliente 0 = CICA360

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - El humano confirmó que las 25 migraciones de ADR-013 corrieron exitosamente contra PostgreSQL real.
  - Se reemplazó el `DatabaseSeeder` monolítico (con `Model::create()` directos, no seguro de re-ejecutar) por 5 seeders separados por responsabilidad, todos idempotentes vía `firstOrCreate`/`updateOrCreate`: `PlanSeeder`, `ModuleSeeder`, `FormFieldDefinitionSeeder`, `PlatformSeeder`, `Cliente0Seeder`.
  - `PlanSeeder`: crea el plan **Free** (`is_free`, `price_monthly`/`price_yearly` en 0, `max_users=1`, `max_pages=20`, `max_posts=50`, `max_storage_mb=500`) y 5 `plan_features` (mismos límites + `modules_vertical=false`), en `lang_iso=es`.
  - `ModuleSeeder`: crea 7 módulos core (`pages`, `posts`, `media`, `menus`, `settings`, `sliders`, `contacts`, todos `is_core=true`, `type=utility`) y los asocia al plan Free vía `plan_module` (`syncWithoutDetaching`).
  - `FormFieldDefinitionSeeder`: crea 6 definiciones de campo del sistema (`name`, `email`, `phone`, `company`, `subject`, `message`) con sus flags `default_required`/`default_encrypted` correctos (`email` y `phone` cifrados por defecto).
  - `PlatformSeeder`: super-admin de plataforma (`admin@genesisly.host`, `tenant_id=null`), idempotente.
  - `Cliente0Seeder`: confirma el **nombre real de Cliente 0: CICA360** (Centro Internacional de Consultoría y Asesoría). Busca primero por los valores nuevos (`slug=cica360`, `owner@cica360.com`) y hace fallback a los valores placeholder del scaffold original (`slug=cliente-0`, `admin@cliente0.com`) para **renombrar in-place** en vez de duplicar — necesario porque el plan Free limita `max_users=1`. Crea/actualiza: dominio `console.genesisly.host`, usuario owner (contraseña dev solo al crear, nunca al renombrar), suscripción activa al plan Free, `tenant_modules` para los 7 módulos core, settings (`site_name`, `default_locale`, `available_locales`) y un slider placeholder `home` sin slides.
  - `DatabaseSeeder` ahora solo orquesta el orden: `PlanSeeder → ModuleSeeder → FormFieldDefinitionSeeder → PlatformSeeder → Cliente0Seeder`.
  - Verificación: sin PHP disponible en el sandbox del agente (mismo bloqueador que la sesión anterior), se hizo revisión estática — balance de llaves, clase↔archivo, y cruce de cada array de datos contra el `#[Fillable]` real de cada modelo (`Plan`, `PlanFeature`, `Module`, `TenantModule`, `FormFieldDefinition`, `Tenant`, `Domain`, `User`, `Subscription`, `Setting`, `Slider`) para descartar errores de mass-assignment. Se confirmó además que `TenantManager` solo se muta desde el middleware HTTP `ResolveTenant` (nunca en `artisan db:seed`), así que los seeders no dependen de estado global de tenant y siempre pasan `tenant_id` explícito.
  - Nueva decisión registrada en `DECISIONS.md` (ADR-014).
- **Archivos/áreas:**
  - `database/seeders/{PlanSeeder,ModuleSeeder,FormFieldDefinitionSeeder,PlatformSeeder,Cliente0Seeder,DatabaseSeeder}.php`
  - `docs/context/DECISIONS.md` (ADR-014)
- **Siguiente:**
  - Correr `php artisan db:seed` en el entorno local real y confirmar los datos.
  - Construir los Filament Resources del contenido (Pages/Blocks, Posts, Sliders, Menus, Contacts).
- **Estado del código de aplicación:** Seeders completos y revisados estáticamente; pendiente de ejecución real contra la base de datos.

---

## 2026-08-13 — Esquema final de base de datos: contenido y negocio (migraciones + modelos)

- **Agente/autor:** Tech Lead (Claude)
- **Qué se hizo:**
  - Se recibió el DBML "FINAL (MVP)" del esquema de base de datos y, tras confirmar alcance con el humano (contenido + negocio, dejando auth avanzado para otra sesión — ver ADR-013), se implementaron 25 migraciones nuevas y 22 modelos Eloquent nuevos.
  - Módulos implementados: `media`; `pages` + `blocks`; `posts`; `sliders` + `slides`; `menus` + `menu_items`; `plans` + `plan_features` + `subscriptions` + `payment_methods` + `invoices` + `invoice_items` + `transactions`; `modules` + `plan_module` + `tenant_modules`; `form_field_definitions` + `forms` + `form_fields` + `contacts` + `contact_activities`.
  - Se creó `app/Enums/` con 18 Enums PHP nativos (`LanguageEnum`, `PageTypeEnum`, `PublishStatusEnum`, `BlockTypeEnum`, `SlideBackgroundTypeEnum`, `MenuItemTypeEnum`, `LinkTargetEnum`, `MediaDiskEnum`, `SubscriptionStatusEnum`, `BillingCycleEnum`, `InvoiceStatusEnum`, `TransactionTypeEnum`, `TransactionStatusEnum`, `PaymentMethodTypeEnum`, `ModuleTypeEnum`, `FormFieldTypeEnum`, `ContactStatusEnum`, `ContactActivityTypeEnum`), todos implementando `Filament\Support\Contracts\HasLabel`.
  - `tenants` se extendió con `short_hash` (default calculado en Postgres + asignado en `creating()`), `is_active`, `plan`. `settings` se extendió con `type`.
  - Todas las tablas tenant-scoped usan `HasTenant` + `HasUuid`; las tablas globales de plataforma (`plans`, `plan_features`, `modules`, `plan_module`, `form_field_definitions`) no llevan `tenant_id`, igual que `tenants`/`domains` (ver ARCHITECTURE.md §3).
  - `Contact.email/phone/company` usan el cast `encrypted` de Laravel y están en `$hidden`; `Contact.data` (jsonb) guarda las respuestas dinámicas del formulario.
  - Índices únicos compuestos `(tenant_id, lang_iso, slug)` en `pages`, `posts`, `sliders`, `menus`, `forms`; FKs con `constrained()` y `onDelete` explícito (`cascadeOnDelete`/`nullOnDelete`/`restrictOnDelete` según corresponda) en todas las tablas.
  - No se instaló `doctrine/dbal` (no estaba en el proyecto): se evitó cualquier `->change()` de columnas existentes; ver ADR-013 para el detalle de las dos desviaciones menores respecto al DBML que esto obligó (`tenants.short_hash` NOT NULL vía default de Postgres en vez de backfill+alter, `settings.tenant_id` se mantiene NOT NULL).
  - Verificación: no había PHP disponible en el sandbox del agente (`php`/`composer` no instalados, sin permisos de `apt`/`sudo` para instalarlos), así que no se pudo correr `php artisan migrate` real. Se hizo una verificación estática exhaustiva: balance de llaves en todos los archivos nuevos, correspondencia 1:1 entre clases de Enum declaradas y referenciadas, correspondencia clase↔nombre de archivo en todos los modelos, orden de dependencias FK entre las 25 migraciones (ninguna referencia hacia adelante), y confirmación en `vendor/` de que `jsonb()`, `decimal()`, `restrictOnDelete()` y los atributos `#[Fillable]`/`#[Hidden]` existen en la versión instalada de Laravel 13 / Filament 5.
- **Archivos/áreas:**
  - `app/Enums/*.php` (18 archivos nuevos)
  - `app/Models/{Media,Page,Block,Post,Slider,Slide,Menu,MenuItem,Plan,PlanFeature,Subscription,PaymentMethod,Invoice,InvoiceItem,Transaction,Module,TenantModule,FormFieldDefinition,Form,FormField,Contact,ContactActivity}.php`
  - `app/Models/{Tenant,Setting}.php` (extendidos)
  - `database/migrations/2026_08_13_*.php` (25 archivos)
  - `docs/context/DECISIONS.md` (ADR-013)
- **Siguiente:**
  - Correr `php artisan migrate` en el entorno local real y validar contra PostgreSQL.
  - Construir los Filament Resources del contenido (Pages/Blocks, Posts, Sliders, Menus, Contacts) — explícitamente fuera de alcance de esta sesión.
  - Decidir con el humano cuándo abordar la capa de auth avanzada del DBML (roles/permissions tenant-aware, Passport OAuth, Sanctum, `social_accounts`, `tenant_user`) como sesión separada con su propio ADR.
- **Estado del código de aplicación:** Migraciones y modelos completos y revisados estáticamente; pendiente de ejecución real contra la base de datos.

---

## 2026-08-13 — Recursos de Filament (Console) simples con Slide-over

- **Agente/autor:** Tech Lead (Gemini)
- **Qué se hizo:**
  - Corregida la migración de `short_hash` agregando detección de driver (`DB::connection()->getDriverName()`) para usar `''` como valor predeterminado en SQLite, solventando la limitación de SQLite para añadir columnas con funciones dinámicas en sentencias ALTER TABLE sin sacrificar la lógica en Postgres (producción).
  - Rediseñados los recursos de contenido del panel Console (`PageResource`, `SliderResource`, `MenuResource`, `PostResource`, `MediaResource`) como CRUDs simples (`ManageRecords`) que se gestionan por completo a través de paneles laterales deslizables (`slideOver()`) directamente desde la vista del listado.
  - Integradas las relaciones secundarias como repetidores anidados (`blocks` en `Page`, `slides` en `Slider` e `items` en `Menu`) ordenables (`sort_order`) y colapsables dentro del formulario principal, eliminando la necesidad de archivos de Relation Managers independientes.
  - Unificados los namespaces de las acciones (`EditAction`, `DeleteAction`, `CreateAction`, `BulkActionGroup`, `DeleteBulkAction`) bajo el nuevo estándar unificado de Filament 5 (`Filament\Actions\*`), resolviendo el error de clase `EditAction` no encontrada.
  - Actualizados los recursos con firmas de métodos estrictamente compatibles con Filament 5 (`form(Schema $schema): Schema` e imports asociados).
  - Todos los tests de la suite de pruebas unitarias pasan con éxito (10 tests, 36 aserciones).
- **Archivos/areas:**
  - `database/migrations/2026_08_13_000001_add_business_fields_to_tenants_table.php`
  - `app/Filament/Resources/*` (MediaResource, PageResource, SliderResource, MenuResource, PostResource)
- **Siguiente:**
  - Desarrollar la API REST pública versión 1 (`/v1/pages`, `/v1/menus`, `/v1/settings`) para que puedan ser consumidas por el frontend estático de Astro o Next.js.
- **Estado del código de aplicación:** Completamente funcional, testeado y listo para pruebas en `console.genesisly.host`.

## 2026-08-12 — Base de datos genesis_cms, Settings tenant-aware, Subdominios y Paneles de Filament

- **Agente/autor:** Tech Lead (Gemini)
- **Qué se hizo:**
  - Creada la base de datos limpia `genesis_cms` en PostgreSQL Docker en el puerto 5434 y configurados `.env` y `.env.example`.
  - Definidos los nuevos ADRs de ID/UUID/Slug, estándar de API JSON, uso de Enums, tabla settings y subdominios con paneles de Filament.
  - Implementado el trait `HasUuid` para autogenerar UUIDs en inserciones y configurado `$routeKeyName` en modelos.
  - Creada la tabla y el modelo `Setting` con alcance tenant-aware (`tenant_id`) y clave única `(tenant_id, key)`.
  - Desarrollado el `SettingService` singleton y el helper global `setting($key, $default)` con caché optimizada para evitar queries N+1.
  - Creado el observer `SettingObserver` que invalida automáticamente la caché del inquilino al modificar una setting.
  - Configurados los subdominios de Filament eliminando `AdminPanelProvider` y añadiendo `PanelCmsProvider` (`console.genesisly.host` con tenancy) y `PanelManagerProvider` (`manager.genesisly.host` sin tenancy).
  - Diseñado y ejecutado el `DatabaseSeeder` para inicializar el super-admin global, el Cliente 0, sus dominios de resolución, el administrador del tenant y sus configuraciones iniciales.
  - Añadidas aserciones de UUID y de settings en `TenantIsolationTest`. Todos los 7 tests pasaron de forma exitosa (21 aserciones).
- **Archivos/áreas:**
  - `bootstrap/providers.php`, `bootstrap/app.php`, `composer.json`
  - `app/Traits/HasUuid.php`, `app/Models/Setting.php`, `app/Observers/SettingObserver.php`
  - `app/Services/SettingService.php`, `app/Helpers/settings_helper.php`
  - `app/Providers/Filament/PanelCmsProvider.php`, `app/Providers/Filament/PanelManagerProvider.php`
  - `database/migrations/*`, `database/seeders/DatabaseSeeder.php`
  - `tests/Feature/TenantIsolationTest.php`
- **Siguiente:**
  - Implementar los modelos de Contenido: `Page` y `Block`, y configurar sus recursos en el panel de Filament.
- **Estado del código de aplicación:** 100% funcional y testeado, base de datos limpia y estructura robusta.

## 2026-08-12 — Scaffold del proyecto y esqueleto multi-tenant

- **Agente/autor:** Tech Lead (Gemini)
- **Qué se hizo:**
  - Configurada la base de datos PostgreSQL Docker en el puerto 5434 y creado el schema `genesis`.
  - Instalado Filament v5 con soporte para Livewire v4 sobre Laravel 13.
  - Creadas las migraciones para `tenants`, `domains` y la relación en la tabla `users` dentro del schema `genesis`.
  - Implementado el singleton `TenantManager` y el middleware global `ResolveTenant` para resolver el tenant mediante hostnames, headers y parámetros.
  - Implementado el trait `HasTenant` con su respectivo global scope `TenantScope` para aislamiento de datos.
  - Integrado el multi-tenancy nativo de Filament en el modelo `User` (mediante el contrato `HasTenants`) y en `AdminPanelProvider`.
  - Creadas y verificadas las pruebas de aislamiento de datos en `TenantIsolationTest`. Todos los tests pasaron exitosamente.
- **Archivos/áreas:**
  - `.env`, `.env.example`, `config/database.php`, `bootstrap/app.php`
  - `app/Models/Tenant.php`, `app/Models/Domain.php`, `app/Models/User.php`
  - `app/Models/Scopes/TenantScope.php`, `app/Traits/HasTenant.php`
  - `app/Services/TenantManager.php`, `app/Http/Middleware/ResolveTenant.php`
  - `app/Providers/Filament/AdminPanelProvider.php`
  - `database/migrations/2026_08_12_000001_create_tenants_and_domains_tables.php`
  - `tests/Feature/TenantIsolationTest.php`
- **Siguiente:**
  - Implementar los modelos de Contenido: `Page` y `Block`, y configurar sus recursos en el panel de Filament.
- **Estado del código de aplicación:** Ejecutable y testeado, base multi-tenant lista para el MVP.

## 2026-08-11 — Creación de estructura de contexto del proyecto

- **Agente/autor:** Arquitecto / Tech Lead (sesión de bootstrap de docs)
- **Qué se hizo:**
  - Creada la carpeta `docs/context/` con la documentación operativa del proyecto.
  - Añadidos: `ARCHITECTURE.md`, `CURRENT_STATE.md`, `DECISIONS.md`, `TASK.md`, `PROGRESS.md`.
  - Añadidos en la raíz: `AGENTS.md`, `CLAUDE.md`, `GEMINI.md`, `README.md`.
  - Registradas decisiones iniciales (ADR-001 … ADR-006): nombre, stack, multi-tenancy, R2, freemium, Cliente 0.
  - Definida la tarea activa: bootstrap Laravel + Filament hacia MVP Headless Cliente 0.
- **Archivos/áreas:**
  - `docs/context/*`
  - `AGENTS.md`, `CLAUDE.md`, `GEMINI.md`, `README.md`
- **Siguiente:**
  - Scaffold del proyecto Laravel 11/12 + Filament + PostgreSQL.
  - Implementar esqueleto multi-tenant (`tenants`, `domains`, scopes).
- **Estado del código de aplicación:** aún no existe (repo de docs/contexto).
