<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Segunda imagen de `Service` (2026-09-14, pedido del Tech Lead):
     * "no solo tenga una imagen si no dos, una normal como la que ya tiene y
     * otra nueva mas panoramica como para el detalle". El docblock original
     * de `create_services_table` (2026-08-31) ya dejaba esto anotado como
     * decisión reversible: "`image_id` (no `card_image_id`/`hero_image_id`
     * separados)... separar en 2 campos queda para cuando el Tech Lead
     * decida que catálogo y detalle necesitan crops distintos" — es ese
     * momento.
     *
     * `image_detail_id`, nullable, mismo patrón que `image_id` (FK a
     * `media`, `nullOnDelete()` — si se borra el Media, el servicio no se
     * cae, solo pierde la referencia). Opcional a propósito: el front
     * (`cica360/src/pages/servicios/[slug].astro`) usa `image_detail` para
     * el header del detalle y cae a `image` (la principal) si no está
     * cargada — la Sección "Imágenes del servicio" de `ServiceResource`
     * explica este fallback en su propio `helperText`, no hace falta
     * duplicar la regla acá. `image_id` NO se renombra (sigue siendo la
     * Principal: miniatura del catálogo + fallback del header) — cero
     * migración de datos necesaria para los servicios ya existentes.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('image_detail_id')
                ->nullable()
                ->after('image_id')
                ->constrained('media')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('image_detail_id');
        });
    }
};
