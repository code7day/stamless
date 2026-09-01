<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Disks de filesystem soportados por `media.disk` (ver config/filesystems.php).
 * Producción: `r2` (Cloudflare R2). Desarrollo local: `local`.
 */
enum MediaDiskEnum: string implements HasLabel
{
    case Local = 'local';
    case Public = 'public';
    case R2 = 'r2';
    case S3 = 's3';

    public function getLabel(): string
    {
        return match ($this) {
            self::Local => 'Local (Privado)',
            self::Public => 'Público',
            self::R2 => 'Cloudflare R2',
            self::S3 => 'Amazon S3',
        };
    }
}
