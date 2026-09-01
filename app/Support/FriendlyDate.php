<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Formateo de fechas "amigable" para TODOS los listados de Console — ver
 * ADR-021 (actualizado 2026-08-31, pedido explícito del Tech Lead: "en
 * todos los listados la fechas que sean amigables para humanos y si hay
 * que mostrar fecha que muestre d:m:Y h:i a"). Reglas:
 *
 *   - Respeta el idioma y la zona horaria configurados por el usuario
 *     autenticado (`App\Filament\Pages\Preferences` → `users.locale` /
 *     `users.timezone`), nunca UTC/servidor a secas.
 *   - Menos de 28 días de diferencia con "ahora" → relativo natural
 *     ("hace 5 min", "hace 2 días", localizado vía Carbon) — la parte
 *     "amigable para humanos" del pedido.
 *   - 28 días o más (o cuando hace falta mostrar la fecha exacta) →
 *     formato fijo `d:m:Y h:i a` (ej. `17:08:2026 12:25 am`), pedido tal
 *     cual por el Tech Lead — numérico y sin depender del idioma, a
 *     diferencia de la versión anterior ("17 Ago 12:25 am").
 */
final class FriendlyDate
{
    private const int RELATIVE_THRESHOLD_DAYS = 28;

    /**
     * Formato fijo pedido por el Tech Lead para cuando hace falta mostrar
     * la fecha exacta (no relativa) en cualquier listado.
     */
    public const string ABSOLUTE_FORMAT = 'd:m:Y h:i a';

    public static function format(mixed $date, ?User $user = null): ?string
    {
        if ($date === null) {
            return null;
        }

        $timezone = self::resolveTimezone($user);
        $locale = self::resolveLocale($user);

        $date = Carbon::parse($date)->setTimezone($timezone);
        $now = Carbon::now($timezone);

        if ($now->diffInDays($date) < self::RELATIVE_THRESHOLD_DAYS) {
            return $date->locale($locale)->diffForHumans($now, short: true);
        }

        return $date->format(self::ABSOLUTE_FORMAT);
    }

    public static function resolveTimezone(?User $user = null): string
    {
        $user ??= auth()->user();

        return filled($user?->timezone) ? $user->timezone : config('app.timezone', 'UTC');
    }

    public static function resolveLocale(?User $user = null): string
    {
        $user ??= auth()->user();

        return filled($user?->locale) ? $user->locale : config('app.locale', 'es');
    }
}
