<?php

namespace App\Support\Calendar;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Briques iCalendar (RFC 5545) du noyau, sans package externe : échappement du texte, repli
 * des lignes, formats de date et enveloppe VCALENDAR. Utilisées par le module Events (fichier
 * d'un événement) et par le module Compliance (abonnement au calendrier des échéances).
 *
 * Le texte saisi est échappé (antislash, point-virgule, virgule, retours à la ligne) : il ne
 * peut pas injecter de propriété ni de composant. Les lignes sont repliées à 75 octets sans
 * couper un caractère UTF-8, et terminées par CRLF.
 */
final class Ics
{
    private const LINE_OCTETS = 75;

    /**
     * Calendrier complet : en-tête, composants (chacun une liste de lignes non repliées), fin.
     *
     * @param  list<list<string>>  $components
     * @param  list<string>  $headers  propriétés supplémentaires du calendrier (X-WR-CALNAME…)
     */
    public static function document(string $prodId, array $components, array $headers = []): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:'.$prodId,
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            ...$headers,
        ];

        foreach ($components as $component) {
            array_push($lines, ...$component);
        }

        $lines[] = 'END:VCALENDAR';

        return implode('', array_map(fn (string $line): string => self::fold($line)."\r\n", $lines));
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

    /**
     * Date et heure en UTC (forme DATE-TIME avec le suffixe Z).
     */
    public static function utcDateTime(DateTimeInterface $date): string
    {
        return CarbonImmutable::parse($date)->utc()->format('Ymd\THis\Z');
    }

    /**
     * Journée entière (forme DATE, à utiliser avec ;VALUE=DATE).
     */
    public static function date(DateTimeInterface $date): string
    {
        return CarbonImmutable::parse($date)->format('Ymd');
    }
}
