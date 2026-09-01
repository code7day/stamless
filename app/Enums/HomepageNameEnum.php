<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum HomepageNameEnum: string implements HasLabel
{
    case HOME = 'home';
    case PORTADA = 'portada';
    case INICIO = 'inicio';
    case MAIN = 'main';
    case PRINCIPAL = 'principal';
    case PAGINA_PRINCIPAL = 'pagina-principal';
    case LANDING = 'landing';
    case INDEX = 'index';
    case BIENVENIDA = 'bienvenida';
    case ENTRADA = 'entrada';
    case RAIZ = 'raiz';
    case PORTAL = 'portal';
    case HOME_PAGE = 'home-page';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function getLabel(): string
    {
        return match($this) {
            self::HOME => 'Home',
            self::PORTADA => 'Portada',
            self::INICIO => 'Inicio',
            self::MAIN => 'Main',
            self::PRINCIPAL => 'Principal',
            self::PAGINA_PRINCIPAL => 'Página Principal',
            self::LANDING => 'Landing',
            self::INDEX => 'Index',
            self::BIENVENIDA => 'Bienvenida',
            self::ENTRADA => 'Entrada',
            self::RAIZ => 'Raíz',
            self::PORTAL => 'Portal',
            self::HOME_PAGE => 'Home Page',
        };
    }

    public static function asSelectArray(): array
    {
        return array_combine(
            array_column(self::cases(), 'value'),
            array_map(fn($case) => $case->getLabel(), self::cases())
        );
    }

    public static function isHomepageKeyword(string $value): bool
    {
        return in_array(strtolower($value), array_column(self::cases(), 'value'));
    }

    public static function labels(): array
    {
        return array_map(fn($case) => $case->getLabel(), self::cases());
    }

    public static function isHomepageLabel(string $value): bool
    {
        $labels = array_map('strtolower', self::labels());
        return in_array(strtolower(trim($value)), $labels, true);
    }

}
