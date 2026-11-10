<?php

namespace App\Http\Resources;

use App\Models\ServiceCategory;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Catégorie de services : slug et nom dans la langue de la requête (Locale::fromRequest).
 * Les sous-catégories ne sont incluses que si la relation "children" est chargée.
 * Jamais d'identifiant interne (ni id, ni parent_id).
 *
 * @mixin ServiceCategory
 */
class ServiceCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->nameIn(Locale::fromRequest($request)),
            'children' => self::collection($this->whenLoaded('children')),
        ];
    }
}
