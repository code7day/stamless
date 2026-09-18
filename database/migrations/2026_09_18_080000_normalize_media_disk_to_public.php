<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migración de DATOS (no de esquema) — normaliza `media.disk` a `'public'`
 * para TODOS los registros ya guardados, sin importar tenant.
 *
 * Contexto (mismo día, mismo hilo de investigación que el fix de
 * `ImageColumn` en `ServiceResource`/`TestimonialResource`/`PostResource`/
 * `MediaResource`): `config/filesystems.php` cambió para que el disco
 * `'public'` sea el ÚNICO nombre de disco que usa la app para media,
 * resolviendo local vs R2 puertas adentro según `FILESYSTEM_DISK` — antes,
 * `MediaUpload::diskName()`/`MediaResource::form()` escribían `'public'` en
 * desarrollo pero `'r2'` en producción (ternariando
 * `config('filesystems.default')`).
 *
 * Por qué importa igualar el VALOR ya guardado, no solo el código nuevo:
 * `Filament\Tables\Columns\ImageColumn::getVisibility()` infiere la
 * visibilidad comparando el nombre de disco contra el string literal
 * `'public'` — un registro `Media` con `disk = 'r2'` (todo lo subido en
 * producción ANTES de este fix) seguiría siendo tratado como privado por
 * Filament aunque el disco `'r2'` en sí sea público, obligando a mantener
 * `->visibility('public')` explícito en cada columna como parche permanente.
 * Reescribir el valor guardado a `'public'` (el disco físico detrás no
 * cambia — sigue siendo el mismo bucket R2 real) es lo que permite sacar
 * esos overrides de una vez.
 *
 * UPDATE directo (no Eloquent): es una corrección de un solo campo interno,
 * sin lógica de negocio ni necesidad de disparar `DeployTriggerObserver`
 * (dato legado, no contenido nuevo) — más simple y más rápido que hidratar
 * cada modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('media')
            ->whereIn('disk', ['r2', 'local', 's3'])
            ->update(['disk' => 'public']);
    }

    /**
     * No reversible de forma significativa — varios valores de origen
     * (`r2`, `local`, `s3`) colapsan al mismo `'public'`, no hay un único
     * "estado anterior" real al que volver sin haberlo registrado antes de
     * correr `up()`.
     */
    public function down(): void
    {
        // Intencionalmente vacío — ver docblock de la clase.
    }
};
