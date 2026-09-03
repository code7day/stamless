<?php

namespace App\Providers\Filament;

use App\Filament\Pages\ChangePassword;
use App\Filament\Pages\Preferences;
use App\Http\Middleware\SyncTenantManagerWithFilament;
use App\Models\Tenant;
use Filament\Http\Middleware\Authenticate;
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
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
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
            ->colors([
                'primary' => Color::Amber,
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
            ->userMenuItems([
                MenuItem::make()
                    ->label('Preferencias')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->url(fn (): string => Preferences::getUrl()),
                MenuItem::make()
                    ->label('Cambiar contraseña')
                    ->icon('heroicon-o-lock-closed')
                    ->url(fn (): string => ChangePassword::getUrl()),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
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
                Authenticate::class,
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
