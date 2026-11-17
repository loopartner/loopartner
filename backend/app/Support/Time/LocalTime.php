<?php

namespace App\Support\Time;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Heure locale et UTC (brique du noyau). Les dates sont stockées en UTC ; une heure saisie en
 * heure locale (plage de disponibilité, exception) est convertie ici, pour une date donnée.
 *
 * Changements d'heure (décret n° 2017-292 pour la France ; règles du fuseau IANA ailleurs) :
 *
 * - **heure inexistante** (passage à l'heure d'été : en France, de 02:00 à 03:00 le dernier
 *   dimanche de mars) : l'instant du changement d'heure, c'est-à-dire la première heure qui
 *   existe après le trou ;
 * - **heure ambiguë** (passage à l'heure d'hiver : en France, de 02:00 à 03:00 le dernier
 *   dimanche d'octobre, qui existe deux fois) : la première occurrence pour un début
 *   (EDGE_START), la dernière pour une fin (EDGE_END). Une plage de 01:00 à 04:00 dure donc
 *   deux heures réelles au printemps et quatre à l'automne.
 */
final class LocalTime
{
    public const EDGE_START = 'start';

    public const EDGE_END = 'end';

    public const MINUTES_PER_DAY = 1440;

    /**
     * Instant UTC d'une heure locale : date "AAAA-MM-JJ" et minutes depuis minuit (0 à 1440,
     * 1440 étant minuit à la fin de la journée), dans le fuseau IANA donné.
     */
    public static function instant(string $date, int $minute, string $timezone, string $edge = self::EDGE_START): CarbonImmutable
    {
        $zone = new DateTimeZone($timezone);
        $naive = (new DateTimeImmutable($date.' 00:00:00', new DateTimeZone('UTC')))->getTimestamp() + $minute * 60;

        $offsets = array_unique([
            self::offsetAt($zone, $naive - 86400),
            self::offsetAt($zone, $naive),
            self::offsetAt($zone, $naive + 86400),
        ]);

        $instants = [];

        foreach ($offsets as $offset) {
            $instant = $naive - $offset;

            if (self::offsetAt($zone, $instant) === $offset) {
                $instants[] = $instant;
            }
        }

        if ($instants !== []) {
            return CarbonImmutable::createFromTimestampUTC($edge === self::EDGE_END ? max($instants) : min($instants));
        }

        return CarbonImmutable::createFromTimestampUTC(self::gapEnd($zone, $naive));
    }

    /**
     * Date locale "AAAA-MM-JJ" d'un instant, dans le fuseau donné.
     */
    public static function localDate(CarbonInterface $instant, string $timezone): string
    {
        return CarbonImmutable::parse($instant)->setTimezone($timezone)->format('Y-m-d');
    }

    /**
     * Présentation d'un instant en heure locale : "2027-10-31T02:30" et son décalage UTC
     * ("+01:00"), qui distingue les deux occurrences d'une heure ambiguë.
     *
     * @return array{date_time: string, offset: string}
     */
    public static function present(CarbonInterface $instant, string $timezone): array
    {
        $local = CarbonImmutable::parse($instant)->setTimezone($timezone);

        return ['date_time' => $local->format('Y-m-d\TH:i'), 'offset' => $local->format('P')];
    }

    /**
     * Fuseau IANA reconnu par PHP (les abréviations comme "CET" ou les décalages fixes sont
     * refusés : ils ne suivent pas les changements d'heure).
     */
    public static function isValidTimezone(mixed $timezone): bool
    {
        if (! is_string($timezone) || $timezone === '' || ! str_contains($timezone, '/')) {
            return $timezone === 'UTC';
        }

        try {
            return in_array($timezone, DateTimeZone::listIdentifiers(), true) && new DateTimeZone($timezone) instanceof DateTimeZone;
        } catch (Throwable) {
            return false;
        }
    }

    private static function offsetAt(DateTimeZone $zone, int $timestamp): int
    {
        return $zone->getOffset((new DateTimeImmutable('@'.$timestamp)));
    }

    /**
     * Instant du changement d'heure qui crée le trou contenant l'heure locale $naive (exprimée
     * comme si elle était en UTC).
     */
    private static function gapEnd(DateTimeZone $zone, int $naive): int
    {
        $transitions = $zone->getTransitions($naive - 2 * 86400, $naive + 2 * 86400);
        $previousOffset = null;

        foreach ($transitions as $transition) {
            $offset = (int) $transition['offset'];
            $at = (int) $transition['ts'];

            if ($previousOffset !== null && $offset > $previousOffset && $naive >= $at + $previousOffset && $naive < $at + $offset) {
                return $at;
            }

            $previousOffset = $offset;
        }

        // Aucun trou trouvé (ne devrait pas arriver) : conversion avec le décalage précédent.
        return $naive - self::offsetAt($zone, $naive - 86400);
    }
}
