<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Página de Agradecimiento, Fase 1 → por Form (2026-09-18, pedido del
     * Tech Lead: "trasladar el contenido de la página gracias que sea
     * dinámica... hay que trasladarlo ahora al formulario para personalizar
     * los textos ahí mismo"). Hasta ahora vivía como `Setting` tenant-wide
     * (`thank_you.*`, editable en `Preferences.php`) — tenía sentido
     * mientras solo existía UN formulario real por tenant, pero deja de
     * tenerlo ahora que un tenant puede tener varios `Form` (Fase 1, ADR-073):
     * un valor tenant-wide no puede dar una página de gracias distinta por
     * formulario. Ver ADR-074.
     *
     * `thank_you_description` es `jsonb` (no `text`, a diferencia de
     * `Setting.value`): el `RichEditor` de Filament 5 guarda su estado como
     * un documento JSON (no HTML plano) — `Setting.value` (columna `text`
     * sin cast) nunca tuvo un lugar correcto donde guardar ese array, un
     * problema que no se hereda acá al usar `jsonb` + cast `array` en
     * `Form` (ver `Form::casts()`).
     */
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->string('thank_you_title')->nullable();
            $table->jsonb('thank_you_description')->nullable();
            $table->string('thank_you_alert_title')->nullable();
            $table->string('thank_you_alert_description')->nullable();
            $table->string('thank_you_button_label')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropColumn([
                'thank_you_title',
                'thank_you_description',
                'thank_you_alert_title',
                'thank_you_alert_description',
                'thank_you_button_label',
            ]);
        });
    }
};
