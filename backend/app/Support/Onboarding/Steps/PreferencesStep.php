<?php

namespace App\Support\Onboarding\Steps;

use App\Models\User;
use App\Support\Onboarding\OnboardingStep;

/**
 * Indiquer son pays (et vérifier sa langue et son fuseau horaire) dans PATCH /me : membres de
 * la plateforme.
 */
final class PreferencesStep implements OnboardingStep
{
    public function roles(): array
    {
        return ['particulier', 'prestataire', 'entrepreneur'];
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
        return $user->country !== null;
    }

    public function label(string $locale): string
    {
        return $locale === 'en' ? 'Set your country, language and time zone' : 'Indiquer votre pays, votre langue et votre fuseau horaire';
    }
}
