<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `properties` jsonb a nivel Slider (no Slide) — 2026-08-31, corrección
     * del Tech Lead: `show_scroll_indicator` no es una property por slide,
     * es una sola vez por Slider ("sobrepuesta al decorador, para todos los
     * slides detrás"). El Slider no tenía `properties` todavía (solo
     * title/slug/is_active) — se agrega siguiendo el mismo patrón jsonb que
     * el resto del esquema (Page/Block/Post/Slide), no una columna
     * dedicada, para poder crecer sin nueva migración si aparecen más
     * propiedades a nivel Slider más adelante.
     */
    public function up(): void
    {
        Schema::table('sliders', function (Blueprint $table) {
            $table->jsonb('properties')->nullable()->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sliders', function (Blueprint $table) {
            $table->dropColumn('properties');
        });
    }
};
