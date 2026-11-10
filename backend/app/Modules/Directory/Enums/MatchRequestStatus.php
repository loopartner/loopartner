<?php

namespace App\Modules\Directory\Enums;

/**
 * Statut d'une demande de mise en relation. Seule une demande en attente change de statut :
 * les autres statuts sont définitifs.
 */
enum MatchRequestStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';

    /**
     * Statuts qui comptent comme "demande active" (au plus une par couple).
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Pending, self::Accepted];
    }

    /**
     * Statuts définitifs sans conversation : supprimés après la durée de conservation.
     *
     * @return list<string>
     */
    public static function closedValues(): array
    {
        return [self::Declined->value, self::Withdrawn->value, self::Expired->value];
    }
}
