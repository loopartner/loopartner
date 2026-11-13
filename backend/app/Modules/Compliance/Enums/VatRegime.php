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

    public function label(string $locale): string
    {
        return match ($this) {
            self::Franchise => $locale === 'en' ? 'VAT exemption (no VAT charged)' : 'Franchise en base de TVA',
            self::Simplified => $locale === 'en' ? 'Simplified VAT scheme' : 'Régime réel simplifié',
            self::Normal => $locale === 'en' ? 'Standard VAT scheme' : 'Régime réel normal',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
