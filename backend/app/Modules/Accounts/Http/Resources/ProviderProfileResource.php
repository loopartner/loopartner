<?php

namespace App\Modules\Accounts\Http\Resources;

use App\Http\Resources\ServiceCategoryResource;
use App\Modules\Accounts\Models\ProviderProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Profil prestataire tel que son propriétaire le voit (GET/PUT /me/provider-profile).
 * Les identifiants internes (id, user_id) ne sont pas exposés.
 *
 * @mixin ProviderProfile
 */
class ProviderProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'display_name' => $this->display_name,
            'slug' => $this->slug,
            'headline' => $this->headline,
            'bio' => $this->bio,
            'city' => $this->city,
            'country' => $this->country,
            'website' => $this->website,
            'logo_url' => $this->logo?->url(),
            'categories' => ServiceCategoryResource::collection($this->whenLoaded('categories')),
            'status' => $this->status->value,
            'published_at' => $this->published_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
