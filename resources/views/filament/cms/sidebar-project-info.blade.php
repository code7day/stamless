{{--
    2026-09-13, pedido del Tech Lead: reemplaza al selector de tenant
    (`<x-filament-panels::tenant-menu />`, apagado vía `->tenantMenu(false)`
    en `PanelCmsProvider`) en la MISMA posición visual del sidebar
    (`SIDEBAR_LOGO_AFTER`) — pero como bloque estático, sin dropdown ni
    flecha: solo se llega acá para tenants Free/Freemium (ver el
    `renderHook` en `PanelCmsProvider::panel()`), como único lugar donde
    ven su nombre de proyecto real (el brand de arriba muestra el genérico
    "Stamless Studio" para ese plan) más un recordatorio de su plan actual.
--}}
<div class="fi-project-info">
    <p class="fi-project-info-name text-gray-950 dark:text-white">{{ $projectName }}</p>
    <p class="fi-project-info-plan text-gray-500 dark:text-gray-400">Plan actual: {{ $planLabel }}</p>
</div>
