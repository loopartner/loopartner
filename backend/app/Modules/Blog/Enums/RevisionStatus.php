<?php

namespace App\Modules\Blog\Enums;

/**
 * Statut d'une révision d'un article publié. "superseded" : devenue sans objet (article
 * dépublié ou supprimé, ou modification annulée parce qu'elle ne change plus rien).
 */
enum RevisionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Superseded = 'superseded';
}
