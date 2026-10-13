<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ModuleEnum: string implements HasLabel
{
    case Pages = 'pages';
    case Posts = 'posts';
    case Media = 'media';
    case Menus = 'menus';
    case Settings = 'settings';
    case Sliders = 'sliders';
    case Contacts = 'contacts';
    case Services = 'services';
    case Testimonials = 'testimonials';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pages => __('Pages'),
            self::Posts => __('Blog / Posts'),
            self::Media => __('Media Library'),
            self::Menus => __('Navigation Menus'),
            self::Settings => __('Site Settings'),
            self::Sliders => __('Sliders'),
            self::Contacts => __('Contacts and Forms'),
            self::Services => __('Services'),
            self::Testimonials => __('Testimonials'),
        };
    }

    public function isCore(): bool
    {
        return in_array($this, [
            self::Pages,
            self::Posts,
            self::Media,
            self::Menus,
            self::Settings,
            self::Sliders,
            self::Contacts,
        ], true);
    }
}
