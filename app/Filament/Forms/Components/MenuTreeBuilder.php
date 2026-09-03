<?php

namespace App\Filament\Forms\Components;

use App\Enums\LinkTargetEnum;
use App\Enums\MenuItemTypeEnum;
use App\Models\Page;
use App\Models\Post;
use App\Models\Service;
use Filament\Forms\Components\Field;

/**
 * Árbol de items de menú con drag-and-drop real (reordenar + anidar hasta
 * `getMaxDepth()` niveles arrastrando horizontalmente, estilo editor
 * clásico de menús de WordPress), reemplazando los 3 `Repeater`s anidados
 * que tenía antes `MenuResource` (ver el comentario histórico que quedó
 * en `MenuResource::menuItemFields()` — el pedido anterior de "reordenar
 * con botones" resolvía el drag roto, pero el Tech Lead pidió después
 * "manejar el anidar/cambiar de padre similar a WordPress").
 *
 * 2026-09-02 — se evaluaron 4 plugins de Filament (ysfkaya,
 * solution-forest/filament-tree, notebrainslab/filament-menu-manager,
 * biostate/filament-menu-builder) y se descartaron todos: cerrado/pago,
 * exigen migrar `Menu`/`MenuItem` a un schema propio (nested-set), o
 * demasiado inmaduros para algo tan central a la navegación pública. Se
 * optó por construirlo acá reusando SortableJS, que Filament YA trae
 * vendorizado en su propio bundle (`window.Sortable`, lo mismo que usa
 * `Repeater`/`KeyValue` internamente vía la directiva `x-sortable`) —
 * cero dependencias nuevas, cero build step (el JS se registra como
 * asset estático, ver `PanelCmsProvider`).
 *
 * El estado del campo es un ARRAY PLANO con un `depth` (0..maxDepth-1)
 * por ítem — el ORDEN del array + la PROFUNDIDAD de cada uno codifican
 * el árbol completo (mismo principio que el editor de menús de
 * WordPress: "el padre de un item es el item más cercano ANTERIOR que
 * tenga profundidad - 1"). Aplanado/rearmado en árbol del lado de PHP
 * (`MenuResource::flattenMenuTree()`/`syncMenuTree()`), no acá — este
 * campo no sabe nada de `Menu`/`MenuItem` como modelos Eloquent, solo
 * mueve el array de ida y vuelta.
 */
class MenuTreeBuilder extends Field
{
    protected string $view = 'filament.forms.components.menu-tree-builder';

    /**
     * 3 niveles pedidos (menú / submenú / sub-submenú) → profundidades
     * válidas 0, 1, 2.
     */
    protected int $treeMaxDepth = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->default([]);
    }

    public function maxDepth(int $depth): static
    {
        $this->treeMaxDepth = $depth;

        return $this;
    }

    public function getMaxDepth(): int
    {
        return $this->treeMaxDepth;
    }

    /**
     * Opciones del Select "Página de destino" — mismo criterio que el
     * `reference_id` que reemplaza (`MenuResource`, antes de este
     * cambio): `->publiclyLinkable()` excluye Header/Footer (2026-09-02,
     * bug real en vivo: partials sin URL pública apareciendo como
     * destino de un ítem de menú).
     *
     * @return array<int, string>
     */
    public function getPageOptions(): array
    {
        return Page::query()->publiclyLinkable()->pluck('title', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    public function getPostOptions(): array
    {
        return Post::query()->pluck('title', 'id')->all();
    }

    /**
     * Solo servicios publicados (2026-09-02, ver ADR-044: "Servicio"
     * nuevo como tipo de enlace, ahora que tiene URL pública propia).
     *
     * @return array<int, string>
     */
    public function getServiceOptions(): array
    {
        return Service::query()->published()->pluck('title', 'id')->all();
    }

    /**
     * @return array<string, string>
     */
    public function getTypeOptions(): array
    {
        return collect(MenuItemTypeEnum::cases())
            ->mapWithKeys(fn (MenuItemTypeEnum $case) => [$case->value => $case->getLabel()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function getTargetOptions(): array
    {
        return collect(LinkTargetEnum::cases())
            ->mapWithKeys(fn (LinkTargetEnum $case) => [$case->value => $case->getLabel()])
            ->all();
    }
}
