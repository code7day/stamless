<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 2026-09-18, pedido del Tech Lead sobre el email de notificación del
     * admin: "el campo Email de notificación debería permitir enviar
     * varios o copia de notificación... con soporte a varios correos
     * separados por comas y se enviaría el primero y con copia a los
     * otros a continuación separados por comas" (ver
     * `ContactSubmissionService::notifyAdmin()`). `notification_email`
     * pasa de `string` (varchar 255) a `text`: unos pocos emails
     * separados por coma pueden superar cómodamente 255 caracteres, y no
     * hay razón real para poner un tope corto acá — la validación de
     * "cada segmento es un email válido" vive en `FormResource.php`, no en
     * el tamaño de la columna.
     *
     * `notification_intro` es nuevo: texto opcional para personalizar el
     * email de notificación al admin (pedido del mismo mensaje: "está
     * bueno personalizar algún texto o título necesario... para el envío
     * de mailing notificando al admin"), ocupando el espacio que dejó
     * libre `success_message` al sacarse de esta misma sección del form
     * (ver ADR-073, actualización 2026-09-18). `text`, no `string`: mismo
     * criterio que `success_message`, es contenido libre sin límite corto
     * con sentido de negocio.
     */
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->text('notification_email')->nullable()->change();
            $table->text('notification_intro')->nullable()->after('notification_subject');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropColumn('notification_intro');
            $table->string('notification_email')->nullable()->change();
        });
    }
};
