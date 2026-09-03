@php
    use Illuminate\Support\Js;
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="menuTreeBuilder({
            state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$getStatePath()}')") }},
            maxDepth: {{ $getMaxDepth() }},
            pageOptions: {{ Js::from($getPageOptions()) }},
            postOptions: {{ Js::from($getPostOptions()) }},
            serviceOptions: {{ Js::from($getServiceOptions()) }},
            typeOptions: {{ Js::from($getTypeOptions()) }},
            targetOptions: {{ Js::from($getTargetOptions()) }},
        })"
        wire:ignore
        class="fi-menu-tree-builder"
    >
        <ul x-ref="list" class="flex flex-col gap-2">
            <template x-for="(item, index) in items" :key="item.key">
                <li
                    class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800"
                    x-bind:style="{ marginLeft: (item.depth * 32) + 'px' }"
                >
                    <div class="flex items-center gap-2 px-3 py-2">
                        <button
                            type="button"
                            data-menu-tree-handle
                            class="cursor-grab text-gray-400 hover:text-gray-600 active:cursor-grabbing dark:hover:text-gray-300"
                            title="Arrastrar para reordenar / anidar (arrastrá a la derecha para convertir en sub-elemento)"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5">
                                <path d="M7 4a1 1 0 100 2 1 1 0 000-2zM7 9a1 1 0 100 2 1 1 0 000-2zM7 14a1 1 0 100 2 1 1 0 000-2zM13 4a1 1 0 100 2 1 1 0 000-2zM13 9a1 1 0 100 2 1 1 0 000-2zM13 14a1 1 0 100 2 1 1 0 000-2z" />
                            </svg>
                        </button>

                        <div class="flex shrink-0 items-center gap-0.5">
                            <button
                                type="button"
                                x-show="canOutdent(index)"
                                x-on:click="outdent(index)"
                                class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-300"
                                title="Quitar un nivel de anidamiento"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                                    <path fill-rule="evenodd" d="M17 10a.75.75 0 01-.75.75H5.612l4.158 3.96a.75.75 0 11-1.04 1.08l-5.5-5.25a.75.75 0 010-1.08l5.5-5.25a.75.75 0 111.04 1.08L5.612 9.25H16.25A.75.75 0 0117 10z" clip-rule="evenodd" />
                                </svg>
                            </button>

                            <button
                                type="button"
                                x-show="canIndent(index)"
                                x-on:click="indent(index)"
                                class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-300"
                                title="Convertir en sub-elemento del anterior"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                                    <path fill-rule="evenodd" d="M3 10a.75.75 0 01.75-.75h10.638L10.23 5.29a.75.75 0 111.04-1.08l5.5 5.25a.75.75 0 010 1.08l-5.5 5.25a.75.75 0 11-1.04-1.08l4.158-3.96H3.75A.75.75 0 013 10z" clip-rule="evenodd" />
                                </svg>
                            </button>
                        </div>

                        <button
                            type="button"
                            x-on:click="toggleOpen(index)"
                            class="flex-1 truncate text-left text-sm font-medium text-gray-950 dark:text-white"
                            x-text="item.title || 'Nuevo elemento'"
                        ></button>

                        <span
                            class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500 dark:bg-gray-700 dark:text-gray-400"
                            x-text="typeOptions[item.type] ?? item.type"
                        ></span>

                        <button
                            type="button"
                            x-on:click="toggleOpen(index)"
                            class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-300"
                            :title="item.isOpen ? 'Contraer' : 'Editar'"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 transition-transform" x-bind:class="item.isOpen ? 'rotate-180' : ''">
                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                            </svg>
                        </button>

                        <button
                            type="button"
                            x-on:click="removeItem(index)"
                            class="rounded p-1 text-danger-500 hover:bg-danger-50 dark:hover:bg-danger-500/10"
                            title="Eliminar"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                                <path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 006 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 10.23 1.482l.149-.022.841 10.518A2.75 2.75 0 007.596 19h4.807a2.75 2.75 0 002.742-2.53l.841-10.52.149.023a.75.75 0 00.23-1.482A41.03 41.03 0 0014 4.193V3.75A2.75 2.75 0 0011.25 1h-2.5zM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4zM8.58 7.72a.75.75 0 00-1.5.06l.3 7.5a.75.75 0 101.5-.06l-.3-7.5zm4.34.06a.75.75 0 10-1.5-.06l-.3 7.5a.75.75 0 101.5.06l.3-7.5z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </div>

                    <div x-show="item.isOpen" class="grid grid-cols-2 gap-3 border-t border-gray-100 px-3 py-3 dark:border-gray-700">
                        {{-- 2026-09-02 — reportado en vivo: estos campos se veían sin
                             ningún estilo (inputs/selects planos del navegador) pese al
                             fix del theme.css custom del panel. Causa: `fi-input`/
                             `fi-select`/etc. sueltos como CLASE no alcanzan — el
                             box/borde/fondo real de un campo de Filament viene del
                             componente Blade `<x-filament::input.wrapper>` (clase
                             `fi-input-wrp`), no del `<input>`/`<select>` en sí. Esas son
                             clases SEMÁNTICAS propias del core de Filament (con su CSS
                             ya precompilado de fábrica) — no clases Tailwind
                             arbitrarias, así que ni siquiera dependen del theme custom
                             agregado para el resto de este campo. Se reemplazan los
                             `<input>`/`<select>`/checkbox a mano por los componentes
                             reales de Filament (mismo patrón usado internamente por
                             `vendor/filament/support/.../pagination/index.blade.php` y
                             `vendor/filament/tables/.../search-field.blade.php`), con
                             los bindings de Alpine (`x-model`/`x-on:*`) pasados como
                             atributos extra — Blade los reenvía tal cual al elemento
                             real vía `$attributes`. --}}
                        <div class="col-span-2">
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Etiqueta / Título</label>
                            <div class="mt-1">
                                <x-filament::input.wrapper>
                                    <x-filament::input
                                        type="text"
                                        x-model="item.title"
                                        x-on:input="syncState()"
                                    />
                                </x-filament::input.wrapper>
                            </div>
                        </div>

                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Tipo de enlace</label>
                            <div class="mt-1">
                                <x-filament::input.wrapper>
                                    <x-filament::input.select
                                        x-model="item.type"
                                        x-on:change="onTypeChanged(index)"
                                    >
                                        <template x-for="(label, value) in typeOptions" :key="value">
                                            <option :value="value" x-text="label"></option>
                                        </template>
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </div>
                        </div>

                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Destino</label>
                            <div class="mt-1">
                                <x-filament::input.wrapper>
                                    <x-filament::input.select
                                        x-model="item.target"
                                        x-on:change="syncState()"
                                    >
                                        <template x-for="(label, value) in targetOptions" :key="value">
                                            <option :value="value" x-text="label"></option>
                                        </template>
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </div>
                        </div>

                        <div class="col-span-2" x-show="needsReference(item.type)">
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400" x-text="referenceLabel(item.type)"></label>
                            <div class="mt-1">
                                <x-filament::input.wrapper>
                                    <x-filament::input.select
                                        x-model.number="item.reference_id"
                                        x-on:change="syncState()"
                                    >
                                        <option value="">—</option>
                                        <template x-for="(label, value) in referenceOptionsFor(item.type)" :key="value">
                                            <option :value="value" x-text="label"></option>
                                        </template>
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </div>
                        </div>

                        <div class="col-span-2" x-show="needsUrl(item.type)">
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">URL / Ruta</label>
                            <div class="mt-1">
                                <x-filament::input.wrapper>
                                    <x-filament::input
                                        type="text"
                                        x-model="item.url"
                                        x-on:input="syncState()"
                                        placeholder="https://ejemplo.com o /ruta-interna"
                                    />
                                </x-filament::input.wrapper>
                            </div>
                        </div>

                        <label class="col-span-2 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <x-filament::input.checkbox
                                x-model="item.is_active"
                                x-on:change="syncState()"
                            />
                            Activo
                        </label>
                    </div>
                </li>
            </template>
        </ul>

        <button
            type="button"
            x-on:click="addItem()"
            class="mt-3 inline-flex items-center gap-1 rounded-lg border border-dashed border-gray-300 px-3 py-2 text-sm font-medium text-gray-600 hover:border-gray-400 hover:text-gray-800 dark:border-gray-600 dark:text-gray-300 dark:hover:border-gray-500"
        >
            + Añadir elemento
        </button>

        <p x-show="items.length === 0" class="mt-2 text-sm text-gray-400">Todavía no hay elementos en este menú.</p>
    </div>
</x-dynamic-component>
