<?php

if (!function_exists('setting')) {
    /**
     * Get / set settings.
     *
     * @param string|array|null $key
     * @param mixed $default
     * @return mixed|\App\Services\SettingService
     */
    function setting($key = null, $default = null)
    {
        if (is_null($key)) {
            return app(\App\Services\SettingService::class);
        }

        if (is_array($key)) {
            app(\App\Services\SettingService::class)->setMany($key);
            return null;
        }

        return app(\App\Services\SettingService::class)->get($key, $default);
    }
}
