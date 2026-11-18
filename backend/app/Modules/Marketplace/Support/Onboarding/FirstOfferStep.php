<?php

namespace App\Modules\Marketplace\Support\Onboarding;

use App\Models\User;
use App\Modules\Marketplace\Models\Offer;
use App\Support\Onboarding\OnboardingStep;

/**
 * Publier une première offre (facultatif).
 */
final class FirstOfferStep implements OnboardingStep
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
        return Offer::query()->where('user_id', $user->id)->whereNotNull('published_at')->exists();
    }

    public function label(string $locale): string
    {
        return $locale === 'en' ? 'Publish your first offer' : 'Publier votre première offre';
    }
}
