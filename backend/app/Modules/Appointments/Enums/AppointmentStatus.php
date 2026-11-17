<?php

namespace App\Modules\Appointments\Enums;

/**
 * Statut d'un rendez-vous (décision 55) :
 * - requested : demandé par le client, à confirmer par le prestataire ;
 * - proposed : proposé par le prestataire, à confirmer par le client ;
 * - confirmed : confirmé (« passé » se déduit de ends_at, sans statut ni tâche) ;
 * - declined, cancelled, expired : définitifs, le créneau est libéré.
 */
enum AppointmentStatus: string
{
    case Requested = 'requested';
    case Proposed = 'proposed';
    case Confirmed = 'confirmed';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * Le rendez-vous retient son créneau.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Requested, self::Proposed, self::Confirmed], true);
    }

    public function isPending(): bool
    {
        return $this === self::Requested || $this === self::Proposed;
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return [self::Requested->value, self::Proposed->value, self::Confirmed->value];
    }

    /**
     * @return list<string>
     */
    public static function closedValues(): array
    {
        return [self::Declined->value, self::Cancelled->value, self::Expired->value];
    }
}
