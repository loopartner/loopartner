<?php

namespace App\Modules\Marketplace\Support\Onboarding;

use App\Models\User;
use App\Modules\Marketplace\Models\SellerProfile;
use App\Support\Onboarding\OnboardingStep;

/**
 * Renseigner son identité professionnelle (nécessaire pour publier des offres et envoyer des
 * devis ; facultatif dans le parcours).
 */
final class SellerProfileStep implements OnboardingStep
{
    public function roles(): array
    {
        return ['prestataire'];
    }

    public function optional(): bool
    {
        return true;
    }

    public function applies(User $user): bool
    {
        return true;
    }

    public function done(User $user): bool
    {
        $profile = SellerProfile::query()->where('user_id', $user->id)->first();

        return $profile !== null && $profile->missingForOffers() === [];
    }

    public function label(string $locale): string
    {
        return $locale === 'en' ? 'Fill in your professional identity (offers and quotes)' : 'Renseigner votre identité professionnelle (offres et devis)';
    }
}
