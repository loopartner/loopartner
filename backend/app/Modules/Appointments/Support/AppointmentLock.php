<?php

namespace App\Modules\Appointments\Support;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Models\Calendar;

/**
 * Verrouille un rendez-vous pour le faire changer d'état (à appeler dans une transaction) :
 * d'abord le calendrier du prestataire (même ordre que la réservation, pour éviter les
 * interblocages), puis la ligne du rendez-vous, relue à jour.
 */
final class AppointmentLock
{
    /**
     * @return array{appointment: Appointment, calendar: Calendar|null}
     */
    public static function lock(Appointment $appointment): array
    {
        $calendar = $appointment->calendar_id !== null
            ? Calendar::query()->whereKey($appointment->calendar_id)->lockForUpdate()->first()
            : null;

        return [
            'appointment' => Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail(),
            'calendar' => $calendar,
        ];
    }
}
