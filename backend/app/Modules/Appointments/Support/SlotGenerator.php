<?php

namespace App\Modules\Appointments\Support;

use App\Modules\Appointments\Enums\ExceptionKind;
use App\Modules\Appointments\Models\AvailabilityException;
use App\Modules\Appointments\Models\AvailabilityRule;
use App\Modules\Appointments\Models\Calendar;
use App\Modules\Appointments\Models\SlotHold;
use App\Support\Time\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Créneaux libres d'un calendrier pour une durée donnée.
 *
 * - Les plages (hebdomadaires et exceptions) sont en heure locale du calendrier ; chaque date
 *   est convertie en UTC séparément (LocalTime) : une plage qui traverse un changement d'heure
 *   dure plus ou moins longtemps en temps réel.
 * - Les créneaux sont découpés **en temps réel** à partir du début de chaque plage, tous les
 *   slot_step_minutes, tant que le rendez-vous tient dans la plage.
 * - Exceptions d'une date : une fermeture sur la journée retire les plages hebdomadaires, une
 *   fermeture partielle en retire une partie, puis les ouvertures ponctuelles s'ajoutent.
 * - Sont écartés : les créneaux avant le délai de prévenance, au-delà de l'horizon, et ceux qui
 *   chevauchent un créneau retenu (rendez-vous actif ou report proposé), marge comprise.
 *
 * Le même calcul sert à l'affichage et au contrôle d'une réservation (sous le verrou du
 * calendrier) : un créneau réservable est exactement un créneau affiché.
 */
class SlotGenerator
{
    /**
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    public function between(Calendar $calendar, int $durationMinutes, CarbonImmutable $from, CarbonImmutable $to, int $horizonDays, ?int $ignoreAppointmentId = null): array
    {
        $timezone = $calendar->timezone;
        $now = CarbonImmutable::now()->utc();
        $from = $from->utc()->max($now->addMinutes($calendar->min_notice_minutes));
        $to = $to->utc()->min($now->addDays($horizonDays)->addSecond());

        if ($from->greaterThanOrEqualTo($to)) {
            return [];
        }

        $buffer = $calendar->buffer_minutes;
        $step = max(5, $calendar->slot_step_minutes);
        $firstDate = CarbonImmutable::parse(LocalTime::localDate($from, $timezone))->subDay();
        $lastDate = CarbonImmutable::parse(LocalTime::localDate($to, $timezone));

        $rules = AvailabilityRule::query()->where('calendar_id', $calendar->id)->get()->groupBy('weekday');
        $exceptions = AvailabilityException::query()
            ->where('calendar_id', $calendar->id)
            ->whereBetween('date', [$firstDate->toDateString(), $lastDate->toDateString()])
            ->get()
            ->groupBy(fn (AvailabilityException $exception): string => $exception->date->toDateString());
        $holds = SlotHold::query()
            ->where('calendar_id', $calendar->id)
            ->where('starts_at', '<', $to->addMinutes($durationMinutes + $buffer))
            ->where('ends_at', '>', $from->subMinutes($buffer))
            ->when($ignoreAppointmentId !== null, fn ($query) => $query->where('appointment_id', '!=', $ignoreAppointmentId))
            ->get(['starts_at', 'ends_at']);

        $slots = [];

        for ($date = $firstDate; $date->lessThanOrEqualTo($lastDate); $date = $date->addDay()) {
            $day = $date->toDateString();
            $intervals = $this->intervalsFor(
                $rules->get($date->dayOfWeekIso, collect())->map(fn (AvailabilityRule $rule): array => [$rule->start_minute, $rule->end_minute])->all(),
                $exceptions->get($day, collect())->all(),
            );

            foreach ($intervals as [$startMinute, $endMinute]) {
                $rangeStart = LocalTime::instant($day, $startMinute, $timezone, LocalTime::EDGE_START);
                $rangeEnd = LocalTime::instant($day, $endMinute, $timezone, LocalTime::EDGE_END);

                for ($start = $rangeStart; $start->addMinutes($durationMinutes)->lessThanOrEqualTo($rangeEnd); $start = $start->addMinutes($step)) {
                    if ($start->lessThan($from)) {
                        continue;
                    }

                    if ($start->greaterThanOrEqualTo($to)) {
                        break;
                    }

                    $end = $start->addMinutes($durationMinutes);

                    if (! $this->overlapsHold($holds, $start, $end, $buffer)) {
                        $slots[$start->getTimestamp()] = ['starts_at' => $start, 'ends_at' => $end];
                    }
                }
            }
        }

        ksort($slots);

        return array_values($slots);
    }

    /**
     * Le créneau qui commence à cet instant est libre et réservable pour cette durée.
     */
    public function isFree(Calendar $calendar, int $durationMinutes, CarbonImmutable $startsAt, int $horizonDays, ?int $ignoreAppointmentId = null): bool
    {
        $startsAt = $startsAt->utc();

        foreach ($this->between($calendar, $durationMinutes, $startsAt, $startsAt->addMinute(), $horizonDays, $ignoreAppointmentId) as $slot) {
            if ($slot['starts_at']->equalTo($startsAt)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Plages d'une date en minutes locales, exceptions appliquées, fusionnées et triées.
     *
     * @param  list<array{0: int, 1: int}>  $weekly
     * @param  list<AvailabilityException>  $exceptions
     * @return list<array{0: int, 1: int}>
     */
    private function intervalsFor(array $weekly, array $exceptions): array
    {
        $intervals = self::merge($weekly);

        foreach ($exceptions as $exception) {
            if ($exception->kind !== ExceptionKind::Closed) {
                continue;
            }

            $intervals = $exception->isWholeDay()
                ? []
                : self::subtract($intervals, [(int) $exception->start_minute, (int) $exception->end_minute]);
        }

        foreach ($exceptions as $exception) {
            if ($exception->kind === ExceptionKind::Open && ! $exception->isWholeDay()) {
                $intervals[] = [(int) $exception->start_minute, (int) $exception->end_minute];
            }
        }

        return self::merge($intervals);
    }

    /**
     * @param  list<array{0: int, 1: int}>  $intervals
     * @return list<array{0: int, 1: int}>
     */
    private static function merge(array $intervals): array
    {
        usort($intervals, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];

        foreach ($intervals as [$start, $end]) {
            if ($end <= $start) {
                continue;
            }

            $last = count($merged) - 1;

            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $intervals
     * @param  array{0: int, 1: int}  $removed
     * @return list<array{0: int, 1: int}>
     */
    private static function subtract(array $intervals, array $removed): array
    {
        $result = [];

        foreach ($intervals as [$start, $end]) {
            if ($removed[1] <= $start || $removed[0] >= $end) {
                $result[] = [$start, $end];

                continue;
            }

            if ($removed[0] > $start) {
                $result[] = [$start, $removed[0]];
            }

            if ($removed[1] < $end) {
                $result[] = [$removed[1], $end];
            }
        }

        return $result;
    }

    /**
     * @param  iterable<SlotHold>  $holds
     */
    private function overlapsHold(iterable $holds, CarbonImmutable $start, CarbonImmutable $end, int $buffer): bool
    {
        foreach ($holds as $hold) {
            if ($hold->starts_at->lessThan($end->addMinutes($buffer)) && $hold->ends_at->greaterThan($start->subMinutes($buffer))) {
                return true;
            }
        }

        return false;
    }
}
