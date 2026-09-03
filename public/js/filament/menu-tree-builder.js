/**
 * Alpine component para `App\Filament\Forms\Components\MenuTreeBuilder`
 * (ver esa clase para el porqué de construir esto en vez de un plugin).
 *
 * Usa `window.Sortable` — SortableJS, ya vendorizado dentro del bundle de
 * `filament/support` (lo mismo que usa `Repeater`/`KeyValue` internamente
 * vía su directiva `x-sortable`) — inicializado acá DIRECTO en vez de con
 * esa directiva, porque necesitamos control total de `onStart`/`onEnd`
 * (posición X del mouse al arrastrar) para calcular el cambio de
 * profundidad, algo que la directiva de Filament no expone.
 *
 * Registrado en `alpine:init` (patrón oficial de Alpine para componentes
 * custom vía `Alpine.data()`) — funciona sin importar si este script
 * carga antes o después del bundle de Alpine/Filament, mientras se
 * evalúe antes de que Alpine arranque.
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('menuTreeBuilder', ({
        state,
        maxDepth,
        pageOptions,
        postOptions,
        serviceOptions,
        typeOptions,
        targetOptions,
    }) => ({
        state,
        maxDepth,
        pageOptions,
        postOptions,
        serviceOptions,
        typeOptions,
        targetOptions,

        items: [],
        sortable: null,
        dragStartX: null,
        dragOriginalDepth: 0,
        nextKey: 1,
        // Ancho en px por nivel de indentación — también usado como el
        // umbral de arrastre horizontal para subir/bajar un nivel de
        // profundidad (arrastrar ~32px a la derecha = anidar un nivel).
        indentPx: 32,

        init() {
            this.hydrateFromState();

            this.$watch('state', (value) => {
                // Solo re-hidratar si el cambio vino de AFUERA de este
                // componente (ej. Livewire revalida el form tras un
                // error de validación) — comparar contra lo que nosotros
                // mismos acabamos de escribir evita un loop infinito
                // hidratar → escribir → hidratar.
                if (JSON.stringify(value) !== JSON.stringify(this.serializeItems())) {
                    this.hydrateFromState();
                }
            });

            this.$nextTick(() => this.initSortable());
        },

        hydrateFromState() {
            const source = Array.isArray(this.state) ? this.state : [];

            this.items = source.map((item) => ({
                key: this.nextKey++,
                id: item.id ?? null,
                depth: this.clampDepth(item.depth ?? 0),
                title: item.title ?? '',
                type: item.type ?? 'page',
                reference_id: item.reference_id ?? null,
                url: item.url ?? null,
                target: item.target ?? '_self',
                is_active: item.is_active ?? true,
                isOpen: false,
            }));
        },

        initSortable() {
            if (!this.$refs.list || typeof window.Sortable === 'undefined') {
                return;
            }

            this.sortable = window.Sortable.create(this.$refs.list, {
                handle: '[data-menu-tree-handle]',
                animation: 150,
                ghostClass: 'fi-sortable-ghost',
                onStart: (evt) => {
                    this.dragStartX = evt.originalEvent?.clientX ?? null;
                    this.dragOriginalDepth = this.items[evt.oldIndex]?.depth ?? 0;
                },
                onEnd: (evt) => this.handleDragEnd(evt),
            });
        },

        handleDragEnd(evt) {
            const { oldIndex, newIndex } = evt;

            if (oldIndex === null || newIndex === null || oldIndex === undefined || newIndex === undefined) {
                return;
            }

            // SortableJS ya movió el <li> real en el DOM. Alpine no sabe
            // nada de eso todavía — hay que reflejar el mismo movimiento
            // en el array reactivo y forzar un re-render completo de la
            // lista (vaciar + `$nextTick` + reponer) para que Alpine
            // vuelva a tomar control del DOM en vez de quedar
            // desincronizado con lo que SortableJS movió a mano. Mismo
            // truco que usa el componente Alpine nativo de `KeyValue`.
            const items = this.items.slice();
            const [moved] = items.splice(oldIndex, 1);
            items.splice(newIndex, 0, moved);

            const clientX = evt.originalEvent?.clientX ?? null;
            let newDepth = moved.depth;

            if (this.dragStartX !== null && clientX !== null) {
                const deltaX = clientX - this.dragStartX;
                const depthChange = Math.round(deltaX / this.indentPx);
                newDepth = this.dragOriginalDepth + depthChange;
            }

            const precedingDepth = newIndex > 0 ? items[newIndex - 1].depth : -1;
            moved.depth = this.clampDepth(Math.min(newDepth, precedingDepth + 1));

            this.normalizeDepths(items);
            this.dragStartX = null;

            this.replaceItems(items);
        },

        indent(index) {
            const items = this.items.slice();
            const item = items[index];
            const precedingDepth = index > 0 ? items[index - 1].depth : -1;
            item.depth = this.clampDepth(Math.min(item.depth + 1, precedingDepth + 1));
            this.normalizeDepths(items);
            this.replaceItems(items);
        },

        outdent(index) {
            const items = this.items.slice();
            items[index].depth = this.clampDepth(items[index].depth - 1);
            this.normalizeDepths(items);
            this.replaceItems(items);
        },

        canIndent(index) {
            if (index === 0) {
                return false;
            }

            const item = this.items[index];
            const precedingDepth = this.items[index - 1].depth;

            return item.depth < this.maxDepth - 1 && item.depth <= precedingDepth;
        },

        canOutdent(index) {
            return this.items[index].depth > 0;
        },

        /**
         * Garantiza que ningún item quede más de 1 nivel más profundo que
         * el item inmediatamente anterior — el mismo invariante que
         * mantiene válida la jerarquía completa sin importar qué
         * combinación de drag/indent/outdent/borrado haya pasado antes.
         * Auto-repara cualquier imprecisión del cálculo por posición del
         * mouse en `handleDragEnd()`.
         */
        normalizeDepths(items) {
            let previousDepth = -1;

            for (const item of items) {
                item.depth = this.clampDepth(Math.min(item.depth, previousDepth + 1));
                previousDepth = item.depth;
            }
        },

        clampDepth(depth) {
            return Math.max(0, Math.min(depth, this.maxDepth - 1));
        },

        addItem() {
            this.items.push({
                key: this.nextKey++,
                id: null,
                depth: 0,
                title: '',
                type: 'page',
                reference_id: null,
                url: null,
                target: '_self',
                is_active: true,
                isOpen: true,
            });
            this.syncState();
        },

        removeItem(index) {
            const items = this.items.slice();
            items.splice(index, 1);
            this.normalizeDepths(items);
            this.replaceItems(items);
        },

        toggleOpen(index) {
            this.items[index].isOpen = !this.items[index].isOpen;
        },

        onTypeChanged(index) {
            this.items[index].reference_id = null;
            this.items[index].url = null;
            this.syncState();
        },

        /**
         * Vacía y repone `items` en el siguiente tick — sin esto, Alpine
         * y SortableJS pelean por quién es dueño del orden real de los
         * nodos `<li>` en el DOM tras un drag (mismo patrón que
         * `reorderRows()` del componente nativo de `KeyValue`).
         */
        replaceItems(items) {
            this.items = [];
            this.$nextTick(() => {
                this.items = items;
                this.syncState();
            });
        },

        syncState() {
            this.state = this.serializeItems();
        },

        serializeItems() {
            return this.items.map((item) => ({
                id: item.id,
                depth: item.depth,
                title: item.title,
                type: item.type,
                reference_id: item.reference_id,
                url: item.url,
                target: item.target,
                is_active: item.is_active,
            }));
        },

        referenceOptionsFor(type) {
            if (type === 'page') {
                return this.pageOptions;
            }

            if (type === 'post') {
                return this.postOptions;
            }

            if (type === 'service') {
                return this.serviceOptions;
            }

            return {};
        },

        needsReference(type) {
            return type === 'page' || type === 'post' || type === 'service';
        },

        needsUrl(type) {
            return type === 'external' || type === 'custom';
        },

        referenceLabel(type) {
            if (type === 'page') {
                return 'Página de destino';
            }

            if (type === 'post') {
                return 'Entrada de blog de destino';
            }

            if (type === 'service') {
                return 'Servicio de destino';
            }

            return 'Referencia';
        },
    }));
});
