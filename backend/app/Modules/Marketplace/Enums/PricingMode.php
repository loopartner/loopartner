<?php

namespace App\Modules\Marketplace\Enums;

/**
 * Présentation du prix indicatif d'une offre (décision 40 : saisi TTC, seul le devis engage).
 * « Sur devis » n'a pas de prix.
 */
enum PricingMode: string
{
    case Fixed = 'fixed';
    case From = 'from';
    case Hourly = 'hourly';
    case Daily = 'daily';
    case OnQuote = 'on_quote';

    public function hasPrice(): bool
    {
        return $this !== self::OnQuote;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
