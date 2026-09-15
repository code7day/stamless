<?php

use App\Services\SettingService;

if (! function_exists('setting')) {
    /**
     * Get / set settings.
     *
     * @param  string|array|null  $key
     * @param  mixed  $default
     * @return mixed|SettingService
     */
    function setting($key = null, $default = null)
    {
        if (is_null($key)) {
            return app(SettingService::class);
        }

        if (is_array($key)) {
            app(SettingService::class)->setMany($key);

            return null;
        }

        return app(SettingService::class)->get($key, $default);
    }
}
