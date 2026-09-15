@php
    $tenant = $this->getTenant();
    $onTopPlan = $this->isOnTopPlan();
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex h-full flex-col justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-gray-500 dark:text-gray-400">Tu plan:</span>

                    <span class="text-lg font-semibold text-gray-950 dark:text-white">
                        {{ $tenant?->planLabel() ?? '—' }}
                    </span>

                    @if ($onTopPlan)
                        <x-filament::badge color="success" size="sm">
                            Plan más completo
                        </x-filament::badge>
                    @endif
                </div>

                <p class="text-sm text-gray-500 dark:text-gray-400">
                    @if ($onTopPlan)
                        Incluye acceso a todos los beneficios disponibles hoy.
                    @else
                        Desbloquear más contenido, sliders, testimonios y multimedia.
                    @endif
                </p>
            </div>

            @unless ($onTopPlan)
                <x-filament::button
                    tag="a"
                    :href="$this->getUpgradeMailtoUrl()"
                    color="primary"
                    icon="heroicon-m-arrow-trending-up"
                    class="w-fit"
                >
                    Mejorar plan
                </x-filament::button>
            @endunless
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
