<?php

namespace App\Modules\Appointments\Support;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Enums\Party;
use App\Modules\Appointments\Models\Appointment;

/**
 * Lieu d'un rendez-vous et titre du type, tels qu'une partie peut les voir (décision 53).
 *
 * - Le lieu (adresse, téléphone, lien de visio) n'est montré aux deux parties qu'une fois le
 *   rendez-vous confirmé, et plus du tout après son effacement (durée de conservation).
 * - Un type masqué par la modération n'affiche plus son titre.
 */
final class AppointmentLocation
{
    /**
     * @return array<string, string>|null
     */
    public static function visibleTo(Appointment $appointment, Party $party): ?array
    {
        if ($appointment->status !== AppointmentStatus::Confirmed || $appointment->location === null || $appointment->location_erased_at !== null) {
            return null;
        }

        $location = $appointment->location->getArrayCopy();

        return $location === [] ? null : array_map('strval', $location);
    }

    public static function typeTitle(Appointment $appointment, string $locale = 'fr'): string
    {
        if ($appointment->type?->isRestricted() === true) {
            return $locale === 'en' ? 'Appointment' : 'Rendez-vous';
        }

        return $appointment->type_title;
    }
}
