<?php

namespace App\Modules\Appointments\Enums;

/**
 * Partie d'un rendez-vous : le client (porteur de projet) ou le prestataire.
 */
enum Party: string
{
    case Client = 'client';
    case Provider = 'provider';

    public function other(): self
    {
        return $this === self::Client ? self::Provider : self::Client;
    }
}
