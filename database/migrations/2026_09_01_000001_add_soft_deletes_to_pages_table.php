<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Soft delete para `pages` (2026-09-01, pedido del Tech Lead: "al ser
 * borrados deberán ser soft-delete, permitiendo diferenciar si es el mismo
 * slug pero uno es soft-delete no cuenta como existente y permitiría
 * generarse con el mismo slug. Solo no deberían duplicarse con contenido
 * activo y no borrado").
 *
 * El unique compuesto original (`tenant_id`, `lang_iso`, `slug`) bloquearía
 * crear una página nueva con el mismo slug que una ya papelereada, aunque
 * Eloquent ya no la vea (el global scope de `SoftDeletes` la excluye de
 * cualquier query normal, incluida la validación `->unique()` de
 * `HeadingFieldset::make()`) — a nivel de base de datos el constraint
 * seguiría viéndolas como duplicadas. Se reemplaza por un **índice único
 * parcial** (`WHERE deleted_at IS NULL`), soportado tanto por PostgreSQL
 * (motor real) como por SQLite (motor de tests, ver CURRENT_STATE.md
 * "compatibilidad total SQLite/Postgres") — mismo `DB::statement()` sirve
 * para ambos, sin ramificar por driver.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->dropUnique('pages_tenant_id_lang_iso_slug_unique');
        });

        DB::statement(
            'CREATE UNIQUE INDEX pages_tenant_lang_slug_active_unique ON pages (tenant_id, lang_iso, slug) WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pages_tenant_lang_slug_active_unique');

        Schema::table('pages', function (Blueprint $table) {
            $table->unique(['tenant_id', 'lang_iso', 'slug'], 'pages_tenant_id_lang_iso_slug_unique');
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
