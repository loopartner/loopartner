<?php

namespace App\Modules\Marketplace\Enums;

/**
 * Cycle de vie d'une offre : brouillon → publiée → dépubliée par son auteur (et republiable).
 * Le masquage par la modération ne change pas le statut (colonne moderation_restricted_at).
 */
enum OfferStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Unpublished = 'unpublished';
}
