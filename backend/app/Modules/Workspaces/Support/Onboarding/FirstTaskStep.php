<?php

namespace App\Modules\Workspaces\Support\Onboarding;

use App\Models\User;
use App\Modules\Workspaces\Models\Task;
use App\Support\Onboarding\OnboardingStep;

/**
 * Ajouter sa première tâche dans un workspace (saisie, modèle ou copilote) : fin du parcours
 * des porteurs de projet (critère d'acceptation du lot 5).
 */
final class FirstTaskStep implements OnboardingStep
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
        return Task::query()->whereHas('workspace', fn ($query) => $query->where('user_id', $user->id))->exists();
    }

    public function label(string $locale): string
    {
        return $locale === 'en' ? 'Add your first task' : 'Ajouter votre première tâche';
    }
}
