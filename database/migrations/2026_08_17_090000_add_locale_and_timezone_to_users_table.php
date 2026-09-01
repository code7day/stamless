<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preferencias de visualización por usuario admin: en qué idioma y en
     * qué zona horaria quiere ver Console — no afecta `lang_iso` del
     * contenido (eso es tenant-aware, ver LanguageEnum), esto es
     * exclusivamente cómo se le muestran fechas/textos de UI a ESTE
     * usuario. Ver `App\Support\FriendlyDate` y
     * `App\Filament\Pages\Preferences` (ADR-021).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->default('es')->after('password');
            $table->string('timezone', 64)->default('America/Lima')->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['locale', 'timezone']);
        });
    }
};
