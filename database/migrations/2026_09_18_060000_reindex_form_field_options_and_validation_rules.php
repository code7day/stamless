<?php

use App\Models\FormField;
use Illuminate\Database\Migrations\Migration;

/**
 * Migración de DATOS (no de esquema) — corrige `FormField` ya guardados
 * ANTES del fix real en `FormResource::reindexRepeaterState()` (mismo día).
 *
 * Causa raíz (ver docblock de `reindexRepeaterState()` en
 * `FormResource.php`, mismo bug ya resuelto para `colophon` en ADR-071):
 * `options`/`validation_rules` se guardaban con las keys crudas de
 * Livewire (string tipo UUID) en vez de reindexarse a 0..n-1 antes de
 * persistir — un array PHP con keys no-secuenciales se serializa a JSON
 * como OBJETO, no como ARRAY. El API público entonces devolvía
 * `field.options` como `{"93f2...": {...}, ...}` en vez de
 * `[{...}, ...]`, y `ContactForm.tsx` (cica360) reventaba con
 * `TypeError: (field.options ?? []).map is not a function` al intentar
 * iterarlo como si fuera un array — reportado en vivo por el Tech Lead
 * sobre el campo "País" del form "Contacto" de CICA360.
 *
 * Reindexa con `array_values()` cualquier `options`/`validation_rules` que
 * NO sea ya una lista secuencial (`array_is_list()`, PHP 8.1+) — no toca
 * los que ya están bien. `saveQuietly()` para no disparar
 * `DeployTriggerObserver` por cada fila corregida (dato legado, no un
 * cambio de contenido real).
 */
return new class extends Migration
{
    public function up(): void
    {
        FormField::withoutGlobalScopes()
            ->get()
            ->each(function (FormField $field): void {
                $dirty = false;

                if (is_array($field->options) && ! array_is_list($field->options)) {
                    $field->options = array_values($field->options);
                    $dirty = true;
                }

                if (is_array($field->validation_rules) && ! array_is_list($field->validation_rules)) {
                    $field->validation_rules = array_values($field->validation_rules);
                    $dirty = true;
                }

                if ($dirty) {
                    $field->saveQuietly();
                }
            });
    }

    /**
     * No reversible de forma significativa — reindexar una lista no pierde
     * ningún dato (mismos valores, mismo orden), así que no hay un "estado
     * anterior" real al que volver.
     */
    public function down(): void
    {
        // Intencionalmente vacío — ver docblock de la clase.
    }
};
