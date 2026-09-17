<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\FormResource;
use App\Models\Form;
use Illuminate\Http\JsonResponse;

class FormController extends Controller
{
    public function show(string $tenant_slug, string $slug): JsonResponse
    {
        $tenant = $this->resolveTenant($tenant_slug);

        $form = Form::where('tenant_id', $tenant->id)
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with(['fields' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')])
            ->first();

        if (! $form) {
            return $this->error('Formulario no encontrado.', 404, ['code' => 'not_found']);
        }

        return $this->success(new FormResource($form));
    }
}
