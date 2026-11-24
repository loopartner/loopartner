<?php

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Models\Offer;
use App\Support\Media\MediaLibrary;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * Supprime une offre, à tout statut, avec son image de couverture et le fichier de celle-ci
 * (MediaLibrary). Les devis qui la citaient gardent leur copie du titre.
 */
class DeleteOffer
{
    public function __construct(private MediaLibrary $library) {}

    public function handle(Offer $offer): void
    {
        $uuid = $offer->uuid;

        DB::transaction(function () use ($offer): void {
            $image = $offer->image;
            $offer->delete();

            if ($image !== null) {
                $this->library->delete($image);
            }
        });

        SecurityLog::record('marketplace.offer_deleted', ['offer' => $uuid]);
    }
}
