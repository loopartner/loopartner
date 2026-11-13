<?php

namespace App\Modules\Compliance\Enums;

/**
 * Formes de récurrence d'une échéance (payload.schedule d'une règle deadline). Les mois, jours
 * et dates viennent de la règle ; le code ne fait que les dérouler (DeadlineSchedule).
 */
enum ScheduleType: string
{
    // Chaque année : month, day.
    case Yearly = 'yearly';

    // Chaque mois : day (ramené au dernier jour d'un mois plus court).
    case Monthly = 'monthly';

    // Certains mois de l'année : months (liste), day.
    case Quarterly = 'quarterly';

    // Dates explicites (échéance qui change chaque année) : dates.
    case FixedDates = 'fixed_dates';

    // Une fois, N jours après la création de l'entreprise : days.
    case AfterCreation = 'after_creation';

    // Chaque exercice : N mois et N jours après la date de clôture : months, days.
    case AfterFiscalYearEnd = 'after_fiscal_year_end';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
