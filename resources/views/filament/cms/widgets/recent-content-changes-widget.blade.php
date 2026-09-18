<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Últimos cambios
        </x-slot>

        @php
            $items = $this->getItems();
        @endphp

        @if (count($items) > 0)
            <div class="fi-wi-recent-content-changes-list grid grid-cols-1 gap-3 max-h-[340px] overflow-y-auto overscroll-contain pr-1.5 -mr-1.5">
                @foreach ($items as $item)
                    <a
                        href="{{ $item['url'] }}"
                        class="flex items-center gap-3 rounded-xl border border-gray-200 p-3 transition hover:border-gray-300 dark:border-white/10 dark:hover:border-white/20"
                    >
                        <x-filament::icon
                            :icon="$item['icon']"
                            class="h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500"
                        />

                        <div class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-gray-950 dark:text-white">
                                {{ $item['title'] }}
                            </span>

                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                {{ \App\Support\FriendlyDate::format($item['updated_at']) }}
                            </span>
                        </div>

                        <x-filament::badge :color="$item['color']" class="shrink-0">
                            {{ $item['type'] }}
                        </x-filament::badge>
                    </a>
                @endforeach
            </div>
        @else
            <div class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                Todavía no hay contenido para mostrar acá.
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
