@php
    $tenant = $this->getTenant();
    $badge = $this->getPlanBadge();
    $canUpgrade = $this->canUpgrade();
@endphp

<x-filament-widgets::widget class="h-full">
    <x-filament::section class="h-full flex flex-col justify-center">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs md:text-sm text-gray-500 dark:text-gray-400">Tu plan:</span>

                    <span class="text-base font-semibold text-gray-950 dark:text-white">
                        {{ $tenant?->planLabel() ?? '—' }}
                    </span>

                    <x-filament::badge :color="$badge['color']" size="sm">
                        {{ $badge['label'] }}
                    </x-filament::badge>
                </div>

                <p class="mt-1 text-xs md:text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                    {{ $this->getPlanDescription() }}
                </p>
            </div>

            @if ($canUpgrade)
                <div class="shrink-0">
                    {{ $this->consultAction }}
                </div>
            @endif
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>

