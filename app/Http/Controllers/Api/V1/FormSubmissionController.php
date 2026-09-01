<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Api\MissingRequiredFieldsException;
use App\Models\Form;
use App\Services\ContactSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Endpoint público de envío de formularios. Reutiliza
 * `ContactSubmissionService` (dominio/cifrado/notificación ya resueltos en
 * ADR-015) — no duplica esa lógica acá.
 */
class FormSubmissionController extends Controller
{
    public function __construct(private readonly ContactSubmissionService $contactSubmissionService) {}

    public function store(Request $request, string $tenant_slug, string $slug): JsonResponse
    {
        $this->resolveTenant($tenant_slug);

        $form = Form::where('slug', $slug)->where('is_active', true)->first();

        if (! $form) {
            return $this->error('Formulario no encontrado.', 404, ['code' => 'not_found']);
        }

        try {
            $contact = $this->contactSubmissionService->submit(
                $form,
                $request->except(['page_url']),
                [
                    'source' => $request->header('Referer'),
                    'page_url' => $request->input('page_url'),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            );
        } catch (MissingRequiredFieldsException $e) {
            // Catch específico ANTES del genérico de abajo (misma clase
            // padre): acá sí tenemos desglose por campo (ADR-024).
            return $this->error('Revisá los datos enviados.', 422, [
                'code' => 'validation',
                'fields' => $e->fields(),
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['code' => 'validation']);
        }

        return $this->success(
            data: ['uuid' => $contact->uuid],
            message: 'Formulario enviado correctamente.',
            status: 201,
        );
    }
}
