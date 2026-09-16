<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectSuperAdminWithoutTenantToPlatform
{
    /**
     * Handle an incoming request.
     *
     * Redirige al panel Platform a los Super Admins que NO tengan un proyecto/tenant
     * asignado como cliente propio cuando ingresan a la raíz de Studio.
     * Si tienen un proyecto propio, Filament los redirige a su tenant en Studio.
     * Si acceden directamente a una ruta /{tenant}/..., se les permite navegar por ser superadmin.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if ($user instanceof User && $user->is_super_admin && ! $user->tenant) {
            $routeTenant = $request->route('tenant');

            if (blank($routeTenant) && ($request->routeIs('filament.cms.tenant') || $request->path() === '' || $request->path() === '/')) {
                return redirect()->to(config('stamless.urls.platform'));
            }
        }

        return $next($request);
    }
}
