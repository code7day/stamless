<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingService
{
    protected TenantManager $tenantManager;

    public function __construct(TenantManager $tenantManager)
    {
        $this->tenantManager = $tenantManager;
    }

    /**
     * Get a setting by key for the active tenant.
     */
    public function get(string $key, $default = null): mixed
    {
        $tenantId = $this->tenantManager->getTenantId();

        if (! $tenantId) {
            return $default;
        }

        // Cache all settings of this tenant to avoid database calls
        $settings = Cache::remember("tenant_{$tenantId}_settings", 3600, function () {
            // TenantScope will automatically scope this query to the current tenant
            return Setting::pluck('value', 'key')->toArray();
        });

        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    /**
     * Set a setting key and value for the active tenant.
     *
     * 2026-09-24: `get()` cachea TODOS los settings del tenant por 1h
     * (`Cache::remember`, ver arriba) — hasta esta vuelta, `set()` nunca
     * invalidaba esa entrada al escribir. Bug real reportado desde
     * Preferencias ("logo del proyecto no guardó" + "el queue necesita
     * reinicio para tomar el cambio"): el `UPDATE`/`INSERT` en la tabla
     * `settings` SÍ ocurría, pero cualquier lectura posterior de
     * `setting()` — en el mismo request, en otro worker de cola, da igual
     * — seguía devolviendo el array cacheado ANTES del guardado hasta que
     * expirara la hora. El caché vive en el store compartido (Redis/
     * archivo), no en memoria de un proceso puntual, así que ningún
     * reinicio de `queue:work`/forever lo solucionaba — solo esperar el
     * TTL o (ahora) este `Cache::forget()`.
     */
    public function set(string $key, $value, ?string $description = null): Setting
    {
        $tenantId = $this->tenantManager->getTenantId();

        if (! $tenantId) {
            throw new \Exception('Cannot set settings without an active tenant context.');
        }

        // Update or create within the tenant scope (automatically handled by HasTenant)
        $setting = Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'description' => $description]
        );

        $this->forgetCache($tenantId);

        return $setting;
    }

    /**
     * Set multiple settings at once.
     */
    public function setMany(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->set($key, $value);
        }
    }

    /**
     * Invalida el caché de settings del tenant — se llama desde `set()`
     * (y por lo tanto también desde `setMany()`, que lo delega por cada
     * clave). La próxima lectura de `get()` recalcula desde la base de
     * datos y vuelve a poblar el caché con el valor fresco.
     */
    private function forgetCache(int $tenantId): void
    {
        Cache::forget("tenant_{$tenantId}_settings");
    }
}
