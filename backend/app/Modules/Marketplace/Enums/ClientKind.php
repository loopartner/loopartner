<?php

namespace App\Modules\Marketplace\Enums;

/**
 * Le client d'un devis : un consommateur (particulier) ou un professionnel (entrepreneur).
 * Un devis à un consommateur exige le médiateur et ouvre le droit de rétractation.
 */
enum ClientKind: string
{
    case Consumer = 'consumer';
    case Business = 'business';

    public static function fromRole(?string $role): ?self
    {
        return match ($role) {
            'particulier' => self::Consumer,
            'entrepreneur' => self::Business,
            default => null,
        };
    }
}
