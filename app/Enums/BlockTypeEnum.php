<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BlockTypeEnum: string implements HasLabel
{
    case Hero = 'hero';
    case RichText = 'rich_text';
    case Image = 'image';
    case Cta = 'cta';
    case Features = 'features';
    case Faq = 'faq';
    case ContactForm = 'contact_form';
    case LegalNotice = 'legal_notice';
    case Heading = 'heading';
    case Split = 'split';
    case Testimonials = 'testimonials';
    case Logos = 'logos';
    case ServicesGrid = 'services_grid';

    public function getLabel(): string
    {
        return match ($this) {
            self::Hero => 'Hero (Cabecera)',
            self::RichText => 'Texto enriquecido',
            self::Image => 'Imagen única',
            self::Cta => 'Llamado a la acción (CTA)',
            self::Features => 'Características / Grid',
            self::Faq => 'Preguntas frecuentes (FAQ)',
            self::ContactForm => 'Formulario de contacto',
            self::LegalNotice => 'Aviso legal / Contenido estático',
            self::Heading => 'Heading (Sección de Títulos)',
            self::Split => 'Split Imagen y Texto',
            self::Testimonials => 'Testimonios',
            self::Logos => 'Logos / Socios',
            self::ServicesGrid => 'Grid de Servicios',
        };
    }
}
