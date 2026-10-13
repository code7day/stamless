<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleFromHeader
{
    /**
     * Locales admitidos en la plataforma.
     *
     * @var list<string>
     */
    protected const SUPPORTED_LOCALES = ['es', 'en', 'pt-br', 'pt_BR'];

    /**
     * Mapeo de prefijos o variantes ISO al locale normalizado.
     *
     * @var array<string, string>
     */
    protected const LOCALE_ALIASES = [
        'es' => 'es',
        'es-es' => 'es',
        'es-419' => 'es',
        'es-uy' => 'es',
        'es-ar' => 'es',
        'es-mx' => 'es',
        'en' => 'en',
        'en-us' => 'en',
        'en-gb' => 'en',
        'pt' => 'pt-br',
        'pt-br' => 'pt-br',
        'pt_br' => 'pt-br',
        'pt-pt' => 'pt-br',
    ];

    /**
     * Determina el locale a partir de encabezados o parámetros de la solicitud y lo aplica a la aplicación.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request) ?? config('app.locale', 'en');

        app()->setLocale($locale);

        $response = $next($request);

        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    /**
     * Resuelve el idioma deseado evaluando cabeceras y parámetros con orden de prioridad.
     */
    public function resolveLocale(Request $request): ?string
    {
        // 1. Cabecera explícita X-Locale o X-Language
        $customHeader = $request->header('X-Locale') ?? $request->header('X-Language');
        if (is_string($customHeader) && ($resolved = $this->normalizeLocale($customHeader)) !== null) {
            return $resolved;
        }

        // 2. Parámetro query ?lang= o ?locale=
        $queryParam = $request->query('lang') ?? $request->query('locale');
        if (is_string($queryParam) && ($resolved = $this->normalizeLocale($queryParam)) !== null) {
            return $resolved;
        }

        // 3. Encabezado estándar HTTP Accept-Language
        $acceptLanguage = $request->header('Accept-Language');
        if (is_string($acceptLanguage) && $acceptLanguage !== '') {
            $parsed = $this->parseAcceptLanguage($acceptLanguage);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Parsea la cabecera HTTP Accept-Language respetando factores de ponderación (q-factor).
     */
    protected function parseAcceptLanguage(string $header): ?string
    {
        $locales = [];

        foreach (explode(',', $header) as $part) {
            $segments = explode(';', trim($part));
            $code = trim($segments[0]);
            $q = 1.0;

            if (isset($segments[1]) && preg_match('/q=([0-9.]+)/', $segments[1], $matches)) {
                $q = (float) $matches[1];
            }

            if ($code !== '') {
                $locales[$code] = $q;
            }
        }

        arsort($locales);

        foreach (array_keys($locales) as $candidate) {
            $normalized = $this->normalizeLocale($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Normaliza cualquier código de idioma o variante a un locale soportado.
     */
    protected function normalizeLocale(string $raw): ?string
    {
        $clean = strtolower(trim(str_replace('_', '-', $raw)));

        if (isset(self::LOCALE_ALIASES[$clean])) {
            return self::LOCALE_ALIASES[$clean];
        }

        $prefix = explode('-', $clean)[0];
        if (isset(self::LOCALE_ALIASES[$prefix])) {
            return self::LOCALE_ALIASES[$prefix];
        }

        return null;
    }
}
