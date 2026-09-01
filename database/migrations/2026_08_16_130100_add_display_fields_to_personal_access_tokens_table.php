<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Sanctum solo guarda el hash del token — nunca el texto plano — así
     * que no hay forma de reconstruir después "los últimos caracteres"
     * para mostrarlos enmascarados en Console. Se agrega `last_four`
     * (los últimos 4 caracteres del token en texto plano, capturados una
     * sola vez al crearlo desde `App\Filament\Pages\ApiTokens`) para poder
     * mostrar algo como `••••••••3f9a` en el listado sin guardar el
     * secreto completo (ver ADR-018).
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('last_four', 4)->nullable()->after('token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('last_four');
        });
    }
};
