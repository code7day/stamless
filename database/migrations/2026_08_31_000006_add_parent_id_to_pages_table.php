<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Árbol de PÁGINAS hasta 3 niveles (2026-08-31, pedido del Tech Lead:
     * "armar árbol tree de navegación... cuando una página tenga parent a
     * otra página"), confirmado con el Tech Lead como ORGANIZACIÓN interna
     * de contenido en Studio — no cambia la URL pública de cada página, que
     * sigue siendo su `slug` plano (`/mi-slug`), sin importar su nivel en
     * el árbol. Por eso NO hace falta tocar `ResolvesPublicLinks`, la API
     * pública, ni la unicidad de slug (`[tenant_id, lang_iso, slug]` sigue
     * intacta).
     *
     * `parent_id` autorreferenciado, mismo patrón que `menu_items.parent_id`
     * (ya probado en ese módulo). `nullOnDelete()`: si se borra la página
     * padre, los hijos NO se borran en cascada — pasan a ser de primer
     * nivel (huérfanos "seguros"), evita perder contenido por accidente.
     * El máximo de 3 niveles se hace cumplir a nivel de aplicación (Filament
     * `PageResource`, filtrando qué páginas son elegibles como padre), no
     * con un CHECK de Postgres — más simple y suficiente para un límite de
     * profundidad fijo y chico.
     */
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained('pages')
                ->nullOnDelete();

            $table->index(['tenant_id', 'parent_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
