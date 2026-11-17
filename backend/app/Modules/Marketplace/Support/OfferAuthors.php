<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Accounts\Actions\GetProviderSummaries;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Marketplace\Models\SellerProfile;
use Illuminate\Container\Attributes\Scoped;

/**
 * Présentation publique de l'auteur d'offres, chargée en deux requêtes pour toute une page :
 *
 * - provider : résumé public du profil prestataire (Action publique du module Accounts) ;
 * - seller : qualité déclarée (« professionnel ») et identité affichée sur l'offre,
 *   dénomination et SIREN seulement (décision 38, Code de la consommation D111-8). Jamais
 *   l'adresse, le téléphone ni l'email : ils n'apparaissent que sur les devis.
 *
 * @phpstan-import-type ProviderSummary from GetProviderSummaries
 *
 * @phpstan-type SellerCard array{declared_status: string, legal_name: string, trade_name: string|null, registration_number: string, country: string}
 * @phpstan-type AuthorCard array{provider: ProviderSummary|null, seller: SellerCard|null}
 *
 * Une instance par requête (#[Scoped]) : le contrôleur charge la page, puis chaque Resource
 * lit sa carte. Une offre absente du chargement est chargée seule.
 */
#[Scoped]
final class OfferAuthors
{
    /**
     * @var array<int, AuthorCard>
     */
    private array $cards = [];

    public function __construct(private GetProviderSummaries $summaries) {}

    /**
     * @param  iterable<Offer>  $offers
     */
    public function load(iterable $offers): self
    {
        $userIds = [];

        foreach ($offers as $offer) {
            $userIds[] = $offer->user_id;
        }

        $userIds = array_values(array_unique($userIds));
        $summaries = $this->summaries->handle($userIds);
        $sellers = SellerProfile::query()->whereIn('user_id', $userIds)->get()->keyBy('user_id');

        foreach ($userIds as $userId) {
            /** @var SellerProfile|null $seller */
            $seller = $sellers->get($userId);

            $this->cards[$userId] = [
                'provider' => $summaries[$userId] ?? null,
                'seller' => $seller === null ? null : self::sellerCard($seller),
            ];
        }

        return $this;
    }

    /**
     * @return AuthorCard
     */
    public function for(Offer $offer): array
    {
        if (! array_key_exists($offer->user_id, $this->cards)) {
            $this->load([$offer]);
        }

        return $this->cards[$offer->user_id];
    }

    /**
     * @return SellerCard
     */
    public static function sellerCard(SellerProfile $seller): array
    {
        return [
            // Statut déclaré par le prestataire (autocertification), pas vérifié au lot 3.
            'declared_status' => 'professional',
            'legal_name' => $seller->legal_name,
            'trade_name' => $seller->trade_name,
            'registration_number' => $seller->registration_number,
            'country' => $seller->country,
        ];
    }
}
