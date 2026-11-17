<?php

namespace App\Modules\Appointments\Support;

use App\Support\Time\LocalTime;
use Carbon\CarbonInterface;

/**
 * Dates d'un créneau ou d'un rendez-vous dans les réponses : UTC (ISO 8601 "Z"), plus l'heure
 * locale du calendrier et son décalage UTC (qui distingue les deux occurrences d'une heure
 * ambiguë au passage à l'heure d'hiver). Le frontend convertit vers le fuseau du visiteur.
 */
final class SlotPresenter
{
    /**
     * @return array{starts_at: string, ends_at: string, local: array{starts_at: string, ends_at: string, offset: string}}
     */
    public static function present(CarbonInterface $startsAt, CarbonInterface $endsAt, string $timezone): array
    {
        $start = LocalTime::present($startsAt, $timezone);
        $end = LocalTime::present($endsAt, $timezone);

        return [
            'starts_at' => $startsAt->copy()->utc()->toIso8601ZuluString(),
            'ends_at' => $endsAt->copy()->utc()->toIso8601ZuluString(),
            'local' => ['starts_at' => $start['date_time'], 'ends_at' => $end['date_time'], 'offset' => $start['offset']],
        ];
    }

    /**
     * @param  list<array{starts_at: CarbonInterface, ends_at: CarbonInterface}>  $slots
     * @return list<array{starts_at: string, ends_at: string, local: array{starts_at: string, ends_at: string, offset: string}}>
     */
    public static function many(array $slots, string $timezone): array
    {
        return array_map(fn (array $slot): array => self::present($slot['starts_at'], $slot['ends_at'], $timezone), $slots);
    }
}
