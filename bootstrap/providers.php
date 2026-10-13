<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\PanelCmsProvider;
use App\Providers\Filament\PanelPlatformProvider;
use Translator\Framework\TranslatorServiceProvider;

return [
    AppServiceProvider::class,
    PanelCmsProvider::class,
    PanelPlatformProvider::class,
    TranslatorServiceProvider::class,
];
