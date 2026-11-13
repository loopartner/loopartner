<?php

namespace App\Modules\Events\Support;

use App\Modules\Events\Enums\AddressVisibility;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Support\Calendar\Ics;

/**
 * Fichier calendrier (iCalendar, RFC 5545) d'un événement, construit avec les briques du noyau
 * (App\Support\Calendar\Ics : échappement, repli des lignes, dates).
 *
 * - Version publique : jamais le lien de connexion ; adresse masquée si l'organisateur la
 *   réserve aux inscrits (address_visibility = registrants).
 * - Version d'un inscrit : avec le lien de connexion et l'adresse complète.
 *
 * UID stable (slug), SEQUENCE = version de l'événement.
 */
final class IcsCalendar
{
    public static function render(Event $event, bool $forRegistrant): string
    {
        $eventUrl = rtrim((string) config('app.frontend_url'), '/').'/events/'.$event->slug;
        $description = $event->description."\n\n".$eventUrl;

        if ($forRegistrant && $event->format->isOnline() && $event->online_url !== null) {
            $description .= "\n\n".self::label('online').' '.$event->online_url;
        }

        return Ics::document('-//Loopartner//Agenda//FR', [[
            'BEGIN:VEVENT',
            'UID:'.Ics::escape($event->slug.'@'.parse_url((string) config('app.url'), PHP_URL_HOST)),
            'DTSTAMP:'.Ics::utcDateTime(now()),
            'SEQUENCE:'.$event->version,
            'DTSTART:'.Ics::utcDateTime($event->starts_at),
            'DTEND:'.Ics::utcDateTime($event->ends_at),
            'SUMMARY:'.Ics::escape($event->title),
            'DESCRIPTION:'.Ics::escape($description),
            'LOCATION:'.Ics::escape(self::location($event, $forRegistrant)),
            'URL:'.Ics::escape($eventUrl),
            'STATUS:'.($event->status === EventStatus::Cancelled ? 'CANCELLED' : 'CONFIRMED'),
            'END:VEVENT',
        ]]);
    }

    private static function location(Event $event, bool $forRegistrant): string
    {
        if (! $event->format->hasVenue()) {
            return self::label('online_only');
        }

        $showAddress = $forRegistrant || $event->address_visibility === AddressVisibility::Public;

        $parts = array_filter([
            $event->venue_name,
            $showAddress ? $event->address_line : null,
            trim(($showAddress ? (string) $event->postal_code : '').' '.(string) $event->city),
            $event->country,
        ], fn (?string $part): bool => $part !== null && $part !== '');

        return implode(', ', $parts);
    }

    private static function label(string $key): string
    {
        return match ($key) {
            'online' => 'Lien de connexion :',
            default => 'En ligne',
        };
    }
}
