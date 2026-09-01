<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Prepara la URL para la petición HTTP en los tests.
     * Si detecta una ruta de la API (/v1/...), le asigna el host correcto de la API
     * para que coincida con el Route::domain() sin tener que modificar cada test.
     */
    protected function prepareUrlForRequest($url)
    {
        if (str_starts_with($url, '/v1/')) {
            $apiHost = parse_url(config('stamless.urls.api', config('app.url')), PHP_URL_HOST);
            return 'https://' . $apiHost . $url;
        }

        return parent::prepareUrlForRequest($url);
    }
}
