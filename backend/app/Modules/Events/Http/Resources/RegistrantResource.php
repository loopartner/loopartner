<?php

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Support\Organizers;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Inscrit tel que l'organisateur le voit : nom affiché et type de compte uniquement (l'inscrit
 * en est prévenu avant de s'inscrire). Jamais d'email ni d'identifiant interne ; l'uuid de
 * l'inscription sert à retirer l'inscrit.
 *
 * Relation à charger : user.roles.
 *
 * @mixin EventRegistration
 */
class RegistrantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EventRegistration $registration */
        $registration = $this->resource;

        return [
            'uuid' => $registration->uuid,
            'name' => $registration->user->name,
            'account_type' => Organizers::accountType($registration->user),
            'registered_at' => $registration->created_at?->toIso8601ZuluString(),
        ];
    }
}
