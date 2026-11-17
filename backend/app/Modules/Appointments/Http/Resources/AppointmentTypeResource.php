<?php

namespace App\Modules\Appointments\Http\Resources;

use App\Modules\Appointments\Models\AppointmentType;
use App\Support\Moderation\Restriction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Type de rendez-vous vu par son prestataire : tous les champs, dont le détail du lieu,
 * « bookable » (réservable aujourd'hui : actif, non masqué, dans la limite de son plan) et
 * l'état de modération. Jamais d'identifiant interne.
 *
 * @mixin AppointmentType
 */
class AppointmentTypeResource extends JsonResource
{
    /**
     * @param  list<int>  $bookableIds
     */
    public function __construct(AppointmentType $resource, private array $bookableIds = [])
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AppointmentType $type */
        $type = $this->resource;

        return [
            'uuid' => $type->uuid,
            'title' => $type->title,
            'description' => $type->description,
            'duration_minutes' => $type->duration_minutes,
            'location_mode' => $type->location_mode->value,
            'location_details' => $type->location_details,
            'visibility' => $type->visibility->value,
            'auto_confirm' => $type->auto_confirm,
            'is_active' => $type->is_active,
            'position' => $type->position,
            'bookable' => in_array($type->id, $this->bookableIds, true),
            'moderation' => Restriction::view($type),
            'created_at' => $type->created_at?->toIso8601ZuluString(),
            'updated_at' => $type->updated_at?->toIso8601ZuluString(),
        ];
    }
}
