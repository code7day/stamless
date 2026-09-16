<?php

namespace App\Http\Middleware;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate as BaseAuthenticate;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class FilamentAuthenticate extends BaseAuthenticate
{
    /**
     * @param  Request  $request
     * @param  array<string>  $guards
     */
    protected function authenticate($request, array $guards): void
    {
        $guard = Filament::auth();

        if (! $guard->check()) {
            $this->unauthenticated($request, $guards);

            return;
        }

        $this->auth->shouldUse(Filament::getAuthGuard());

        /** @var Model|User $user */
        $user = $guard->user();

        $panel = Filament::getCurrentOrDefaultPanel();

        $canAccess = $user instanceof FilamentUser ?
            $user->canAccessPanel($panel) :
            (config('app.env') === 'local');

        if (! $canAccess) {
            // Si el usuario no tiene permiso en Platform pero sí tiene acceso a Studio (CMS),
            // redirigir suavemente a Studio en vez de bloquear con 403 Forbidden.
            if ($panel->getId() === 'platform') {
                $cmsPanel = Filament::getPanel('cms', isStrict: false);
                if ($cmsPanel && $user instanceof FilamentUser && $user->canAccessPanel($cmsPanel)) {
                    abort(redirect()->to(config('stamless.urls.studio')));
                }
            }

            // Si el usuario no tiene permiso en Studio (CMS) pero tiene acceso a Platform,
            // redirigir a Platform en vez de bloquear con 403 Forbidden.
            if ($panel->getId() === 'cms') {
                $platformPanel = Filament::getPanel('platform', isStrict: false);
                if ($platformPanel && $user instanceof FilamentUser && $user->canAccessPanel($platformPanel)) {
                    abort(redirect()->to(config('stamless.urls.platform')));
                }
            }

            abort(403);
        }
    }
}
