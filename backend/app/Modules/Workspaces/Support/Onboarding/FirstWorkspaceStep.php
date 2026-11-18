<?php

namespace App\Modules\Workspaces\Support\Onboarding;

use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use App\Support\Onboarding\OnboardingStep;

/**
 * Créer son premier workspace (particulier, entrepreneur).
 */
final class FirstWorkspaceStep implements OnboardingStep
{
    public function roles(): array
    {
        return ['particulier', 'entrepreneur'];
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
        return Workspace::query()->where('user_id', $user->id)->exists();
    }

    public function label(string $locale): string
    {
        return $locale === 'en' ? 'Create your first project workspace' : 'Créer votre premier workspace de projet';
    }
}
