<?php

namespace App\Modules\Events\Support;

use App\Modules\Events\Enums\AddressVisibility;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Fichier calendrier (iCalendar, RFC 5545) d'un événement, sans package externe.
 *
 * - Version publique : jamais le lien de connexion ; adresse masquée si l'organisateur la
 *   réserve aux inscrits (address_visibility = registrants).
 * - Version d'un inscrit : avec le lien de connexion et l'adresse complète.
 *
 * Le texte saisi est échappé (antislash, point-virgule, virgule, retours à la ligne) : il ne
 * peut pas injecter de propriété ni de composant. Les lignes sont repliées à 75 octets sans
 * couper un caractère UTF-8. UID stable (slug), SEQUENCE = version de l'événement.
 */
final class IcsCalendar
{
    private const LINE_OCTETS = 75;

    public static function render(Event $event, bool $forRegistrant): string
    {
        $eventUrl = rtrim((string) config('app.frontend_url'), '/').'/events/'.$event->slug;
        $description = $event->description."\n\n".$eventUrl;

        if ($forRegistrant && $event->format->isOnline() && $event->online_url !== null) {
            $description .= "\n\n".self::label('online').' '.$event->online_url;
        }

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Loopartner//Agenda//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.self::escape($event->slug.'@'.parse_url((string) config('app.url'), PHP_URL_HOST)),
            'DTSTAMP:'.self::date(now()),
            'SEQUENCE:'.$event->version,
            'DTSTART:'.self::date($event->starts_at),
            'DTEND:'.self::date($event->ends_at),
            'SUMMARY:'.self::escape($event->title),
            'DESCRIPTION:'.self::escape($description),
            'LOCATION:'.self::escape(self::location($event, $forRegistrant)),
            'URL:'.self::escape($eventUrl),
            'STATUS:'.($event->status === EventStatus::Cancelled ? 'CANCELLED' : 'CONFIRMED'),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode('', array_map(fn (string $line): string => self::fold($line)."\r\n", $lines));
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

    /**
     * Échappement d'une valeur TEXT (RFC 5545, 3.3.11).
     */
    public static function escape(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
    }

    /**
     * Repli des lignes à 75 octets (RFC 5545, 3.1), sans couper un caractère multioctet.
     */
    public static function fold(string $line): string
    {
        $folded = '';
        $current = '';
        $limit = self::LINE_OCTETS;

        foreach (mb_str_split($line) as $character) {
            if (strlen($current) + strlen($character) > $limit) {
                $folded .= $current."\r\n ";
                $current = '';
                $limit = self::LINE_OCTETS - 1;
            }

            $current .= $character;
        }

        return $folded.$current;
    }

    private static function date(DateTimeInterface $date): string
    {
        return CarbonImmutable::parse($date)->utc()->format('Ymd\THis\Z');
    }

    private static function label(string $key): string
    {
        return match ($key) {
            'online' => 'Lien de connexion :',
            default => 'En ligne',
        };
    }
}
