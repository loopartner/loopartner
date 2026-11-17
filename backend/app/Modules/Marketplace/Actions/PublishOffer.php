<?php

namespace App\Modules\Marketplace\Actions;

use App\Models\User;
use App\Modules\Marketplace\Enums\OfferStatus;
use App\Modules\Marketplace\Exceptions\SellerProfileIncomplete;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Marketplace\Models\SellerProfile;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Publie une offre (brouillon ou dépubliée), sans validation préalable (décision 39).
 *
 * - Identité professionnelle complète et autocertification à jour exigées (409
 *   seller_profile_incomplete) : l'offre affiche la qualité déclarée du prestataire.
 * - Au plus marketplace.offers.max_published offres publiées (409), compté sous verrou de la
 *   ligne users.
 * - Slug calculé à la première publication, puis stable.
 */
class PublishOffer
{
    private const SLUG_BASE_LENGTH = 80;

    public function handle(User $user, Offer $offer): Offer
    {
        $seller = SellerProfile::query()->where('user_id', $user->id)->first();
        $missing = $seller === null ? ['seller_profile'] : $seller->missingForOffers();

        if ($missing !== []) {
            throw new SellerProfileIncomplete($missing);
        }

        $offer = DB::transaction(function () use ($user, $offer): Offer {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $offer = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if ($offer->status === OfferStatus::Published) {
                throw new ConflictHttpException('Cette offre est déjà publiée.');
            }

            $published = Offer::query()->where('user_id', $user->id)->where('status', OfferStatus::Published->value)->count();

            if ($published >= (int) config('marketplace.offers.max_published')) {
                throw new ConflictHttpException('Vous avez atteint le nombre maximal d\'offres publiées. Dépubliez une offre avant d\'en publier une autre.');
            }

            $offer->forceFill([
                'status' => OfferStatus::Published,
                'slug' => $offer->slug ?? self::uniqueSlug($offer->title),
                'published_at' => now(),
                'unpublished_at' => null,
            ])->save();

            return $offer;
        });

        SecurityLog::record('marketplace.offer_published', ['offer' => $offer->uuid]);

        return $offer;
    }

    private static function uniqueSlug(string $title): string
    {
        $base = trim(Str::substr(Str::slug($title), 0, self::SLUG_BASE_LENGTH), '-');
        $base = $base === '' ? 'offre' : $base;

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (Offer::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
