<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transformer público de `Contact`. Nunca incluye `email`/`phone`/
 * `company`/`data`/`notes`/`ip_address`/`user_agent` — ver ADR-013 ("API
 * nunca expone datos sensibles"). Pensado para cuando exista la API
 * pública/admin (todavía no implementada, ver TASK.md #8).
 *
 * El acceso a los datos sensibles descifrados (para la vista de detalle
 * de un Contact dentro del panel, por ejemplo) debe pasar explícitamente
 * por `ContactPolicy::viewSensitive` + `ContactSubmissionService::decryptData()`,
 * nunca por este Resource.
 */
class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'status' => $this->status?->value,
            'source' => $this->source,
            'assigned_to' => $this->whenLoaded('assignedTo', fn () => $this->assignedTo?->uuid),
            'last_contacted_at' => $this->last_contacted_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
