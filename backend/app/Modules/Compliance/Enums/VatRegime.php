<?php

namespace App\Modules\Compliance\Enums;

/**
 * Régimes de TVA. Les régimes possibles pour un statut viennent de sa règle (legal_form).
 */
enum VatRegime: string
{
    // Franchise en base : pas de TVA facturée.
    case Franchise = 'franchise';

    // Régime réel simplifié.
    case Simplified = 'simplified';

    // Régime réel normal.
    case Normal = 'normal';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
