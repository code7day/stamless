<?php

namespace App\Models;

use App\Enums\LanguageEnum;
use App\Enums\PageTypeEnum;
use App\Enums\PublishStatusEnum;
use App\Traits\HasTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id', 'uuid', 'parent_id', 'lang_iso', 'pretitle', 'title',
    'subtitle', 'slug', 'type', 'is_home', 'status', 'meta', 'links',
    'properties', 'published_at',
])]
class Page extends Model
{
    use HasTenant, HasUuid;

    // Soft delete (2026-09-01, pedido del Tech Lead): un slug de una página
    // papelereada NO cuenta como "existente" para la unicidad por
    // tenant+lang+slug — permite recrear el mismo slug sin chocar — pero
    // sigue recuperable ("por si borró accidentalmente"). El global scope
    // que agrega este trait excluye automáticamente los registros
    // papelereados de CUALQUIER query normal (incluida la validación
    // `->unique()` de `HeadingFieldset::make()`), sin tocar nada más acá.
    // El índice único a nivel de base de datos que hace cumplir esto de
    // verdad (parcial, `WHERE deleted_at IS NULL`) vive en la migración
    // `2026_09_01_000001_add_soft_deletes_to_pages_table.php`. La UI de
    // papelera (filtro, restaurar, borrado permanente, vaciar papelera)
    // vive en `PageResource`.
    use SoftDeletes;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'lang_iso' => LanguageEnum::class,
            'type' => PageTypeEnum::class,
            'status' => PublishStatusEnum::class,
            'is_home' => 'boolean',
            'meta' => 'array',
            'links' => 'array',
            'properties' => 'array',
            'published_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Blocks ordenados de la página, listos para eager loading (`with('blocks')`).
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class)->orderBy('sort_order');
    }

    /**
     * Árbol de páginas hasta 3 niveles (2026-08-31 — organización interna
     * en Studio, NO afecta la URL pública de cada página, que sigue siendo
     * su `slug` plano). `parent()`/`children()` mismo patrón ya usado en
     * `MenuItem`.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('title');
    }

    /**
     * 0 = página de primer nivel, 1 = hija, 2 = nieta (el máximo permitido
     * — ver `PageResource::eligibleParentOptions()`). Sube por `parent`
     * como máximo 2 veces; el `$guard` es puramente defensivo por si algún
     * dato viejo/corrupto formara un ciclo (no debería poder pasar, la
     * elegibilidad de `parent_id` ya lo previene en el form).
     */
    public function depth(): int
    {
        $depth = 0;
        $current = $this;
        $guard = 0;

        while ($current->parent_id && $guard < 5) {
            $depth++;
            $current = $current->parent;
            $guard++;

            if (! $current) {
                break;
            }
        }

        return $depth;
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PublishStatusEnum::Published->value);
    }

    public function scopeOfType(Builder $query, PageTypeEnum $type): Builder
    {
        return $query->where('type', $type->value);
    }

    public function scopeForLanguage(Builder $query, LanguageEnum $lang): Builder
    {
        return $query->where('lang_iso', $lang->value);
    }

    /**
     * Páginas elegibles como DESTINO de un link/navegación (menús, CTAs,
     * bloques con "Destino: Página", etc.) — excluye `Header`/`Footer`:
     * son partials compartidos sin URL pública propia (no se navega
     * "a" un footer), confirmado en vivo por el Tech Lead con una
     * captura real de "Footer principal" apareciendo como opción en
     * "Página de destino" de un ítem de menú.
     *
     * 2026-09-02 — scope centralizado (no un filtro puntual por call
     * site) para que TODO selector de "página de destino" del proyecto
     * use el mismo criterio de una sola vez: `MenuResource`,
     * `LinkSchema::make()`/`makeSingle()` (compartido por ~10 bloques),
     * y el `page_id` de `services_grid` en `PageResource`. NO aplica al
     * Select de `content.footer_page_id` del bloque `footer` (ese
     * SÍ debe listar únicamente páginas `Footer`, es la única referencia
     * legítima a un partial) ni al `parent_id` de la jerarquía interna
     * de páginas (organización en Studio, no navegación pública).
     */
    public function scopePubliclyLinkable(Builder $query): Builder
    {
        return $query->whereNotIn('type', [
            PageTypeEnum::Header->value,
            PageTypeEnum::Footer->value,
        ]);
    }
}
