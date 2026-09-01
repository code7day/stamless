<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Módulo de Servicios (2026-08-31, pedido directo del Tech Lead con
     * capturas del catálogo y del detalle de "Seguros generales"): "similar
     * a páginas" — mismo esqueleto que `pages` (id/uuid/tenant/lang_iso/
     * pretitle/title/subtitle/slug/status/meta/links/properties/
     * published_at), pero con su propia tabla en vez de vivir como bloques
     * de una `Page`, porque cada servicio necesita: (1) una card de catálogo
     * (imagen + título + subtitulo + banderas de país + botón "Quiero
     * saber") y (2) su propia página de detalle (banner, intro, tabs "¿Qué
     * ofrecemos?"/"Coberturas", "¿Por qué elegirnos?", tip de ayuda) — forma
     * de contenido fija y reutilizada por los 9 servicios de Cliente 0, no
     * un `Builder` de bloques libres como `pages.blocks`. `image_id` (no
     * `card_image_id`/`hero_image_id` separados): mismo criterio "una sola
     * imagen real todavía" ya usado en el resto del proyecto — separar en 2
     * campos queda para cuando el Tech Lead decida que catálogo y detalle
     * necesitan crops distintos. `countries` jsonb (array de `CountryEnum`)
     * en vez de una tabla `countries`/pivote: catálogo estático chico, mismo
     * criterio que `lang_iso`.
     */
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('lang_iso', 5)->default('es');

            $table->string('pretitle')->nullable();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('slug');

            $table->string('status')->default('draft');
            $table->foreignId('image_id')->nullable()->constrained('media')->nullOnDelete();
            $table->jsonb('countries')->nullable();

            $table->jsonb('content')->nullable();
            $table->jsonb('meta')->nullable();
            $table->jsonb('links')->nullable();
            $table->jsonb('properties')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'lang_iso', 'slug']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
