<?php

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Enums\ScheduleType;
use App\Modules\Compliance\Models\Company;
use Carbon\CarbonImmutable;

/**
 * Déroule la récurrence d'une règle d'échéance (payload.schedule) entre deux jours, bornes
 * comprises. Les mois, jours et dates viennent de la règle : le code ne contient aucune date.
 *
 * Chaque occurrence a une clé stable (occurrence_key) qui identifie la période et non la date :
 * si une nouvelle version de la règle déplace l'échéance, l'échéance existante est déplacée au
 * lieu d'être doublée.
 *
 * - Un jour au-delà de la fin du mois (31 en avril, 29 février…) est ramené au dernier jour.
 * - after_fiscal_year_end : N mois après une clôture en fin de mois tombe en fin de mois
 *   (clôture au 31 décembre + 6 mois = 30 juin), puis N jours sont ajoutés.
 * - Jamais d'occurrence avant la création de l'entreprise.
 * - Aucun report automatique d'une échéance qui tombe un week-end ou un jour férié : la date
 *   vient de la règle (fixed_dates quand elle change chaque année).
 */
final class DeadlineSchedule
{
    /**
     * @param  array<string, mixed>  $schedule
     * @return list<array{key: string, date: CarbonImmutable}>
     */
    public static function occurrences(array $schedule, Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $created = CarbonImmutable::parse($company->created_on->toDateString());
        $from = $from->max($created)->startOfDay();
        $to = $to->startOfDay();

        if ($from->greaterThan($to)) {
            return [];
        }

        $type = ScheduleType::tryFrom((string) ($schedule['type'] ?? ''));
        $day = self::int($schedule['day'] ?? null);
        $occurrences = [];

        switch ($type) {
            case ScheduleType::Yearly:
                $month = self::int($schedule['month'] ?? null);

                if ($month !== null && $day !== null) {
                    for ($year = $from->year; $year <= $to->year; $year++) {
                        $occurrences[] = ['key' => (string) $year, 'date' => self::clamp($year, $month, $day)];
                    }
                }
                break;

            case ScheduleType::Monthly:
                if ($day !== null) {
                    for ($month = $from->startOfMonth(); $month->lessThanOrEqualTo($to); $month = $month->addMonthNoOverflow()) {
                        $occurrences[] = ['key' => $month->format('Y-m'), 'date' => self::clamp($month->year, $month->month, $day)];
                    }
                }
                break;

            case ScheduleType::Quarterly:
                $months = array_map('intval', (array) ($schedule['months'] ?? []));

                if ($day !== null) {
                    for ($year = $from->year; $year <= $to->year; $year++) {
                        foreach ($months as $month) {
                            $occurrences[] = ['key' => sprintf('%d-%02d', $year, $month), 'date' => self::clamp($year, $month, $day)];
                        }
                    }
                }
                break;

            case ScheduleType::FixedDates:
                foreach ((array) ($schedule['dates'] ?? []) as $date) {
                    if (is_string($date)) {
                        $occurrences[] = ['key' => $date, 'date' => CarbonImmutable::parse($date)->startOfDay()];
                    }
                }
                break;

            case ScheduleType::AfterCreation:
                $days = self::int($schedule['days'] ?? null);

                if ($days !== null) {
                    $occurrences[] = ['key' => 'once', 'date' => $created->addDays($days)];
                }
                break;

            case ScheduleType::AfterFiscalYearEnd:
                $offsetMonths = self::int($schedule['offset_months'] ?? null);
                $days = self::int($schedule['days'] ?? null) ?? 0;
                [$endMonth, $endDay] = array_map('intval', explode('-', $company->fiscal_year_end));

                if ($offsetMonths !== null) {
                    // Clôtures possibles dont l'échéance peut tomber dans la période.
                    for ($year = $from->year - 3; $year <= $to->year; $year++) {
                        $closing = self::clamp($year, $endMonth, $endDay);

                        if ($closing->lessThan($created)) {
                            continue;
                        }

                        $due = $closing->day === $closing->daysInMonth
                            ? $closing->startOfMonth()->addMonthsNoOverflow($offsetMonths)->endOfMonth()->startOfDay()
                            : $closing->addMonthsNoOverflow($offsetMonths);

                        $occurrences[] = ['key' => 'FY'.$closing->toDateString(), 'date' => $due->addDays($days)];
                    }
                }
                break;

            case null:
                break;
        }

        return array_values(array_filter(
            $occurrences,
            fn (array $occurrence): bool => $occurrence['date']->betweenIncluded($from, $to),
        ));
    }

    /**
     * Date du jour demandé, ramenée au dernier jour d'un mois plus court.
     */
    public static function clamp(int $year, int $month, int $day): CarbonImmutable
    {
        $first = CarbonImmutable::create($year, $month, 1);

        return $first->setDay(min($day, $first->daysInMonth))->startOfDay();
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
