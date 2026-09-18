<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * SMTP propio por tenant, opcional (2026-09-18, pedido del Tech Lead:
     * "falta la sección de configuración para ingresar los datos para
     * configurar su SMTP favorito y será mejor para evitar usar mi smtp
     * general para todo"). Mismo criterio que `deploy_repo`/`deploy_token`
     * (ver `2026_09_17_162311_add_deploy_webhook_fields_to_tenants_table`):
     * TODAS estas columnas son nullable — `null` en `smtp_host` desactiva
     * el mecanismo para ese tenant, y `ContactSubmissionService`/los
     * Mailables caen al `MAIL_MAILER` global de `.env` sin cambio de
     * comportamiento. Es opt-in, no un requisito para poder usar
     * formularios.
     *
     * A diferencia de `deploy_repo`/`deploy_token` (solo seteables por el
     * operador de la plataforma vía tinker), esto SÍ se expone en una
     * sección propia de `Preferences.php` — el Tech Lead pidió
     * explícitamente que el tenant pueda auto-gestionarlo, no que dependa
     * de un ticket a soporte.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('smtp_host')->nullable()->after('deploy_token');
            $table->unsignedSmallInteger('smtp_port')->nullable()->after('smtp_host');
            $table->string('smtp_username')->nullable()->after('smtp_port');

            // Cifrado at-rest con el cast `encrypted` (ver `Tenant::casts()`)
            // — mismo criterio que `deploy_token`/`Contact::email` para
            // cualquier credencial/secreto que viva en esta tabla.
            $table->text('smtp_password')->nullable()->after('smtp_username');

            // 'tls' | 'ssl' | null (sin cifrado — puerto 25 típicamente).
            $table->string('smtp_encryption', 10)->nullable()->after('smtp_password');

            // "From" propio del tenant — si se deja vacío, se usa
            // `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME` global + nombre del
            // tenant como fallback (ver `Tenant::smtpMailerConfig()`).
            $table->string('smtp_from_address')->nullable()->after('smtp_encryption');
            $table->string('smtp_from_name')->nullable()->after('smtp_from_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'smtp_host',
                'smtp_port',
                'smtp_username',
                'smtp_password',
                'smtp_encryption',
                'smtp_from_address',
                'smtp_from_name',
            ]);
        });
    }
};
