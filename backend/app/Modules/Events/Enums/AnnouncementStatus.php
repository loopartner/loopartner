<?php

namespace App\Modules\Events\Enums;

/**
 * Statut d'une annonce aux inscrits : publiée tout de suite, ou en attente de validation si elle
 * contient un lien ou un nom de domaine.
 */
enum AnnouncementStatus: string
{
    case PendingReview = 'pending_review';
    case Published = 'published';
    case Rejected = 'rejected';
}
