<?php

namespace App\Modules\Marketplace\Policies;

use App\Models\User;
use App\Modules\Accounts\Actions\HasPublishedProviderProfile;
use App\Modules\Accounts\Enums\AccountType;
use App\Modules\Marketplace\Models\Offer;
use Illuminate\Auth\Access\Response;

/**
 * Offres : seul un prestataire gère les siennes (/me/offers). L'offre d'un autre utilisateur
 * donne 404, admins compris : la modération passe par /admin/moderation.
 */
class OfferPolicy
{
    private const NOT_FOUND = 'Offre introuvable.';

    public function __construct(private HasPublishedProviderProfile $hasPublishedProviderProfile) {}

    public function manage(User $user): bool
    {
        return $user->hasRole(AccountType::Prestataire->value);
    }

    public function view(User $user, Offer $offer): Response
    {
        return $this->manage($user) && $offer->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound(self::NOT_FOUND);
    }

    public function update(User $user, Offer $offer): Response
    {
        return $this->view($user, $offer);
    }

    public function delete(User $user, Offer $offer): Response
    {
        return $this->view($user, $offer);
    }

    public function unpublish(User $user, Offer $offer): Response
    {
        return $this->view($user, $offer);
    }

    /**
     * Publier : email vérifié et profil prestataire publié (avec une catégorie), en plus de la
     * propriété. L'identité professionnelle est vérifiée par PublishOffer (409).
     */
    public function publish(User $user, Offer $offer): Response
    {
        $ownership = $this->view($user, $offer);

        if ($ownership->denied()) {
            return $ownership;
        }

        if (! $user->hasVerifiedEmail()) {
            return Response::deny('Vous devez vérifier votre adresse email avant de publier une offre.');
        }

        return $this->hasPublishedProviderProfile->handle($user)
            ? Response::allow()
            : Response::deny('Publiez votre profil prestataire, avec au moins une catégorie, avant de publier une offre.');
    }
}
