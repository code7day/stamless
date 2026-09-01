<?php

namespace App\Services;

/**
 * Helpers de enmascarado de datos sensibles para previsualización (UI,
 * logs, exports parciales) sin necesidad de descifrar/exponer el valor
 * completo. No reemplaza el control de acceso: es solo presentación.
 * Ver `ContactPolicy` para la capa de autorización real.
 */
class DataMasker
{
    /**
     * "j***@example.com"
     */
    public static function email(?string $email): ?string
    {
        if (! $email) {
            return $email;
        }

        if (! str_contains($email, '@')) {
            return self::value($email);
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, 1);
        $maskedLocal = $visible.str_repeat('*', max(mb_strlen($local) - 1, 1));

        return "{$maskedLocal}@{$domain}";
    }

    /**
     * "*******1234" (conserva los últimos 4 dígitos).
     */
    public static function phone(?string $phone): ?string
    {
        if (! $phone) {
            return $phone;
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';
        $lastFour = mb_substr($digits, -4);

        return str_repeat('*', max(mb_strlen($digits) - 4, 0)).$lastFour;
    }

    /**
     * Enmascarado genérico: conserva los primeros `$visibleChars` caracteres.
     */
    public static function value(?string $value, int $visibleChars = 2): ?string
    {
        if (! $value) {
            return $value;
        }

        $length = mb_strlen($value);

        if ($length <= $visibleChars) {
            return str_repeat('*', $length);
        }

        return mb_substr($value, 0, $visibleChars).str_repeat('*', $length - $visibleChars);
    }
}
