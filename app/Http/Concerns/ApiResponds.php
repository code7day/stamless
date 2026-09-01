<?php

namespace App\Http\Concerns;

use App\Support\Api\ErrorEnvelope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Envelope estándar de respuesta JSON de la API (ADR-009):
 * `success`, `message`, `status_code`, `data`, `meta`, `links`, `errors`.
 */
trait ApiResponds
{
    protected function success(
        mixed $data = null,
        ?string $message = null,
        array $meta = [],
        array $links = [],
        int $status = 200,
    ): JsonResponse {
        return response()->json(array_filter([
            'success' => true,
            'message' => $message,
            'status_code' => $status,
            'data' => $data,
            'meta' => $meta ?: null,
            'links' => $links ?: null,
        ], fn ($value) => ! is_null($value)), $status);
    }

    /**
     * Delega en `ErrorEnvelope` (ver ADR-024) — el mismo builder que usa el
     * handler global de excepciones de `bootstrap/app.php`, para que un
     * error armado a mano acá y uno armado por una excepción no capturada
     * tengan siempre el mismo shape exacto.
     */
    protected function error(string $message, int $status = 404, array $errors = []): JsonResponse
    {
        return ErrorEnvelope::make($message, $status, $errors);
    }

    /**
     * Envelope para listados paginados: `data` es la colección transformada
     * por `$resourceClass`, `meta` trae la paginación y `links` los enlaces
     * first/prev/next/last (ADR-009).
     */
    protected function paginated(
        LengthAwarePaginator $paginator,
        string $resourceClass,
        ?string $message = null,
    ): JsonResponse {
        return $this->success(
            data: $resourceClass::collection($paginator->items()),
            message: $message,
            meta: [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            links: [
                'first' => $paginator->url(1),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
                'last' => $paginator->url($paginator->lastPage()),
            ],
        );
    }

    /**
     * Clamp de `per_page` para no permitir listados gigantes desde query string.
     */
    protected function perPage(Request $request, int $default = 15, int $max = 50): int
    {
        $value = (int) $request->query('per_page', $default);

        return max(1, min($value ?: $default, $max));
    }
}
