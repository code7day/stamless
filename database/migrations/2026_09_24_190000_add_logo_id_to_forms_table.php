<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `logo_id`, nullable, mismo patrón que `Service::image_detail_id`
     * (FK a `media`, `nullOnDelete()`) — 2026-09-24, pedido del Tech Lead
     * tras el fix del caché de `Setting`: "cambiar de preferencias y poner
     * a configuración del formulario donde se pueda configurar el logo
     * que mostrará en el mailing". A diferencia de `Setting`
     * `branding.logo_id` (ADR-075, tenant-wide, vive en `Preferences.php`),
     * este campo es un OVERRIDE opcional por `Form` — si un formulario no
     * define el suyo, sigue usando el logo tenant-wide de Preferencias sin
     * ningún cambio de comportamiento (columna nullable, ningún dato
     * existente se toca). Pensado para tenants con más de un formulario
     * (ej. uno de contacto general y otro de una campaña puntual) que
     * quieran una marca distinta por mailing. Ver `resolveBrandLogoUrl()`
     * en `ContactSubmissionService`, que ahora prioriza este campo sobre
     * el `Setting` del tenant.
     */
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->foreignId('logo_id')
                ->nullable()
                ->after('notification_intro')
                ->constrained('media')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('logo_id');
        });
    }
};
