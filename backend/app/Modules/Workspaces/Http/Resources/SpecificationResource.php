<?php

namespace App\Modules\Workspaces\Http\Resources;

use App\Http\Resources\ServiceCategoryResource;
use App\Modules\Workspaces\Models\Specification;
use App\Modules\Workspaces\Support\SpecificationSections;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cahier des charges vu par le propriétaire : sections complètes (liste fermée), catégorie,
 * étape (uuid), origine et marque IA.
 *
 * @mixin Specification
 */
class SpecificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Specification $specification */
        $specification = $this->resource;

        return [
            'uuid' => $specification->uuid,
            'title' => $specification->title,
            'category' => $specification->category === null ? null : (new ServiceCategoryResource($specification->category))->resolve($request),
            'step' => $specification->step?->uuid,
            'sections' => SpecificationSections::normalize($specification->sections),
            'source' => $specification->source->value,
            'ai_generated' => $specification->ai_generated_at !== null,
            'created_at' => $specification->created_at?->toIso8601ZuluString(),
            'updated_at' => $specification->updated_at?->toIso8601ZuluString(),
        ];
    }
}
