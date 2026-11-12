<?php

namespace App\Modules\Events\Enums;

/**
 * Format d'un événement : en présentiel (lieu), en ligne (lien de connexion) ou hybride (les deux).
 */
enum EventFormat: string
{
    case InPerson = 'in_person';
    case Online = 'online';
    case Hybrid = 'hybrid';

    public function hasVenue(): bool
    {
        return $this !== self::Online;
    }

    public function isOnline(): bool
    {
        return $this !== self::InPerson;
    }
}
