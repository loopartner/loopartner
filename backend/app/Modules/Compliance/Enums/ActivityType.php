<?php

namespace App\Modules\Compliance\Enums;

/**
 * Types d'activité, utiles au calcul des seuils et des cotisations. Le chiffre d'affaires est
 * saisi par type d'activité ; une activité mixte déclare deux types. Les règles indiquent
 * lesquels elles concernent (un seuil peut additionner plusieurs types).
 */
enum ActivityType: string
{
    // Vente de marchandises, fourniture de logement.
    case Sales = 'sales';

    // Prestations de services commerciales ou artisanales.
    case ServicesCommercial = 'services_commercial';

    // Professions libérales relevant du régime général.
    case ServicesLiberal = 'services_liberal';

    // Professions libérales relevant d'une caisse de retraite propre (CIPAV en France).
    case LiberalCipav = 'liberal_cipav';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
