<?php

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Enums\OfferStatus;
use App\Modules\Marketplace\Models\Offer;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Retire une offre publiée de la vue du public, à la demande de son auteur (republiable, même
 * slug). Une offre masquée par la modération le reste.
 */
class UnpublishOffer
{
    public function handle(Offer $offer): Offer
    {
        $offer = DB::transaction(function () use ($offer): Offer {
            $offer = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if ($offer->status !== OfferStatus::Published) {
                throw new ConflictHttpException('Seule une offre publiée peut être dépubliée.');
            }

            $offer->forceFill(['status' => OfferStatus::Unpublished, 'unpublished_at' => now()])->save();

            return $offer;
        });

        SecurityLog::record('marketplace.offer_unpublished', ['offer' => $offer->uuid]);

        return $offer;
    }
}
