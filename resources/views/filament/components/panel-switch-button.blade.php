@props([
    'targetPanel' => 'platform',
    'targetUrl' => null,
])

@php
    $user = filament()->auth()->user() ?? auth()->user();

    // Solo visible si el usuario tiene acceso a Platform (Super Admin)
    if (! $user || ! $user->is_super_admin) {
        return;
    }

    $isToPlatform = $targetPanel === 'platform';
    $url = $targetUrl;

    if (! $url) {
        if ($isToPlatform) {
            $url = config('stamless.urls.platform');
        } else {
            $baseUrl = rtrim(config('stamless.urls.studio'), '/');
            $tenant = $user->tenant;
            $url = $tenant ? "{$baseUrl}/{$tenant->slug}" : $baseUrl;
        }
    }
@endphp

<div class="fi-panel-switcher-wrapper">
    <a
        href="{{ $url }}"
        title="{{ $isToPlatform ? 'Cambiar a Platform Manager' : 'Cambiar a Studio' }}"
        class="fi-panel-switcher-btn fi-panel-switcher-btn--{{ $isToPlatform ? 'manager' : 'studio' }}"
    >
        @if ($isToPlatform)
            <!-- Platform Manager Icon (Teal) -->
            <span class="fi-panel-switcher-icon-wrap">
                <svg class="fi-panel-switcher-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M4.25 2A2.25 2.25 0 0 0 2 4.25v2.5A2.25 2.25 0 0 0 4.25 9h2.5A2.25 2.25 0 0 0 9 6.75v-2.5A2.25 2.25 0 0 0 6.75 2h-2.5Zm0 9A2.25 2.25 0 0 0 2 13.25v2.5A2.25 2.25 0 0 0 4.25 18h2.5A2.25 2.25 0 0 0 9 15.75v-2.5A2.25 2.25 0 0 0 6.75 11h-2.5Zm9-9A2.25 2.25 0 0 0 11 4.25v2.5A2.25 2.25 0 0 0 13.25 9h2.5A2.25 2.25 0 0 0 18 6.75v-2.5A2.25 2.25 0 0 0 15.75 2h-2.5Zm0 9A2.25 2.25 0 0 0 11 13.25v2.5A2.25 2.25 0 0 0 13.25 18h2.5A2.25 2.25 0 0 0 18 15.75v-2.5A2.25 2.25 0 0 0 15.75 11h-2.5Z" clip-rule="evenodd" />
                </svg>
            </span>
            <span class="fi-panel-switcher-label">Manager</span>
            <span class="fi-panel-switcher-arrow">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor">
                    <path fill-rule="evenodd" d="M6.22 3.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L9.94 8 6.22 4.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                </svg>
            </span>
        @else
            <!-- Studio Icon (Amber) -->
            <span class="fi-panel-switcher-icon-wrap">
                <svg class="fi-panel-switcher-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path d="m2.695 14.762-1.262 3.155a.5.5 0 0 0 .65.65l3.155-1.262a4 4 0 0 0 1.343-.886L17.5 5.501a2.121 2.121 0 0 0-3-3L3.58 13.419a4 4 0 0 0-.885 1.343Z" />
                </svg>
            </span>
            <span class="fi-panel-switcher-label">Studio</span>
            <span class="fi-panel-switcher-arrow">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor">
                    <path fill-rule="evenodd" d="M6.22 3.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L9.94 8 6.22 4.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                </svg>
            </span>
        @endif
    </a>
</div>