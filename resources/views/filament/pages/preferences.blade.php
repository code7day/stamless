{{--
    2026-09-13 (ADR-065): el `<div class="gnss-card" style="max-width: 32rem">`
    que envolvía TODO el form quedó de cuando esta página solo tenía 2 campos
    sueltos (idioma/zona horaria) — con las 2 Sections nuevas de SEO/OG
    (bastante más contenido, con imágenes) todo se veía apretado en una sola
    columna angosta. Se saca el wrapper: `Preferences::form()` ya arma su
    propio layout en tarjetas (`Section` "Cuenta" arriba, SEO/OG lado a lado
    en un `Grid` de 2 columnas) — mismo patrón visual que el resto de Studio.
--}}
<x-filament-panels::page>
    {{ $this->form }}
</x-filament-panels::page>
