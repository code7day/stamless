<?php

namespace App\Models;

use App\Enums\CountryEnum;
use App\Enums\LanguageEnum;
use App\Enums\PublishStatusEnum;
use App\Traits\HasTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'uuid', 'lang_iso', 'pretitle', 'title', 'subtitle', 'slug',
    'status', 'image_id', 'countries', 'content', 'meta', 'links', 'properties',
    'sort_order', 'published_at',
])]
class Service extends Model
{
    use HasTenant, HasUuid;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'lang_iso' => LanguageEnum::class,
            'status' => PublishStatusEnum::class,
            'countries' => 'array',
            'content' => 'array',
            'meta' => 'array',
            'links' => 'array',
            'properties' => 'array',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_id');
    }

    /**
     * `countries` saneado: mayúscula + descarta cualquier código que no
     * exista hoy en `CountryEnum` (dato viejo/corrupto). Usado por
     * `ServiceResource` (hidratación y guardado del `Select`) y por
     * `countriesResolved()` — centralizado acá para no repetir la regla en
     * los dos lugares. A propósito NO es un `Attribute` mutator (`set:`)
     * sobre este mismo campo: mezclar un `Attribute` con el cast `'array'`
     * ya existente en `casts()` para la MISMA key es una combinación de
     * Eloquent con semántica poco clara sobre cuándo corre el cast — más
     * seguro sanear explícitamente en los puntos de entrada/salida.
     *
     * Fix real (2026-08-31): con `->options(CountryEnum::class)`, Filament
     * hidrata el `Select` con instancias de `CountryEnum` en vez de los
     * strings crudos guardados en la fila — `(string) $code` sobre un Enum
     * PHP (no implementa `__toString()`) tira `Error: Object ... could not
     * be converted to string`. Este método ahora acepta los dos casos: una
     * instancia de `CountryEnum` (usa `->value` directo) o un valor
     * escalar/legado (se castea a string).
     *
     * @param  array<int, string|CountryEnum>|null  $codes
     * @return array<int, string>
     */
    public static function sanitizeCountries(?array $codes): array
    {
        return collect($codes ?? [])
            ->map(fn ($code) => strtoupper($code instanceof CountryEnum ? $code->value : (string) $code))
            ->filter(fn (string $code) => CountryEnum::tryFrom($code) !== null)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * `countries` resuelto a `[['iso' => ..., 'name' => ...], ...]` — la forma
     * que debe devolver la API pública (ver `CountryEnum::resolveMany()` y
     * ADR-035). El frontend arma la ruta de la banderita a partir de `iso`
     * (`media/flags/flag_{iso}.webp`).
     *
     * @return array<int, array{iso: string, name: string}>
     */
    public function countriesResolved(): array
    {
        return CountryEnum::resolveMany($this->countries ?? []);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PublishStatusEnum::Published->value);
    }

    public function scopeForLanguage(Builder $query, LanguageEnum $lang): Builder
    {
        return $query->where('lang_iso', $lang->value);
    }
}
