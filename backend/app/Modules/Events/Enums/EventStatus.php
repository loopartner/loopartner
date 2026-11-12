<?php

namespace App\Modules\Events\Enums;

/**
 * Statut d'un événement.
 *
 * draft → pending_review → published → past (une fois terminé) ; pending_review → rejected
 * (corrigé puis soumis à nouveau) ; published → cancelled. Un événement créé par un admin passe
 * directement de draft à published.
 */
enum EventStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Published = 'published';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Past = 'past';

    /**
     * Statuts dans lesquels l'organisateur modifie directement son événement.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Rejected;
    }
}
