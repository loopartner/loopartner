<?php

namespace App\Modules\Compliance\Support\Onboarding;

use App\Models\User;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Support\RuleCatalog;
use App\Support\Onboarding\OnboardingStep;

/**
 * Créer son entreprise dans le suivi administratif (facultatif) : seulement quand au moins un
 * pays est proposé (règles vérifiées par un professionnel) ; sinon l'étape n'apparaît pas.
 */
final class CompanyStep implements OnboardingStep
{
    public function roles(): array
    {
        return ['prestataire', 'entrepreneur'];
    }

    public function optional(): bool
    {
        return true;
    }

    public function applies(User $user): bool
    {
        return RuleCatalog::availableCountries((int) now()->year) !== [];
    }

    public function done(User $user): bool
    {
        return Company::query()->where('user_id', $user->id)->exists();
    }

    public function label(string $locale): string
    {
        return $locale === 'en' ? 'Set up your company in the administrative tracker' : 'Créer votre entreprise dans le suivi administratif';
    }
}
