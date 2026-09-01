<?php

use App\Enums\CountryEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Data-fix (2026-08-31, extiende ADR-035): `CountryEnum` pasó de 6 casos
     * en minúscula (`ar`/`uy`/`py`/`ec`/`us`/`global`) al listado ISO 3166-1
     * completo con códigos en MAYÚSCULA. Las filas de `services` sembradas
     * ANTES de ese cambio (`Cliente0ServicesSeeder`, ADR-034) quedaron con
     * códigos viejos que el Enum nuevo ya no reconoce — al abrir esa fila en
     * el `Select` de `ServiceResource`, Filament rompe con un `TypeError` en
     * `CanDisableOptions::isOptionDisabled()` porque el valor guardado no
     * existe entre las `options()` actuales (ver PROGRESS.md, reporte real
     * del Tech Lead sobre un 500 en producción).
     *
     * Recorre `services` fila por fila con el query builder (`DB::table`,
     * NO Eloquent) a propósito: `Service` usa `HasTenant`, que agrega un
     * global scope que necesita un tenant resuelto en el contexto — una
     * migración corre fuera de ese contexto y rompería. Se normaliza cada
     * código a mayúscula y se descarta cualquiera que no exista hoy en
     * `CountryEnum` (dato corrupto/legado), usando la MISMA regla que
     * `Service::sanitizeCountries()` para no duplicar el criterio.
     */
    public function up(): void
    {
        DB::table('services')
            ->select('id', 'countries')
            ->whereNotNull('countries')
            ->orderBy('id')
            ->get()
            ->each(function (object $row): void {
                $raw = json_decode($row->countries ?? '[]', true) ?: [];

                $normalized = collect($raw)
                    ->map(fn ($code) => strtoupper((string) $code))
                    ->filter(fn (string $code) => CountryEnum::tryFrom($code) !== null)
                    ->unique()
                    ->values()
                    ->all();

                if ($normalized === $raw) {
                    return;
                }

                DB::table('services')
                    ->where('id', $row->id)
                    ->update(['countries' => json_encode($normalized)]);
            });
    }

    /**
     * Reverse the migrations.
     *
     * Irreversible a propósito: no hay forma de recuperar los códigos en
     * minúscula descartados (y no tendría sentido volver a ellos — el Enum
     * ya no los reconoce).
     */
    public function down(): void
    {
        // Intencional: ver docblock de up().
    }
};
