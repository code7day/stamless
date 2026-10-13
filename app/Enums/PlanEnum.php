<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PlanEnum: string implements HasColor, HasLabel
{
    case Trial = 'trial';
    case Starter = 'starter';
    case Sponsorship = 'sponsorship';
    case Pro = 'pro';
    case Premium = 'premium';

    /**
     * Nombre formal y legible del plan.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::Trial => __('Trial'),
            self::Starter => __('Starter'),
            self::Sponsorship => __('Sponsorship'),
            self::Pro => __('Pro'),
            self::Premium => __('Premium'),
        };
    }

    /**
     * Etiqueta / badge de estado del plan en widgets y paneles.
     */
    public function getBadgeLabel(): string
    {
        return match ($this) {
            self::Trial => __('15-day Trial'),
            self::Starter => __('Starter Active'),
            self::Sponsorship => __('B2B Agreement'),
            self::Pro => __('Pro Active'),
            self::Premium => __('Top Tier'),
        };
    }

    /**
     * Color del badge en Filament (warning, info, gray, primary, success).
     */
    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Trial => 'warning',
            self::Starter => 'info',
            self::Sponsorship => 'gray',
            self::Pro => 'primary',
            self::Premium => 'success',
        };
    }

    /**
     * Descripción honesta y precisa según las capacidades del plan.
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::Trial => __('Active trial period. Upgrade to Starter or Pro to unlock storage and more content.'),
            self::Starter => __('Individual plan with editable copyright and 50 active posts. Upgrade to Pro for vertical modules and greater capacity.'),
            self::Sponsorship => __('Institutional agreement plan with extended resources and partner branding.'),
            self::Pro => __('Access to industry modules, full brand personalization, and high resource capacity.'),
            self::Premium => __('Your workspace includes maximum resources, modules, and platform benefits.'),
        };
    }

    /**
     * Texto contextual del botón de acción en el dashboard.
     */
    public function getActionButtonLabel(): ?string
    {
        return match ($this) {
            self::Trial => __('Upgrade plan'),
            self::Starter => __('Upgrade to Pro'),
            self::Sponsorship => __('Request expansion'),
            self::Pro => __('Upgrade to Premium'),
            self::Premium => null,
        };
    }

    /**
     * Determina si el plan actual tiene margen de mejora / upgrade.
     */
    public function canUpgrade(): bool
    {
        return $this !== self::Premium;
    }

    /**
     * Determina si es considerado tier gratuito (Trial / Sponsorship).
     */
    public function isFreeTier(): bool
    {
        return in_array($this, [self::Trial, self::Sponsorship], true);
    }

    /**
     * Determina si es plan bajo convenio/auspicio.
     */
    public function isSponsorshipTier(): bool
    {
        return $this === self::Sponsorship;
    }

    /**
     * Determina si tiene derecho a editar el copyright del footer.
     */
    public function canEditCopyright(): bool
    {
        return $this !== self::Trial;
    }

    /**
     * Determina si tiene derecho a personalizar el brand de Studio.
     */
    public function canPersonalizeStudioBrand(): bool
    {
        return $this !== self::Trial;
    }

    /**
     * Resuelve una instancia de PlanEnum a partir de cualquier string o alias heredado.
     */
    public static function tryFromKey(?string $value): ?self
    {
        if (blank($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'free', 'freemium', 'trial' => self::Trial,
            'freelance', 'starter' => self::Starter,
            'sponsorship', 'convenio', 'auspicio' => self::Sponsorship,
            'pro', 'professional' => self::Pro,
            'corporate', 'premium' => self::Premium,
            default => self::tryFrom($normalized),
        };
    }
}
