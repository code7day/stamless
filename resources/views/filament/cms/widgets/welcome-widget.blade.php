@php
    $user = filament()->auth()->user();
    $roleLabel = $this->getRoleLabel();
@endphp

<x-filament-widgets::widget class="fi-account-widget h-full">
    <x-filament::section class="h-full flex flex-col justify-center">
        <div class="flex items-center justify-between gap-x-4 w-full">
            <div class="flex items-center gap-x-4 min-w-0 flex-1">
                <x-filament-panels::avatar.user
                    size="lg"
                    :user="$user"
                    loading="lazy"
                />

                <div class="fi-account-widget-main min-w-0 flex-1">
                    <h2 class="fi-account-widget-heading">
                        {{ __('filament-panels::widgets/account-widget.welcome', ['app' => config('app.name')]) }}
                    </h2>

                    <p class="fi-account-widget-user-name flex flex-wrap items-center gap-1.5 mt-0.5">
                        <span class="truncate">{{ filament()->getUserName($user) }}</span>

                        @if ($roleLabel)
                            <x-filament::badge :color="$this->getRoleBadgeColor()" size="sm" class="fi-welcome-widget-role-badge">
                                {{ $roleLabel }}
                            </x-filament::badge>
                        @endif
                    </p>
                </div>
            </div>

            <form
                action="{{ filament()->getLogoutUrl() }}"
                method="post"
                class="fi-account-widget-logout-form shrink-0 ms-auto"
            >
                @csrf

                <x-filament::button
                    color="gray"
                    :icon="\Filament\Support\Icons\Heroicon::ArrowLeftEndOnRectangle"
                    :icon-alias="\Filament\View\PanelsIconAlias::WIDGETS_ACCOUNT_LOGOUT_BUTTON"
                    labeled-from="sm"
                    size="sm"
                    tag="button"
                    type="submit"
                >
                    {{ __('filament-panels::widgets/account-widget.actions.logout.label') }}
                </x-filament::button>
            </form>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
