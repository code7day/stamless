<script>
    document.addEventListener('livewire:init', () => {
        if (typeof Livewire !== 'undefined' && typeof Livewire.hook === 'function') {
            Livewire.hook('request', ({ fail }) => {
                fail(({ status, preventDefault }) => {
                    // Manejo suave de expiración de sesión (419 CSRF/sesión expirada, 401 no autenticado, 404 contexto perdido)
                    if (status === 419 || status === 401 || status === 404) {
                        preventDefault();
                        console.warn(`[Stamless Session] Sesión expirada (HTTP ${status}). Redirigiendo/recargando para renovar token...`);
                        window.location.reload();
                    }
                });
            });
        }
    });
</script>
