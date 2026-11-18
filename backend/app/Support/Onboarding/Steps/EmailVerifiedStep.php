<?php

namespace App\Support\Onboarding\Steps;

use App\Models\User;
use App\Support\Onboarding\OnboardingStep;

/**
 * Confirmer son adresse email (tous les rôles).
 */
final class EmailVerifiedStep implements OnboardingStep
{
    public function roles(): array
    {
        return ['particulier', 'prestataire', 'entrepreneur', 'redacteur', 'admin'];
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
        return $user->hasVerifiedEmail();
    }

    public function label(string $locale): string
    {
        return $locale === 'en' ? 'Confirm your email address' : 'Confirmer votre adresse email';
    }
}
