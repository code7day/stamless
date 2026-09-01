<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `short_hash` recibe un default calculado en Postgres para no requerir
     * doctrine/dbal (no instalado) al momento de agregar la columna sobre una
     * tabla con filas existentes. El trait/hook de creación en el modelo
     * `Tenant` además la asigna explícitamente en memoria (mismo patrón que
     * `HasUuid`), por lo que a nivel de aplicación siempre está poblada.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $defaultRaw = DB::connection()->getDriverName() === 'sqlite'
                ? ''
                : DB::raw("substr(md5(random()::text), 1, 12)");

            $table->string('short_hash', 12)->default($defaultRaw);
            $table->boolean('is_active')->default(true);
            $table->string('plan')->default('free');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->unique('short_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['short_hash']);
            $table->dropColumn(['short_hash', 'is_active', 'plan']);
        });
    }
};
