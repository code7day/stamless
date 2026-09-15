<?php

namespace App\Models;

use App\Enums\LanguageEnum;
use App\Enums\PageTypeEnum;
use App\Enums\PublishStatusEnum;
use App\Services\TenantManager;
use App\Traits\HasTenant;
use App\Traits\HasUuid;
use Filament\Facades\Filament;
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
     * Invariante "solo 1 página Home por tenant a la vez" (2026-09-13, bug
     * reportado por el Tech Lead: al EDITAR una página y prender el toggle
     * `is_home` desde el form, la página que antes era Home se quedaba
     * también marcada, resultando en 2 páginas con `is_home=true` — el
     * form (`HeadingFieldset::make(hasIsHome: true)`) solo seteaba el valor
     * en el registro que se estaba guardando, sin desactivar las demás; esa
     * lógica vivía SOLO en el ícono clickeable de la columna "Inicio" de
     * `PageResource::table()`, un segundo camino de guardado que la
     * duplicaba a mano y que quedó desincronizado del form. Centralizado
     * acá como hook de modelo para que valga sin importar el camino de
     * guardado (form de editar/crear, ícono de tabla, factories, API a
     * futuro, etc.) — un solo lugar de verdad en vez de 2 implementaciones
     * que pueden divergir. Scope por `tenant_id` únicamente (no por
     * `lang_iso`): "Home" es un concepto a nivel de sitio, no por idioma.
     */
    protected static function booted(): void
    {
        static::saving(function (Page $page): void {
            if (! $page->is_home) {
                return;
            }

            $tenantId = $page->tenant_id ?? app(TenantManager::class)->getTenantId() ?? Filament::getTenant()?->id;

            if (! $tenantId) {
                return;
            }

            static::where('tenant_id', $tenantId)
                ->when($page->exists, fn (Builder $query) => $query->where('id', '!=', $page->id))
                ->where('is_home', true)
                ->update(['is_home' => false]);
        });

        static::restoring(function (Page $page): void {
            $tenantId = $page->tenant_id ?? app(TenantManager::class)->getTenantId() ?? Filament::getTenant()?->id;
            $langIso = $page->lang_iso ?? 'es';

            $slugCollisionExists = static::query()
                ->where('tenant_id', $tenantId)
                ->where('lang_iso', $langIso)
                ->where('slug', $page->slug)
                ->where('id', '!=', $page->id)
                ->exists();

            if ($slugCollisionExists) {
                $page->slug = static::generateUniqueRestoredSlug($page);
            }
        });
    }

    /**
     * Genera un slug único para una página que está siendo restaurada de la papelera
     * si su slug original colisiona con un registro activo del mismo tenant.
     */
    public static function generateUniqueRestoredSlug(Page $page): string
    {
        $tenantId = $page->tenant_id ?? app(TenantManager::class)->getTenantId() ?? Filament::getTenant()?->id;
        $langIso = $page->lang_iso ?? 'es';
        $base = $page->slug.'-restaurado';
        $candidate = $base;
        $suffix = 2;

        while (static::query()
            ->where('tenant_id', $tenantId)
            ->where('lang_iso', $langIso)
            ->where('slug', $candidate)
            ->where('id', '!=', $page->id)
            ->exists()) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

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
     * bloques con "Destino: Página", etc.) — excluye `Footer`: es un
     * partial compartido sin URL pública propia (no se navega "a" un
     * footer), confirmado en vivo por el Tech Lead con una captura real
     * de "Footer principal" apareciendo como opción en "Página de
     * destino" de un ítem de menú. `Header` tenía el mismo tratamiento
     * hasta que se descartó por completo como tipo de contenido
     * (2026-09-11, ver ADR-053 — nunca tuvo un mecanismo real de consumo).
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
        return $query->where('type', '!=', PageTypeEnum::Footer->value);
    }
}
