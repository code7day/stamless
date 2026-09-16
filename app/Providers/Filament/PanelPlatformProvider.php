<?php

namespace App\Providers\Filament;

use App\Models\User;
use Caresome\FilamentAuthDesigner\AuthDesignerPlugin;
use Caresome\FilamentAuthDesigner\Data\AuthPageConfig;
use Caresome\FilamentAuthDesigner\Enums\MediaPosition;
use DutchCodingCompany\FilamentSocialite\FilamentSocialitePlugin;
use DutchCodingCompany\FilamentSocialite\Models\Contracts\FilamentSocialiteUser as FilamentSocialiteUserContract;
use DutchCodingCompany\FilamentSocialite\Provider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Panel de Plataforma — super-admins de Stamless.
 *
 * Dominio: platform.stamless.host (local) / platform.stamless.com (prod).
 * Sin tenancy activo — ve todos los tenants de la plataforma.
 * Ver config/stamless.php y ADR-012 / ADR-026.
 */
class PanelPlatformProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('platform')
            ->path('') // Serve at the root del dominio de Platform (ver config/stamless.php)
            ->domain(parse_url(config('stamless.urls.platform'), PHP_URL_HOST))
            ->viteTheme('resources/css/filament/cms/theme.css')
            ->brandName(fn (): HtmlString => new HtmlString(
                e(config('app.name')).' <span class="fi-logo-suffix">Platform</span>'
            ))
            ->login()
            // 2026-09-13, ADR-064 (paleta dual-primary de Stamless):
            // `Color::Indigo` era un valor de scaffold sin decisión de marca
            // detrás — se reemplaza por el segundo primary OFICIAL,
            // `--sl-platform-primary` (`#0F766E`, teal; hover conceptual
            // `#115E59`), a propósito DISTINTO del `#D97706` (amber) de
            // `PanelCmsProvider` (Studio): Platform es el panel B2B de
            // super-admins de la plataforma ("operación, control"), no el
            // panel de un tenant ("creación, taller") — el color ayuda a que
            // nunca se confundan a simple vista, sobre todo para alguien con
            // acceso a ambos. Explícitamente NO se reutiliza el amber de
            // Studio acá. `Color::hex()` genera su propia rampa OKLCH
            // (50-950), igual que en Studio.
            ->colors([
                'primary' => Color::hex('#0F766E'),
            ])
            // Mismo favicon de marca que Studio (íconos neutros, no
            // colorizados — ver `public/favicon/`), pero con su propio
            // `<meta name="theme-color">` teal en vez del amber de Studio,
            // coherente con el primary de este panel. Sin manifest propio
            // todavía (reusa los archivos estáticos de `public/favicon/`,
            // que no están atados a un panel) — si Platform necesita su
            // propio `site.webmanifest` (nombre/short_name distintos) más
            // adelante, se agrega ahí, no acá.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): HtmlString => new HtmlString(
                    '<link rel="icon" type="image/png" href="'.asset('favicon/favicon-96x96.png').'" sizes="96x96">'
                    .'<link rel="icon" type="image/svg+xml" href="'.asset('favicon/favicon.svg').'">'
                    .'<link rel="shortcut icon" href="'.asset('favicon.ico').'">'
                    .'<link rel="apple-touch-icon" sizes="180x180" href="'.asset('favicon/apple-touch-icon.png').'">'
                    .'<meta name="theme-color" content="#0F766E">'
                )
            )
            ->plugins([
                AuthDesignerPlugin::make()
                    ->login(fn (AuthPageConfig $config) => $config
                        ->media(asset('images/auth/stamless-login-cover.jpg'))
                        ->mediaPosition(MediaPosition::Right)
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
                        return redirect()->intended($plugin->getPanel()->getUrl());
                    }),
            ])
            ->discoverResources(in: app_path('Filament/Platform/Resources'), for: 'App\Filament\Platform\Resources')
            ->discoverPages(in: app_path('Filament/Platform/Pages'), for: 'App\Filament\Platform\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Platform/Widgets'), for: 'App\Filament\Platform\Widgets')
            ->widgets([
                AccountWidget::class,
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
            ]);
    }
}
