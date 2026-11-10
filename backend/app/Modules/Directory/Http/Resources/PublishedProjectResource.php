<?php

namespace App\Modules\Directory\Http\Resources;

use App\Http\Resources\ServiceCategoryResource;
use App\Modules\Accounts\Enums\AccountType;
use App\Modules\Directory\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Projet publié, tel que les prestataires le voient (GET /projects).
 *
 * Confidentialité : aucune donnée personnelle ni identifiant du porteur de projet (ni email,
 * ni nom, ni id). Seuls son type de compte (particulier ou entrepreneur) et son pays sont
 * indiqués. Le contact passera par la mise en relation.
 *
 * La relation user.roles doit être chargée pour éviter une requête par projet.
 *
 * @mixin Project
 */
class PublishedProjectResource extends JsonResource
{
    private const OWNER_TYPES = [AccountType::Particulier->value, AccountType::Entrepreneur->value];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $owner = $this->user;

        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'category' => new ServiceCategoryResource($this->whenLoaded('category')),
            'budget_min' => $this->budget_min,
            'budget_max' => $this->budget_max,
            'currency' => $this->currency,
            'country' => $this->country,
            'city' => $this->city,
            'remote_ok' => $this->remote_ok,
            'desired_start_date' => $this->desired_start_date?->toDateString(),
            'published_at' => $this->published_at?->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'owner' => [
                'account_type' => $owner->getRoleNames()->first(fn (string $role): bool => in_array($role, self::OWNER_TYPES, true)),
                'country' => $owner->country,
            ],
        ];
    }
}
