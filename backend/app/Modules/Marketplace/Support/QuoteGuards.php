<?php

namespace App\Modules\Marketplace\Support;

use App\Models\User;
use App\Modules\Compliance\Actions\GetVatRates;
use App\Modules\Directory\Actions\GetMatchRequestContext;
use App\Modules\Marketplace\Enums\ClientKind;
use App\Modules\Marketplace\Exceptions\SellerProfileIncomplete;
use App\Modules\Marketplace\Exceptions\VatRatesUnavailable;
use App\Modules\Marketplace\Models\SellerProfile;
use Carbon\CarbonInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Conditions communes à la rédaction et à l'envoi d'un devis (décisions 36, 37 et 49) :
 *
 * - demande de mise en relation connue du prestataire (404 sinon), dont il est le prestataire
 *   (403 pour le porteur de projet), acceptée (409), sans blocage entre les deux (403), avec un
 *   client toujours inscrit (409) ;
 * - identité professionnelle complète, médiateur compris pour un consommateur (409
 *   seller_profile_incomplete), établie dans un pays où les devis sont proposés ;
 * - au moins un taux de TVA vérifié en vigueur (409 vat_rates_unavailable).
 *
 * @phpstan-import-type MatchRequestContext from GetMatchRequestContext
 * @phpstan-import-type VatRate from GetVatRates
 */
final class QuoteGuards
{
    public function __construct(
        private GetMatchRequestContext $matchRequests,
        private GetVatRates $vatRates,
    ) {}

    /**
     * @return MatchRequestContext
     */
    public function matchRequest(User $provider, ?string $uuid = null, ?int $id = null): array
    {
        $context = $uuid !== null ? $this->matchRequests->handle($uuid, $provider) : $this->matchRequests->forId((int) $id, $provider);

        if ($context === null) {
            throw new NotFoundHttpException('Demande de mise en relation introuvable.');
        }

        if ($context['provider_id'] !== $provider->id) {
            throw new AccessDeniedHttpException('Seul le prestataire de cette demande peut lui adresser un devis.');
        }

        if (! $context['accepted']) {
            throw new ConflictHttpException('Un devis s\'envoie une fois la demande de mise en relation acceptée.');
        }

        if ($context['blocked']) {
            throw new AccessDeniedHttpException('Vous ne pouvez pas adresser de devis à cet utilisateur.');
        }

        if ($context['client_id'] === null || ClientKind::fromRole($context['client_role']) === null) {
            throw new ConflictHttpException('Le porteur de ce projet n\'est plus inscrit.');
        }

        return $context;
    }

    public function seller(User $provider, ClientKind $clientKind): SellerProfile
    {
        $seller = SellerProfile::query()->where('user_id', $provider->id)->first();
        $missing = match (true) {
            $seller === null => ['seller_profile'],
            $clientKind === ClientKind::Consumer => $seller->missingForConsumerQuotes(),
            default => $seller->missingForQuotes(),
        };

        if ($missing !== [] || $seller === null) {
            throw new SellerProfileIncomplete($missing);
        }

        return $seller;
    }

    /**
     * Taux vérifiés en vigueur ce jour-là dans le pays du prestataire, indexés par code.
     *
     * @return array<string, VatRate>
     */
    public function vatRates(SellerProfile $seller, CarbonInterface $day): array
    {
        $rates = [];

        foreach ($this->vatRates->handle($seller->country, $day) as $rate) {
            $rates[$rate['code']] = $rate;
        }

        if ($rates === []) {
            throw new VatRatesUnavailable;
        }

        return $rates;
    }
}
