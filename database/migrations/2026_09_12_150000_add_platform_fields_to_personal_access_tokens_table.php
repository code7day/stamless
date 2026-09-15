<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 2026-09-12, pedido del Tech Lead sobre la protección de origen del
     * API: "la proteccion es para stamless... por eso tambien un select si
     * es una web o es app desde donde se usará el api, si es app mobile ya
     * no se valida... pero desde desktop via web creo que si porque el
     * cliente estara alojado en un server identificado por un dominio".
     *
     * `platform` — declarado al crear el token en `App\Filament\Pages\
     * ApiTokens` ('web'/'app', ver `App\Enums\ApiTokenPlatformEnum`).
     * `null` (tokens creados antes de esta feature) = sin restricción,
     * comportamiento idéntico a hoy.
     *
     * `allowed_origin` — el dominio (host, sin esquema) desde el que se
     * puede usar ESTE token puntual, solo tiene sentido cuando
     * `platform = 'web'`. `App\Http\Middleware\ValidateTokenOrigin` es
     * quien la aplica, sobre TODAS las rutas `v1/{tenant_slug}` (no solo
     * un endpoint puntual) — ver ADR-059.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('platform', 10)->nullable()->after('abilities');
            $table->string('allowed_origin')->nullable()->after('platform');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['platform', 'allowed_origin']);
        });
    }
};
