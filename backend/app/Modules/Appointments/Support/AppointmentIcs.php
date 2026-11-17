<?php

namespace App\Modules\Appointments\Support;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Enums\Party;
use App\Modules\Appointments\Models\Appointment;
use App\Support\Calendar\Ics;

/**
 * Fichier iCalendar d'un rendez-vous (briques du noyau App\Support\Calendar\Ics).
 *
 * - Joint aux emails (décision 56) : **titre et dates seulement**, ni lieu, ni lien, ni note,
 *   ni nom ; l'email peut être transféré ou lu sur un appareil partagé.
 * - Téléchargé par une partie connectée (GET /me/appointments/{uuid}/calendar.ics) : en plus,
 *   le lieu ou le lien d'un rendez-vous confirmé, comme le .ics d'un inscrit à un événement.
 *
 * UID stable (uuid du rendez-vous) et SEQUENCE = version : un report met à jour l'entrée de
 * l'agenda au lieu d'en créer une seconde.
 */
final class AppointmentIcs
{
    public static function forEmail(Appointment $appointment, string $locale): string
    {
        return self::document($appointment, $locale, []);
    }

    public static function forParty(Appointment $appointment, Party $party, string $locale): string
    {
        $extra = [];
        $location = AppointmentLocation::visibleTo($appointment, $party);

        if ($location !== null && isset($location['video_url'])) {
            $extra[] = 'URL:'.Ics::escape($location['video_url']);
            $extra[] = 'LOCATION:'.Ics::escape($locale === 'en' ? 'Video call' : 'Visioconférence');
        } elseif ($location !== null && isset($location['address'])) {
            $extra[] = 'LOCATION:'.Ics::escape($location['address']);
        } elseif ($location !== null && isset($location['phone'])) {
            $extra[] = 'LOCATION:'.Ics::escape(($locale === 'en' ? 'Phone call: ' : 'Appel téléphonique : ').$location['phone']);
        }

        return self::document($appointment, $locale, $extra);
    }

    public static function title(Appointment $appointment, string $locale): string
    {
        return ($locale === 'en' ? 'Appointment: ' : 'Rendez-vous : ').AppointmentLocation::typeTitle($appointment, $locale);
    }

    /**
     * @param  list<string>  $extra
     */
    private static function document(Appointment $appointment, string $locale, array $extra): string
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $cancelled = ! $appointment->status->isActive();

        $component = [
            'BEGIN:VEVENT',
            'UID:'.Ics::escape($appointment->uuid.'@'.$host),
            'SEQUENCE:'.$appointment->version,
            'DTSTAMP:'.Ics::utcDateTime(now()),
            'DTSTART:'.Ics::utcDateTime($appointment->starts_at),
            'DTEND:'.Ics::utcDateTime($appointment->ends_at),
            'SUMMARY:'.Ics::escape(self::title($appointment, $locale)),
            ...$extra,
            'STATUS:'.($cancelled ? 'CANCELLED' : ($appointment->status === AppointmentStatus::Confirmed ? 'CONFIRMED' : 'TENTATIVE')),
            'END:VEVENT',
        ];

        return Ics::document('-//Loopartner//Rendez-vous//FR', [$component]);
    }
}
