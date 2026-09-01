<x-filament-panels::page>
    @if ($plainTextToken)
        <div x-data="{ token: @js($plainTextToken) }" class="gnss-token-reveal">
            <div style="flex:1;">
                <p class="gnss-token-reveal-title">Guardá este token ahora — no se va a volver a mostrar</p>
                <p class="gnss-token-reveal-body">Sanctum solo guarda el hash. Si lo perdés, tenés que revocarlo y crear uno nuevo.</p>
                <code class="gnss-token-value">{{ $plainTextToken }}</code>
            </div>
            <div class="gnss-token-actions">
                <button type="button" x-on:click="navigator.clipboard.writeText(token)" class="gnss-btn gnss-btn--sm gnss-btn--warning">
                    Copiar
                </button>
                <button type="button" wire:click="clearPlainTextToken" class="gnss-btn gnss-btn--sm gnss-btn--ghost">
                    Cerrar
                </button>
            </div>
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
