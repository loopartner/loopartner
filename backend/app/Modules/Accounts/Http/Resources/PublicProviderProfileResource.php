<?php

namespace App\Modules\Accounts\Http\Resources;

use App\Modules\Accounts\Models\ProviderProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Profil prestataire public (GET /providers/{slug}).
 *
 * N'expose aucune donnée personnelle ni identifiant interne : ni email, ni id de
 * l'utilisateur, ni id du profil.
 *
 * @mixin ProviderProfile
 */
class PublicProviderProfileResource extends JsonResource
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
            'published_at' => $this->published_at?->toIso8601ZuluString(),
        ];
    }
}
