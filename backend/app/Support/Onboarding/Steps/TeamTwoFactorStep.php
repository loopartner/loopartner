<?php

namespace App\Support\Onboarding\Steps;

use App\Models\User;
use App\Support\Onboarding\OnboardingStep;

/**
 * Activer et confirmer la double authentification : obligatoire pour l'équipe (décision 17).
 */
final class TeamTwoFactorStep implements OnboardingStep
{
    public function roles(): array
    {
        return ['redacteur', 'admin'];
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
        return $user->two_factor_confirmed_at !== null;
    }

    public function label(string $locale): string
    {
        return $locale === 'en' ? 'Turn on two-factor authentication' : 'Activer la double authentification';
    }
}
