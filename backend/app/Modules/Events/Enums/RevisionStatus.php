<?php

namespace App\Modules\Events\Enums;

/**
 * Statut d'une révision d'un événement publié.
 *
 * pending → approved (appliquée) | rejected (refusée par un admin) | superseded (retirée par
 * l'organisateur, ou devenue sans objet : événement annulé ou terminé).
 */
enum RevisionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
}
