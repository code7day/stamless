<?php

/**
 * DEPRECATED — Alias de backward-compatibility. No usar en código nuevo.
 *
 * Este archivo existía como `config/genesis.php` hasta ADR-026 (rebrand
 * Genesisly → Stamless). Se mantiene SOLO para no romper ninguna llamada
 * a `config('genesis.urls.*')` que pudiera quedar en vendor o paquetes
 * de terceros que cachen la configuración.
 *
 * En código de la aplicación, usar `config('stamless.urls.*')`.
 */
return require __DIR__.'/stamless.php';
