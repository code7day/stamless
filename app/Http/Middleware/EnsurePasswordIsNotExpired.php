<?php

namespace App\Http\Middleware;

use App\Filament\Pages\ChangePassword;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsNotExpired
{
    /**
     * Handle an incoming request.
     *
     * Si el usuario autenticado tiene activo `must_change_password = true`,
     * se le redirige obligatoriamente a la página de cambio de contraseña
     * antes de permitirle navegar otras áreas del panel.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $user->must_change_password) {
            return $next($request);
        }

        // Permitir peticiones a la página de cambiar contraseña, logout y requests de autenticación
        if ($request->routeIs('filament.*.pages.change-password')
            || $request->routeIs('filament.*.auth.logout')
            || str_contains($request->path(), 'change-password')
            || str_contains($request->path(), 'logout')
        ) {
            return $next($request);
        }

        // Permitir llamadas AJAX de Livewire que afecten al formulario de ChangePassword
        if ($request->hasHeader('X-Livewire')) {
            $fingerprint = $request->input('components.0.snapshot') ?? $request->input('fingerprint');
            if (is_string($fingerprint) && str_contains($fingerprint, 'ChangePassword')) {
                return $next($request);
            }
        }

        $tenant = $user->tenant ?? Filament::getTenant();

        return redirect()->to(ChangePassword::getUrl(['tenant' => $tenant]));
    }
}
