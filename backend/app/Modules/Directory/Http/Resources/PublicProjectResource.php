<?php

namespace App\Modules\Directory\Http\Resources;

use App\Http\Resources\ServiceCategoryResource;
use App\Modules\Directory\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Projet publié, tel que tout visiteur le voit dans la vitrine publique (GET /public/projects,
 * décision 71).
 *
 * Vitrine limitée : titre, catégorie, budget, pays, travail à distance et date de publication.
 * Jamais la description, la ville, la date de début, ni rien du porteur de projet (ni nom, ni
 * type de compte, ni pays du compte). La description complète et la proposition restent
 * réservées aux prestataires connectés (GET /projects).
 *
 * @mixin Project
 */
class PublicProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'category' => new ServiceCategoryResource($this->whenLoaded('category')),
            'budget_min' => $this->budget_min,
            'budget_max' => $this->budget_max,
            'currency' => $this->currency,
            'country' => $this->country,
            'remote_ok' => $this->remote_ok,
            'published_at' => $this->published_at?->toIso8601ZuluString(),
        ];
    }
}
