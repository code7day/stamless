<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserAccessesOwnTenant
{
    /**
     * Handle an incoming request.
     *
     * Si un usuario de tenant autenticado intenta acceder a una ruta de Studio con un slug
     * de otro tenant (o que no existe), se le redirige automáticamente a su propio tenant
     * en lugar de recibir un 404 / 403.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $panel = Filament::getCurrentOrDefaultPanel();

        if (! $panel->hasTenancy()) {
            return $next($request);
        }

        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $routeTenant = $request->route()?->parameter('tenant');

        if (blank($routeTenant)) {
            return $next($request);
        }

        $requestedSlug = $routeTenant instanceof Model
            ? ($routeTenant->getAttribute('slug') ?? (string) $routeTenant->getKey())
            : (string) $routeTenant;

        // 1. Caso Usuario regular de Tenant (no Super Admin)
        if (! $user->is_super_admin) {
            $userTenant = $user->tenant;

            if ($userTenant instanceof Tenant) {
                // Si el slug en la URL no coincide con su tenant asignado:
                if ($requestedSlug !== $userTenant->slug && (string) $userTenant->id !== $requestedSlug) {
                    return redirect()->to($panel->getUrl($userTenant));
                }
            }

            return $next($request);
        }

        // 2. Caso Super Admin: si el slug solicitado no existe en la BD:
        $query = Tenant::where('slug', $requestedSlug);
        if (is_numeric($requestedSlug)) {
            $query->orWhere('id', (int) $requestedSlug);
        }
        $exists = $query->exists();

        if (! $exists) {
            if ($user->tenant instanceof Tenant) {
                return redirect()->to($panel->getUrl($user->tenant));
            }

            return redirect()->to(config('stamless.urls.platform'));
        }

        return $next($request);
    }
}
