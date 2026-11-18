<?php

namespace App\Modules\Accounts\Support\Onboarding;

use App\Models\User;
use App\Modules\Accounts\Actions\HasPublishedProviderProfile;
use App\Support\Onboarding\OnboardingStep;

/**
 * Publier son profil de prestataire avec au moins une catégorie.
 */
final class ProviderProfileStep implements OnboardingStep
{
    public function __construct(private HasPublishedProviderProfile $hasPublishedProviderProfile) {}

    public function roles(): array
    {
        return ['prestataire'];
    }

    public function optional(): bool
    {
        return false;
    }

    public function applies(User $user): bool
    {
        return true;
    }

    public function done(User $user): bool
    {
        return $this->hasPublishedProviderProfile->handle($user);
    }

    public function label(string $locale): string
    {
        return $locale === 'en' ? 'Publish your provider profile with at least one category' : 'Publier votre profil de prestataire avec au moins une catégorie';
    }
}
