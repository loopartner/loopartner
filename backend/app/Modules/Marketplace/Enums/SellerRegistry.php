<?php

namespace App\Modules\Marketplace\Enums;

/**
 * Registre d'immatriculation (Code de commerce R123-237 : « RCS » suivi de la ville du greffe ;
 * registre national des entreprises).
 */
enum SellerRegistry: string
{
    case Rcs = 'rcs';
    case Rne = 'rne';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
