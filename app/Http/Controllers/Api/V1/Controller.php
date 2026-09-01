<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ApiResponds;
use App\Http\Concerns\ResolvesPublicLinks;
use App\Http\Concerns\ResolvesTenant;

/**
 * Base de todos los controllers de la API pública v1. No agrega lógica de
 * negocio propia: solo compone los concerns de tenancy, envelope de
 * respuesta y resolución de contrato público (links/hero sin ids internos,
 * ver ADR-018) que comparten todos los endpoints headless.
 */
abstract class Controller extends \App\Http\Controllers\Controller
{
    use ApiResponds, ResolvesPublicLinks, ResolvesTenant;
}
