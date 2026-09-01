<x-filament-panels::page>
    {{-- Banner --}}
    <div class="gnss-banner">
        <div class="gnss-banner-badges">
            <span class="gnss-chip gnss-chip--accent">
                <x-heroicon-o-tag class="gnss-icon-sm" />
                v1
            </span>
            <span class="gnss-chip gnss-chip--muted">
                <x-heroicon-o-lock-closed class="gnss-icon-sm" />
                Bearer token (Sanctum)
            </span>
            <span class="gnss-chip gnss-chip--muted gnss-chip--mono">
                {{ config('stamless.urls.api') }}/v1/{tenant_slug}/...
            </span>
        </div>

        <a href="{{ \App\Filament\Pages\ApiPlayground::getUrl() }}" class="gnss-btn gnss-btn--accent">
            <x-heroicon-o-code-bracket-square class="gnss-icon-sm" />
            Abrir Playground
        </a>
    </div>

    <div
        x-data="{
            query: '',
            active: '',
            setActive(id) { this.active = id },
        }"
        x-init="
            const headings = $refs.content.querySelectorAll('h2[id], h3[id]');
            const observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) setActive(entry.target.id);
                });
            }, { rootMargin: '-10% 0px -70% 0px' });
            headings.forEach((h) => observer.observe(h));

            // Copiar código: se agrega un botón por cada <pre> del markdown.
            $refs.content.querySelectorAll('pre').forEach((pre) => {
                if (pre.dataset.copyReady) return;
                pre.dataset.copyReady = '1';

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = 'Copiar';
                btn.className = 'gnss-copy-btn';
                btn.addEventListener('click', () => {
                    navigator.clipboard.writeText(pre.innerText);
                    btn.textContent = '¡Copiado!';
                    btn.classList.add('is-copied');
                    setTimeout(() => { btn.textContent = 'Copiar'; btn.classList.remove('is-copied'); }, 1500);
                });
                pre.appendChild(btn);
            });
        "
        class="gnss-layout"
    >
        {{-- Sidebar: índice + búsqueda --}}
        <aside class="gnss-sticky">
            <div class="gnss-card gnss-card--tight">
                <div class="gnss-search-wrap">
                    <x-heroicon-o-magnifying-glass class="gnss-search-icon" />
                    <input
                        type="text"
                        x-model="query"
                        placeholder="Buscar en el índice..."
                        class="gnss-search-input"
                    />
                </div>

                <nav class="gnss-toc">
                    @foreach ($this->getTableOfContents() as $item)
                        <a
                            href="#{{ $item['id'] }}"
                            x-show="query === '' || {{ \Illuminate\Support\Js::from(\Illuminate\Support\Str::lower($item['text'])) }}.includes(query.toLowerCase())"
                            @class([
                                'gnss-toc-link',
                                'gnss-toc-link--h3' => $item['level'] === 3,
                            ])
                            :class="active === '{{ $item['id'] }}' ? 'is-active' : ''"
                        >
                            {{ $item['text'] }}
                        </a>
                    @endforeach
                </nav>
            </div>
        </aside>

        {{-- Contenido --}}
        <div x-ref="content" class="gnss-card">
            <div class="gnss-prose">
                {!! $this->getMarkdownHtml() !!}
            </div>
        </div>
    </div>

    {{--
        Syntax highlighting de los bloques de código (Prism.js, vía CDN —
        no hay build step de JS en este panel, ver api-console.css). Se
        cargan sin `defer`/`async` a propósito: como estos <script> están
        al final del body, para cuando el navegador los parsea el HTML del
        markdown ya existe en el DOM, así que corren sincrónicamente antes
        de que Alpine (que sí es `defer`) llegue a inicializar el `x-init`
        de más arriba — no hace falta ningún listener/orden adicional.
    --}}
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-core.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-clike.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-typescript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-json.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-bash.min.js"></script>
    <script>
        if (window.Prism) {
            // `jsonc` no es un lenguaje real de Prism — los fences ```jsonc
            // de v1.md son JSON con comentarios `//` de anotación. Se
            // extiende el grammar de `json` (ya cargado arriba) en vez de
            // traer un componente aparte que no existe en el CDN.
            if (Prism.languages.json && !Prism.languages.jsonc) {
                Prism.languages.jsonc = Prism.languages.extend('json', {
                    comment: { pattern: /\/\/.*/, greedy: true },
                });
            }

            Prism.highlightAllUnder(document);
        }
    </script>
</x-filament-panels::page>
