<?php

namespace App\Http\Responses;

use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as Responsable;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class FilamentLoginResponse implements Responsable
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();
        $panel = Filament::getCurrentOrDefaultPanel();

        if ($user && $user->is_super_admin) {
            if ($panel->getId() === 'cms') {
                if ($user->tenant) {
                    return redirect()->intended($panel->getUrl($user->tenant));
                }

                return redirect()->to(config('stamless.urls.platform'));
            }

            return redirect()->intended($panel->getUrl());
        }

        if ($panel->hasTenancy() && $user?->tenant) {
            return redirect()->intended($panel->getUrl($user->tenant));
        }

        return redirect()->intended($panel->getUrl());
    }
}
