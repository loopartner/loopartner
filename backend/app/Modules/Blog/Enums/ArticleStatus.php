<?php

namespace App\Modules\Blog\Enums;

/**
 * Cycle de vie d'un article.
 *
 * draft → pending_review → published ; pending_review → rejected (motif) ; pending_review →
 * draft (retrait par l'auteur) ; published → unpublished (dépublication par la modération,
 * motif). Un article refusé ou dépublié se corrige puis se soumet à nouveau. L'équipe
 * (rédacteurs, admins) publie ses propres articles directement : draft → published.
 */
enum ArticleStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Published = 'published';
    case Rejected = 'rejected';
    case Unpublished = 'unpublished';

    /**
     * Modifiable directement par son auteur (sans révision).
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected, self::Unpublished], true);
    }
}
