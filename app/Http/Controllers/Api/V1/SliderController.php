<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LanguageEnum;
use App\Http\Resources\Api\V1\SliderResource;
use App\Models\Slider;
use Illuminate\Http\JsonResponse;

class SliderController extends Controller
{
    public function show(string $tenant_slug, string $slug): JsonResponse
    {
        $this->resolveTenant($tenant_slug);

        $slider = Slider::query()
            ->where('lang_iso', LanguageEnum::Spanish->value)
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with(['slides' => fn ($query) => $query
                ->where('is_active', true)
                ->with(['imageDesktop', 'imageTablet', 'imageMobile', 'videoDesktop', 'videoMobile']),
            ])
            ->first();

        if (! $slider) {
            return $this->error('Slider no encontrado.', 404, ['code' => 'not_found']);
        }

        $this->attachResolvedLinks($slider->slides);

        return $this->success(new SliderResource($slider));
    }
}
