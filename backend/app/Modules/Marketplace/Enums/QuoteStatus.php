<?php

namespace App\Modules\Marketplace\Enums;

/**
 * Cycle de vie d'un devis : brouillon (vu du seul prestataire) → envoyé → accepté, refusé,
 * retiré par le prestataire, expiré (fin de validité) ou remplacé par une nouvelle version.
 * Un devis accepté par un consommateur peut ensuite faire l'objet d'une rétractation
 * (withdrawal_exercised_at, le statut reste « accepté »).
 */
enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';
    case Superseded = 'superseded';

    /**
     * Statuts définitifs, purgés après la durée de conservation (décision 47).
     *
     * @return list<string>
     */
    public static function closedValues(): array
    {
        return [self::Declined->value, self::Withdrawn->value, self::Expired->value, self::Superseded->value];
    }
}
