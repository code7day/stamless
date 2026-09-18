<?php

use App\Models\Block;
use App\Models\Form;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Fase 2 del plan de formularios (ADR-073) — `PageResource.php` cambió
     * el `Select` del bloque `contact_form` de guardar el `id` interno del
     * `Form` (`content.form_id`, numérico) a guardar su `slug`
     * (`content.form_slug`, string): el API público resuelve formularios
     * por slug (`GET /forms/{slug}`, igual que Páginas/Posts/Servicios),
     * nunca por id interno — con el `id` guardado, cica360 no tenía forma
     * de pedirle el formulario elegido al API (el gap real detrás de "el
     * front sigue mostrando el formulario hardcodeado, no lee
     * `content.form_id`", reportado en vivo por el Tech Lead).
     *
     * Cualquier bloque `contact_form` YA guardado en producción (mínimo el
     * de la página "Contacto" de CICA360, sembrado por
     * `Cliente0ContentSeeder`) quedó con la clave vieja — esta migración de
     * DATOS (no de esquema) los reescribe uno por uno: lee `content.form_id`,
     * busca el `Form` correspondiente (con `withoutGlobalScopes()`, esto
     * corre fuera de cualquier contexto de tenant/request), y si existe
     * reemplaza la clave por `content.form_slug` con su `slug` real. Si el
     * `Form` referenciado ya no existe (borrado desde entonces), la clave
     * vieja simplemente se elimina — el bloque queda sin formulario elegido,
     * mismo estado que un bloque `contact_form` recién agregado sin
     * completar (el campo es `->required()` en Filament, así que un editor
     * lo va a notar la próxima vez que abra ese bloque).
     */
    public function up(): void
    {
        Block::withoutGlobalScopes()
            ->where('type', 'contact_form')
            ->get()
            ->each(function (Block $block): void {
                $content = $block->content ?? [];

                if (! array_key_exists('form_id', $content)) {
                    return;
                }

                $formId = $content['form_id'];
                unset($content['form_id']);

                $slug = Form::withoutGlobalScopes()->where('id', $formId)->value('slug');

                if ($slug !== null) {
                    $content['form_slug'] = $slug;
                }

                $block->content = $content;
                $block->saveQuietly();
            });
    }

    /**
     * Reverse the migrations.
     *
     * Simétrico al `up()`: vuelve a guardar el `id` interno del `Form` a
     * partir de su `slug`, por si hiciera falta revertir el deploy de
     * `PageResource.php` que empezó a esperar `content.form_slug`.
     */
    public function down(): void
    {
        Block::withoutGlobalScopes()
            ->where('type', 'contact_form')
            ->get()
            ->each(function (Block $block): void {
                $content = $block->content ?? [];

                if (! array_key_exists('form_slug', $content)) {
                    return;
                }

                $slug = $content['form_slug'];
                unset($content['form_slug']);

                $formId = Form::withoutGlobalScopes()->where('slug', $slug)->value('id');

                if ($formId !== null) {
                    $content['form_id'] = $formId;
                }

                $block->content = $content;
                $block->saveQuietly();
            });
    }
};
