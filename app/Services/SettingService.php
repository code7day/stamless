<?php

namespace App\Services;

use App\Models\Setting;
use App\Services\TenantManager;
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

        if (!$tenantId) {
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
     */
    public function set(string $key, $value, ?string $description = null): Setting
    {
        $tenantId = $this->tenantManager->getTenantId();

        if (!$tenantId) {
            throw new \Exception("Cannot set settings without an active tenant context.");
        }

        // Update or create within the tenant scope (automatically handled by HasTenant)
        return Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'description' => $description]
        );
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
}
