<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Uso del plan
        </x-slot>

        <div class="fi-wi-plan-usage-list grid grid-cols-1 gap-3 max-h-[340px] overflow-y-auto overscroll-contain pr-1.5 -mr-1.5">
            @foreach ($this->getRows() as $row)
                @php
                    $percent = $this->getPercent($row['count'], $row['limit']);
                    $barColor = $this->getBarColorClass($row['count'], $row['limit']);
                @endphp

                {{--
                    2026-09-14, ADR-067: `url` puede venir en `null` (por
                    ahora, solo la fila "Multimedia" para tenants Free/
                    Auspicio sin acceso a `MediaResource`, ver
                    `PlanUsageWidget::getRows()`) — en ese caso se renderiza
                    como `<div>` en vez de `<a>`: sigue mostrando el
                    conteo/barra (el "aviso" pedido), pero sin `href` ni el
                    estado hover que sugeriría que se puede hacer click.
                --}}
                @if ($row['url'] !== null)
                    <a
                        href="{{ $row['url'] }}"
                        class="block rounded-xl border border-gray-200 p-3 transition hover:border-gray-300 dark:border-white/10 dark:hover:border-white/20"
                    >
                        <div class="mb-1.5 flex items-center justify-between gap-2">
                            <span class="text-sm font-medium text-gray-950 dark:text-white">
                                {{ $row['label'] }}
                            </span>

                            <span class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                                {{ $row['limit'] !== null ? "{$row['count']}/{$row['limit']}" : $row['count'] }}
                            </span>
                        </div>

                        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                            <div class="h-full rounded-full {{ $barColor }}" style="width: {{ $percent }}%"></div>
                        </div>
                    </a>
                @else
                    <div class="block rounded-xl border border-gray-200 p-3 dark:border-white/10">
                        <div class="mb-1.5 flex items-center justify-between gap-2">
                            <span class="text-sm font-medium text-gray-950 dark:text-white">
                                {{ $row['label'] }}
                            </span>

                            <span class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                                {{ $row['limit'] !== null ? "{$row['count']}/{$row['limit']}" : $row['count'] }}
                            </span>
                        </div>

                        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                            <div class="h-full rounded-full {{ $barColor }}" style="width: {{ $percent }}%"></div>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
