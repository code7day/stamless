<?php

namespace App\Filament\Concerns;

/**
 * 2026-09-13, pedido del Tech Lead: badges de uso en el sidebar ("Servicios
 * 7/10") y en las tabs de `ManagePages` ("Páginas 5") — para que el usuario
 * vea de un vistazo cuánto lleva ocupado de su plan sin tener que entrar a
 * cada sección. Formato y color quedan acá, compartidos por los ~7 recursos
 * que ya tienen su propio `Tenant::maxX()` (ver el resto de límites de plan
 * agregados esta sesión) — cada recurso sigue siendo dueño de SU consulta de
 * conteo (una sola responsabilidad por trait: dado un conteo y un límite,
 * decidir qué texto/color mostrar).
 */
trait FormatsUsageBadge
{
    /**
     * "{count}/{limit}" cuando el plan tiene tope; solo "{count}" cuando no
     * (`$limit === null` = plan sin límite para ese recurso, o un recurso
     * que directamente no tiene tope de cantidad — ej. `Menu`, que solo
     * limita items DENTRO de cada menú, no la cantidad de menús).
     */
    private static function formatUsageBadge(int $count, ?int $limit): string
    {
        return $limit !== null ? "{$count}/{$limit}" : (string) $count;
    }

    /**
     * Semáforo simple: gris mientras hay margen, ámbar a partir del 80% del
     * tope, rojo al llegar o pasar el límite — mismo criterio visual que ya
     * usan los `->tooltip()`/`->disabled()` de las acciones "Crear" en cada
     * recurso (avisar ANTES de que el botón se bloquee, no recién cuando ya
     * se bloqueó).
     */
    private static function usageBadgeColor(int $count, ?int $limit): string
    {
        if ($limit === null) {
            return 'gray';
        }

        if ($count >= $limit) {
            return 'danger';
        }

        if ($count >= (int) ceil($limit * 0.8)) {
            return 'warning';
        }

        return 'gray';
    }
}
