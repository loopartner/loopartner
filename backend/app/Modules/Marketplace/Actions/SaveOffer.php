<?php

namespace App\Modules\Marketplace\Actions;

use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Marketplace\Enums\PricingMode;
use App\Modules\Marketplace\Models\Offer;
use App\Support\Media\MediaLibrary;
use App\Support\Media\MediaPurpose;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Crée une offre (toujours en brouillon) ou modifie une offre, à tout statut. Une offre
 * publiée est modifiée directement (décision 39) : sa version augmente.
 *
 * Plafond : marketplace.offers.max_total offres par prestataire (409), compté sous verrou de
 * la ligne users. L'image de couverture (« image », décision 83) est rattachée par
 * MediaLibrary::replace(), qui supprime l'ancienne avec son fichier.
 */
class SaveOffer
{
    public function __construct(private MediaLibrary $library) {}

    /**
     * @param  array<string, mixed>  $data  données validées
     */
    public function create(User $user, array $data): Offer
    {
        return DB::transaction(function () use ($user, $data): Offer {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if (Offer::query()->where('user_id', $user->id)->count() >= (int) config('marketplace.offers.max_total')) {
                throw new ConflictHttpException('Vous avez atteint le nombre maximal d\'offres. Supprimez une offre avant d\'en créer une autre.');
            }

            $offer = new Offer;
            $offer->fill(self::content($data))->forceFill([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'service_category_id' => self::categoryId((string) $data['category']),
            ]);

            if (array_key_exists('image', $data)) {
                $offer->image_media_id = $this->library->replace($user, MediaPurpose::OfferImage, $data['image'], null, 'image');
            }

            $offer->save();

            return $offer;
        });
    }

    /**
     * @param  array<string, mixed>  $data  données validées (modification partielle)
     */
    public function update(Offer $offer, array $data): Offer
    {
        return DB::transaction(function () use ($offer, $data): Offer {
            $offer = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $offer->fill(self::content($data));

            if (array_key_exists('category', $data)) {
                $offer->service_category_id = self::categoryId((string) $data['category']);
            }

            if (array_key_exists('image', $data)) {
                /** @var User $owner */
                $owner = $offer->user()->firstOrFail();
                $offer->image_media_id = $this->library->replace($owner, MediaPurpose::OfferImage, $data['image'], $offer->image_media_id, 'image');
            }

            // Une offre passée « sur devis » perd son prix indicatif.
            if ($offer->pricing_mode === PricingMode::OnQuote) {
                $offer->price_cents = null;
            }

            if ($offer->isDirty()) {
                $offer->version++;
                $offer->save();
            }

            return $offer;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function content(array $data): array
    {
        $content = array_intersect_key($data, array_flip((new Offer)->getFillable()));

        if (($data['pricing_mode'] ?? null) === PricingMode::OnQuote->value) {
            $content['price_cents'] = null;
        }

        return $content;
    }

    private static function categoryId(string $slug): int
    {
        return (int) ServiceCategory::query()->where('slug', $slug)->value('id');
    }
}
