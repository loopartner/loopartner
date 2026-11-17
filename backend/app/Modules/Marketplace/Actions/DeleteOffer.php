<?php

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Models\Offer;
use App\Support\SecurityLog;

/**
 * Supprime une offre, à tout statut (aucun fichier rattaché). Les devis qui la citaient
 * gardent leur copie du titre.
 */
class DeleteOffer
{
    public function handle(Offer $offer): void
    {
        $uuid = $offer->uuid;
        $offer->delete();

        SecurityLog::record('marketplace.offer_deleted', ['offer' => $uuid]);
    }
}
