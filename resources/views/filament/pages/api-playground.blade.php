<x-filament-panels::page>
    {{-- Banner --}}
    <div class="gnss-banner">
        <div class="gnss-banner-badges">
            <span class="gnss-chip gnss-chip--accent">
                <x-heroicon-o-bolt class="gnss-icon-sm" />
                Requests reales, no un mock
            </span>
            <span class="gnss-chip gnss-chip--muted gnss-chip--mono">
                {{ config('stamless.urls.api') }}/v1/{tenant_slug}/...
            </span>
        </div>
    </div>

    <div class="gnss-layout">
        {{-- Sidebar de endpoints --}}
        <aside class="gnss-sticky">
            <div class="gnss-card gnss-card--tight">
                <p class="gnss-sidebar-label">Endpoints de ejemplo</p>

                @foreach ($this->groupedPresets() as $group => $items)
                    <div class="gnss-sidebar-group">
                        <p class="gnss-sidebar-label">{{ $group }}</p>

                        @foreach ($items as $key => $preset)
                            <button
                                type="button"
                                wire:click="selectPreset('{{ $key }}')"
                                @class(['gnss-endpoint-btn', 'is-active' => $activePreset === $key])
                            >
                                <span class="gnss-method gnss-method--{{ strtolower($preset['method']) }}">
                                    {{ $preset['method'] }}
                                </span>
                                <span class="gnss-endpoint-label">{{ $preset['label'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endforeach

                <div class="gnss-info-card">
                    <p class="gnss-info-card-title">
                        <x-heroicon-o-signal class="gnss-icon-sm" />
                        Destino real
                    </p>
                    <code>{{ config('stamless.urls.api') }}</code>
                </div>
            </div>
        </aside>

        {{-- Panel principal --}}
        <div class="gnss-stack">
            <div class="gnss-card">
                <h2 class="gnss-section-title">
                    <x-heroicon-o-paper-airplane class="gnss-icon gnss-icon-muted" />
                    Request
                </h2>
                {{ $this->form }}
            </div>

            <div class="gnss-card">
                <div style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:.5rem; margin-bottom:.75rem;">
                    <h2 class="gnss-section-title" style="margin-bottom:0;">
                        <x-heroicon-o-inbox-arrow-down class="gnss-icon gnss-icon-muted" />
                        Response
                    </h2>

                    @if ($responseStatus !== null || $responseError !== null)
                        <div style="display:flex; align-items:center; gap:.75rem;">
                            @if ($responseStatus !== null)
                                <span
                                    @class([
                                        'gnss-status',
                                        'gnss-status--ok' => $responseStatus < 300,
                                        'gnss-status--redirect' => $responseStatus >= 300 && $responseStatus < 400,
                                        'gnss-status--error' => $responseStatus >= 400,
                                    ])
                                >
                                    @if ($responseStatus < 300)
                                        <x-heroicon-o-check-circle class="gnss-icon-sm" />
                                    @else
                                        <x-heroicon-o-x-circle class="gnss-icon-sm" />
                                    @endif
                                    {{ $responseStatus }}
                                </span>
                            @endif

                            @if ($responseDurationMs !== null)
                                <span class="gnss-duration">
                                    <x-heroicon-o-clock class="gnss-icon-sm" />
                                    {{ $responseDurationMs }} ms
                                </span>
                            @endif
                        </div>
                    @endif
                </div>

                @if ($responseStatus === null && $responseError === null)
                    <div class="gnss-response-empty">
                        <x-heroicon-o-bolt class="gnss-icon-lg gnss-icon-muted" />
                        <p>Elegí un ejemplo del costado, completá el token y apretá <strong>Send</strong> arriba a la derecha.</p>
                    </div>
                @endif

                @if ($responseError)
                    <div class="gnss-alert">
                        <x-heroicon-o-exclamation-triangle class="gnss-icon-sm" />
                        {{ $responseError }}
                    </div>
                @endif

                @if ($responseBody)
                    <div class="gnss-tabs">
                        @foreach (['pretty' => 'Pretty', 'raw' => 'Raw', 'headers' => 'Headers ('.count($responseHeaders).')'] as $tab => $tabLabel)
                            <button
                                type="button"
                                wire:click="$set('responseTab', '{{ $tab }}')"
                                @class(['gnss-tab', 'is-active' => $responseTab === $tab])
                            >
                                {{ $tabLabel }}
                            </button>
                        @endforeach
                    </div>

                    @if ($responseTab === 'pretty')
                        <div x-data="{ copied: false }" class="gnss-code-block">
                            <pre x-ref="prettyBox"><code>{!! $responseBodyHighlighted !!}</code></pre>
                            <button
                                type="button"
                                x-on:click="navigator.clipboard.writeText($refs.prettyBox.innerText); copied = true; setTimeout(() => copied = false, 1500)"
                                :class="{ 'is-copied': copied }"
                                class="gnss-copy-btn"
                            >
                                <span x-show="!copied">Copiar</span>
                                <span x-show="copied" x-cloak>¡Copiado!</span>
                            </button>
                        </div>
                    @elseif ($responseTab === 'raw')
                        <div x-data="{ copied: false }" class="gnss-code-block">
                            <pre x-ref="rawBox">{{ $responseBody }}</pre>
                            <button
                                type="button"
                                x-on:click="navigator.clipboard.writeText($refs.rawBox.innerText); copied = true; setTimeout(() => copied = false, 1500)"
                                :class="{ 'is-copied': copied }"
                                class="gnss-copy-btn"
                            >
                                <span x-show="!copied">Copiar</span>
                                <span x-show="copied" x-cloak>¡Copiado!</span>
                            </button>
                        </div>
                    @else
                        <div class="gnss-headers-table">
                            @forelse ($responseHeaders as $header => $value)
                                <div class="gnss-headers-row">
                                    <span class="gnss-headers-key">{{ $header }}</span>
                                    <span class="gnss-headers-value">{{ $value }}</span>
                                </div>
                            @empty
                                <p class="gnss-headers-empty">Sin headers.</p>
                            @endforelse
                        </div>
                    @endif
                @endif

                @if ($lastCurl)
                    <div style="margin-top:1rem;">
                        <p class="gnss-sidebar-label" style="margin-bottom:.4rem;">cURL equivalente</p>
                        <div x-data="{ curl: @js($lastCurl), copied: false }" class="gnss-code-block">
                            <pre x-ref="curlBox">{{ $lastCurl }}</pre>
                            <button
                                type="button"
                                x-on:click="navigator.clipboard.writeText(curl); copied = true; setTimeout(() => copied = false, 1500)"
                                :class="{ 'is-copied': copied }"
                                class="gnss-copy-btn"
                            >
                                <span x-show="!copied">Copiar cURL</span>
                                <span x-show="copied" x-cloak>¡Copiado!</span>
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
