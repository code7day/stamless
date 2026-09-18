<?php

namespace App\Providers\Filament;

use App\Filament\Pages\ChangePassword;
use App\Filament\Pages\Preferences;
use App\Filament\Widgets\LeadsOverviewWidget;
use App\Filament\Widgets\PlanStatusWidget;
use App\Filament\Widgets\PlanUsageWidget;
use App\Filament\Widgets\RecentContactsWidget;
use App\Filament\Widgets\WelcomeWidget;
use App\Http\Middleware\EnsurePasswordIsNotExpired;
use App\Http\Middleware\EnsureUserAccessesOwnTenant;
use App\Http\Middleware\FilamentAuthenticate;
use App\Http\Middleware\RedirectSuperAdminWithoutTenantToPlatform;
use App\Http\Middleware\SyncTenantManagerWithFilament;
use App\Models\Tenant;
use App\Models\User;
use Caresome\FilamentAuthDesigner\AuthDesignerPlugin;
use Caresome\FilamentAuthDesigner\Data\AuthPageConfig;
use Caresome\FilamentAuthDesigner\Enums\MediaPosition;
use DutchCodingCompany\FilamentSocialite\FilamentSocialitePlugin;
use DutchCodingCompany\FilamentSocialite\Models\Contracts\FilamentSocialiteUser as FilamentSocialiteUserContract;
use DutchCodingCompany\FilamentSocialite\Provider;
use Filament\Facades\Filament;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentAsset;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class PanelCmsProvider extends PanelProvider
{
    /**
     * CSS plano (sin Tailwind/build step) para las páginas custom del
     * grupo "Desarrolladores" — ver comentario de cabecera en
     * `public/css/filament/api-console.css` sobre por qué no se usan
     * clases utilitarias de Tailwind directamente en esos blade.
     */
    public function boot(): void
    {
        FilamentAsset::register([
            Css::make('api-console', asset('css/filament/api-console.css')),

            // 2026-09-02 — primer JS custom registrado en este panel, para
            // `App\Filament\Forms\Components\MenuTreeBuilder` (árbol de
            // menú drag-and-drop estilo WordPress). `Js::make(..., asset(...))`
            // en vez de `AlpineComponent::make()` (lo que usan los campos
            // nativos de Filament) a propósito: `AlpineComponent` depende
            // de `php artisan filament:assets` para publicar el archivo a
            // `public/`, comando que no se puede correr en este sandbox de
            // desarrollo — `Js::make()` con una URL ya pública (mismo
            // patrón que `api-console.css` arriba) no lo necesita, el
            // archivo ya vive directo en `public/js/filament/`.
            Js::make('menu-tree-builder', asset('js/filament/menu-tree-builder.js')),
        ]);
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('cms')
            ->path('') // Serve at the root del dominio de Studio (ver config/stamless.php)
            // 2026-09-14, pedido del Tech Lead: "activar el sidebar
            // collapsible" — nativo de Filament, colapsa a una barra angosta
            // con solo íconos (queda un botón para expandir/contraer), no
            // lo oculta del todo (`sidebarFullyCollapsibleOnDesktop()` es la
            // otra opción de Filament para eso, no lo que se pidió). Sin
            // costo de build: es puro Alpine/CSS que ya trae Filament, no
            // requiere `npm run build` ni tocar el theme del panel.
            ->sidebarCollapsibleOnDesktop()
            // 2026-09-02, fix real en vivo: `MenuTreeBuilder` se renderizaba
            // SIN estilos (sin drag handle, sin badges, sin bordes/cards) —
            // reportado con captura de "Editar Menú" mostrando una lista de
            // texto plano en vez de las tarjetas diseñadas. Causa raíz:
            // Filament NO incluye clases Tailwind arbitrarias en su CSS
            // precompilado — solo las que sus propios componentes usan. Sin
            // un theme propio, cualquier clase Tailwind usada en un Blade
            // custom (como `menu-tree-builder.blade.php`) simplemente no
            // tiene efecto, aunque el HTML/Alpine funcione bien (por eso la
            // jerarquía SÍ se veía indentada — ese `margin-left` es un
            // style inline, no una clase). Fix oficial de Filament: crear
            // un theme propio del panel (`php artisan make:filament-theme`,
            // replicado a mano acá por no haber runtime de PHP en el
            // sandbox de desarrollo) — `resources/css/filament/cms/theme.css`
            // importa el CSS base de Filament + declara vía `@source` que
            // escanee `resources/views/filament/**/*` (ya cubre el Blade
            // del builder) además de `app/Filament/**/*`. Requiere volver a
            // compilar (`npm run build`) para que tome efecto.
            ->viteTheme('resources/css/filament/cms/theme.css')
            ->domain(parse_url(config('stamless.urls.studio'), PHP_URL_HOST))
            ->login()
            ->tenant(Tenant::class, slugAttribute: 'slug')
            // 2026-09-13, pedido del Tech Lead: cada cuenta pertenece a
            // EXACTAMENTE 1 tenant (`User::getTenants()` siempre devuelve 0
            // o 1 elemento — `tenant_id` es una columna propia de `User`,
            // no un pivot muchos-a-muchos), así que el selector de tenant
            // de Filament (avatar + nombre + flecha "▾" en el sidebar) no
            // puede ofrecer nunca una segunda opción para cambiar — solo
            // insinúa una funcionalidad de multi-proyecto que no existe y
            // confunde ("como si tuviera acceso a más proyectos"). Se
            // apaga por completo (`tenantMenu(false)` saca el bloque
            // entero del sidebar, no solo la flecha) y, en su lugar, el
            // nombre del tenant pasa a ser el brand del panel — así cada
            // cliente ve "su" Studio (ej. "CICA360"), no un genérico
            // "Stamless" seguido de un selector que no hace nada.
            ->tenantMenu(false)
            // El brand del panel es el nombre del tenant + un sufijo
            // "Studio" (`.fi-logo-suffix` en el theme del panel, ver
            // `resources/css/filament/cms/theme.css`) — ej. "CICA360
            // Studio" — pero SOLO para tenants que pueden personalizarlo
            // (`canPersonalizeStudioBrand()`, 2026-09-13: gate de plan
            // pago, mismo criterio que `canEditCopyright()` — Free/
            // Freemium NO, Auspicio/Convenio y planes pagos SÍ). Un tenant
            // Free ve el genérico "Stamless Studio" (`config('app.name')`
            // + el mismo sufijo) — su nombre real de proyecto se muestra
            // en el bloque `sidebar-project-info` de abajo en su lugar
            // (ver `SIDEBAR_LOGO_AFTER` más abajo), NO acá. `HtmlString`
            // porque `getBrandName()` hace `{{ $brandName }}` en el Blade
            // de Filament (`components/logo.blade.php`) — Blade no escapa
            // un valor `Htmlable`, así que el `<span>` se renderiza tal
            // cual. `e($displayName)` igual escapa el nombre real del
            // tenant (dato de usuario) antes de insertarlo en el HTML.
            ->brandName(function (): HtmlString {
                $tenant = Filament::getTenant();

                $displayName = ($tenant instanceof Tenant && $tenant->canPersonalizeStudioBrand() && filled($tenant->name))
                    ? $tenant->name
                    : config('app.name');

                return new HtmlString(e($displayName).' <span class="fi-logo-suffix">Studio</span>');
            })
            // 2026-09-13, ADR-064 (paleta dual-primary de Stamless):
            // `Color::Amber` es literalmente el color de referencia/demo de
            // Filament (lo trae "de fábrica" cualquier panel sin
            // personalizar) — el Tech Lead lo notó ("el amber lo relacionan
            // con Filament básico") y se reemplaza por el primary OFICIAL de
            // Studio, `--sl-primary` (`#D97706`, hover conceptual
            // `#B45309`). `Color::hex()` genera una rampa de 11 tonos
            // (50-950) propia vía OKLCH a partir de este hex — es un array
            // NUEVO, no una referencia al `Amber` de Filament. Studio es el
            // panel de CADA TENANT ("creación, taller"); Platform (ver
            // `PanelPlatformProvider`) es el super-admin B2B y usa un primary
            // DISTINTO a propósito (teal `#0F766E`, "operación, control") —
            // el color ahora también sirve como señal de en qué panel estás
            // parado. El wordmark/logo de Stamless NO se recolorea con
            // ninguno de los dos (tinta `--sl-ink` `#171412`, ver ADR-064).
            // Runtime puro (Filament inyecta las CSS custom properties
            // `--primary-*` por request) — no requiere `npm run build`.
            ->colors([
                'primary' => Color::hex('#D97706'),
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): HtmlString => new HtmlString(
                    '<link rel="stylesheet" href="'.asset('css/filament/api-console.css').'?v='.filemtime(public_path('css/filament/api-console.css')).'">'
                    // 2026-09-02 — mismo criterio que el <link> de arriba
                    // (cache-busting con filemtime() en vez de confiar en
                    // que el registro plano de `FilamentAsset::register()`
                    // alcance solo para inyectar el <script> en el <head>
                    // de TODAS las páginas del panel, no solo donde el
                    // campo `MenuTreeBuilder` esté presente — el script
                    // registra `Alpine.data('menuTreeBuilder', ...)` en
                    // `alpine:init`, así que tiene que estar cargado ANTES
                    // de que Alpine arranque, sin importar en qué página).
                    .'<script src="'.asset('js/filament/menu-tree-builder.js').'?v='.filemtime(public_path('js/filament/menu-tree-builder.js')).'" defer></script>'
                )
            )
            // Favicon de Stamless (2026-09-13, pedido del Tech Lead) — set
            // completo ya generado y publicado en `public/favicon/` +
            // `public/favicon.ico` (no requiere build de Vite, son archivos
            // estáticos servidos tal cual). Segundo `->renderHook()` sobre el
            // mismo `HEAD_END` que el de arriba a propósito: Filament
            // ACUMULA los closures por hook (`HasRenderHooks::renderHook()`
            // los apila en un array, no los reemplaza), así que separarlo
            // deja cada bloque con una sola responsabilidad (activos
            // versionados con `filemtime()` arriba, favicon estático acá)
            // sin tener que tocar el closure existente. Sin `filemtime()`
            // acá: a diferencia del CSS/JS de arriba (que cambian seguido en
            // desarrollo), un favicon prácticamente nunca se reemplaza en
            // caliente. `site.webmanifest` también se actualizó (`name`/
            // `short_name`/`theme_color`) — venía con los valores default
            // del generador ("MyWebSite"/blanco), ahora dice "Stamless" y
            // usa el ámbar del panel (`Color::Amber`, ver `->colors()` más
            // abajo).
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): HtmlString => new HtmlString(
                    '<link rel="icon" type="image/png" href="'.asset('favicon/favicon-96x96.png').'" sizes="96x96">'
                    .'<link rel="icon" type="image/svg+xml" href="'.asset('favicon/favicon.svg').'">'
                    .'<link rel="shortcut icon" href="'.asset('favicon.ico').'">'
                    .'<link rel="apple-touch-icon" sizes="180x180" href="'.asset('favicon/apple-touch-icon.png').'">'
                    .'<link rel="manifest" href="'.asset('favicon/site.webmanifest').'">'
                    // ADR-064: color de la barra de UI del navegador (pestañas
                    // Android/PWA), alineado al mismo `--sl-primary` del panel.
                    .'<meta name="theme-color" content="#D97706">'
                )
            )
            // 2026-09-13, pedido del Tech Lead: donde antes estaba el
            // selector de tenant (ahora apagado, `tenantMenu(false)` arriba)
            // va un bloque estático "nombre del proyecto" + "Plan actual:
            // X" en letra más chica debajo — pero SOLO para Free/Freemium
            // (`! canPersonalizeStudioBrand()`): "los auspicios y otros
            // planes de pago solo mostrarán el titular Studio" — un tenant
            // de pago ya ve su nombre real personalizado en el brand de
            // arriba (ej. "CICA360 Studio"), así que este bloque sería
            // información duplicada; para Free, en cambio, el brand
            // muestra el genérico "Stamless Studio", así que ESTE es el
            // único lugar donde ese tenant ve su nombre de proyecto real,
            // más un recordatorio de su plan actual. Mismo hook
            // (`SIDEBAR_LOGO_AFTER`) donde Filament renderiza el
            // `<x-filament-panels::tenant-menu />` original — misma
            // posición visual, contenido no interactivo (sin dropdown/
            // flecha) en vez del switcher.
            ->renderHook(
                PanelsRenderHook::SIDEBAR_LOGO_AFTER,
                function (): HtmlString {
                    $tenant = Filament::getTenant();

                    if (! ($tenant instanceof Tenant) || $tenant->canPersonalizeStudioBrand()) {
                        return new HtmlString('');
                    }

                    return new HtmlString(view('filament.cms.sidebar-project-info', [
                        'projectName' => $tenant->name,
                        'planLabel' => $tenant->planLabel(),
                    ])->render());
                }
            )
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): HtmlString => new HtmlString(view('filament.components.panel-switch-button', [
                    'targetPanel' => 'platform',
                ])->render())
            )
            ->plugins([
                AuthDesignerPlugin::make()
                    ->login(fn (AuthPageConfig $config) => $config
                        ->media(asset('images/auth/stamless-login-cover.jpg'))
                        ->mediaPosition(MediaPosition::Left)
                        ->themeToggle()
                    ),
                FilamentSocialitePlugin::make()
                    ->providers([
                        Provider::make('google')
                            ->label('Google')
                            ->icon('fab-google')
                            ->outlined(true)
                            ->stateless(true)
                            ->visible(fn (): bool => ! empty(config('services.google.client_id')) && ! empty(config('services.google.client_secret'))),
                        Provider::make('linkedin-openid')
                            ->label('LinkedIn')
                            ->icon('fab-linkedin')
                            ->outlined(true)
                            ->stateless(true)
                            ->visible(fn (): bool => ! empty(config('services.linkedin-openid.client_id')) && ! empty(config('services.linkedin-openid.client_secret'))),
                        Provider::make('twitter-oauth-2')
                            ->label('X')
                            ->icon('fab-x-twitter')
                            ->outlined(true)
                            ->stateless(true)
                            ->visible(fn (): bool => ! empty(config('services.twitter-oauth-2.client_id')) && ! empty(config('services.twitter-oauth-2.client_secret'))),
                        Provider::make('instagram')
                            ->label('Instagram')
                            ->icon('fab-instagram')
                            ->outlined(true)
                            ->stateless(true)
                            ->visible(fn (): bool => ! empty(config('services.instagram.client_id')) && ! empty(config('services.instagram.client_secret'))),
                        Provider::make('facebook')
                            ->label('Facebook')
                            ->icon('fab-facebook')
                            ->outlined(true)
                            ->stateless(true)
                            ->visible(fn (): bool => ! empty(config('services.facebook.client_id')) && ! empty(config('services.facebook.client_secret'))),
                        Provider::make('microsoft')
                            ->label('Microsoft')
                            ->icon('fab-microsoft')
                            ->outlined(true)
                            ->stateless(true)
                            ->visible(fn (): bool => ! empty(config('services.microsoft.client_id')) && ! empty(config('services.microsoft.client_secret'))),
                    ])
                    ->registration(fn (string $provider, mixed $oauthUser, ?User $user): bool => $user !== null)
                    ->redirectAfterLoginUsing(function (string $provider, FilamentSocialiteUserContract $socialiteUser, FilamentSocialitePlugin $plugin) {
                        /** @var User $user */
                        $user = $socialiteUser->getUser();
                        $panel = $plugin->getPanel();

                        if ($user->is_super_admin) {
                            if ($user->tenant) {
                                return redirect()->intended($panel->getUrl($user->tenant));
                            }

                            return redirect()->to(config('stamless.urls.platform'));
                        }

                        if ($panel->hasTenancy() && $user->tenant) {
                            return redirect()->intended($panel->getUrl($user->tenant));
                        }

                        return redirect()->intended($panel->getUrl());
                    }),
            ])
            ->userMenuItems([
                MenuItem::make()
                    ->label('Ir a Platform Manager')
                    ->icon('heroicon-o-squares-2x2')
                    ->url(fn (): string => config('stamless.urls.platform'))
                    ->visible(fn (): bool => auth()->user()?->is_super_admin ?? false),
                MenuItem::make()
                    ->label('Preferencias')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->url(fn (): string => Preferences::getUrl(['tenant' => Filament::getTenant() ?? auth()->user()?->tenant]))
                    // 2026-09-18, bug real reportado por el Tech Lead: este
                    // ítem no tenía `->visible()` — se mostraba a CUALQUIER
                    // rol en el dropdown del avatar, aunque `Preferences::
                    // canAccess()` (ADR-078, `[Admin, Soporte]` desde la
                    // expansión de roles) diera `false` para ese usuario —
                    // probado en vivo con una cuenta `Marketing`, que veía el
                    // link y recibía un 403 al hacer clic. `MenuItem` no
                    // pasa por ninguna Policy/canAccess automático de
                    // Filament (a diferencia de un Resource o Page en el
                    // sidebar) — hay que gatearlo a mano, igual que
                    // "Ir a Platform Manager" arriba.
                    ->visible(fn (): bool => Preferences::canAccess()),
                MenuItem::make()
                    ->label('Cambiar contraseña')
                    ->icon('heroicon-o-lock-closed')
                    ->url(fn (): string => ChangePassword::getUrl(['tenant' => Filament::getTenant() ?? auth()->user()?->tenant])),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            // 2026-09-13, pedido del Tech Lead (Nivel 1 del plan de
            // Dashboard): "quitar el widget Filament, dejar el de
            // bienvenida (pero agregar en un badge el tipo de plan)".
            // `FilamentInfoWidget` (promo/versión del framework, sin valor
            // para el cliente) se saca; `AccountWidget` nativo se reemplaza
            // por `WelcomeWidget` propio (mismo saludo + botón de salir,
            // vista propia con el badge de plan agregado) — ver su docblock.
            // Se suman 4 widgets más, mismo día, siguiente pedido ("faltan
            // los widgets para mostrar todo estos indicadores" + "falta un
            // widget en segundo orden... plan y botón de mejorar plan"):
            // `$sort` de cada clase controla el orden (Welcome=-50,
            // PlanStatus=-40, Leads=-30, PlanUsage=-20, RecentContacts=-10,
            // espaciados de a 10 para poder insertar uno nuevo en el medio
            // sin renumerar todo) — no hace falta ordenar el array acá,
            // Filament ordena por `$sort` al armar el Dashboard. Welcome y
            // PlanStatus comparten la primera fila (columna 1 cada uno);
            // PlanUsage y RecentContacts comparten la última.
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                WelcomeWidget::class,
                PlanStatusWidget::class,
                LeadsOverviewWidget::class,
                PlanUsageWidget::class,
                RecentContactsWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                FilamentAuthenticate::class,
                RedirectSuperAdminWithoutTenantToPlatform::class,
                EnsureUserAccessesOwnTenant::class,
                EnsurePasswordIsNotExpired::class,
            ])
            // 2026-09-02, fix bug real en vivo (tenant_id NOT NULL al crear
            // submenús anidados): puentea `Filament::getTenant()` hacia
            // `App\Services\TenantManager`, del que depende `HasTenant`
            // para autocompletar `tenant_id` en cualquier modelo creado
            // dentro de Studio (incluidos los creados por Repeaters
            // anidados con `->relationship()`, como `MenuItem`/`Slide`).
            // Va en `tenantMiddleware` (no en `middleware`/`authMiddleware`
            // de arriba) porque Filament coloca `IdentifyTenant` primero en
            // ese grupo — recién ahí `Filament::getTenant()` ya resolvió.
            // `isPersistent: true` es OBLIGATORIO: los guardados de
            // Filament (crear/editar registros, incluido el Repeater
            // anidado que disparó este bug) no van por una navegación
            // normal sino por el endpoint AJAX de Livewire
            // (`/livewire-xxx/update`, confirmado en el stack trace real
            // del error) — ese endpoint NO vuelve a correr el middleware
            // "normal" del panel, solo el subset marcado explícitamente
            // como persistente (`Livewire::addPersistentMiddleware()`).
            // Sin este flag, el bridge corre en el request inicial de
            // página (GET) pero no en el POST de guardado — que es
            // exactamente donde se necesita `tenant_id` resuelto.
            // Ver docblock de `SyncTenantManagerWithFilament` para el
            // detalle completo de la causa raíz.
            ->tenantMiddleware([
                SyncTenantManagerWithFilament::class,
            ], isPersistent: true);
    }
}
