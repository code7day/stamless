<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'url' => $this->url(),
            'alt_text' => $this->alt_text,
            'mime_type' => $this->mime_type,
        ];
    }

    /**
     * Variante nula-segura para refs opcionales (`imageDesktop`,
     * `featuredImage`, etc.) sin tener que envolver cada llamada en
     * `whenLoaded`/comprobaciones de null en el resource que la usa.
     */
    public static function optional(?Media $media): ?array
    {
        return $media ? (new self($media))->resolve() : null;
    }
}
