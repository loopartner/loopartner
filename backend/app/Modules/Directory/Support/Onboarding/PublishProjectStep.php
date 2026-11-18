<?php

namespace App\Modules\Directory\Support\Onboarding;

use App\Models\User;
use App\Modules\Directory\Models\Project;
use App\Support\Onboarding\OnboardingStep;

/**
 * Publier un projet pour recevoir des propositions de prestataires (facultatif).
 */
final class PublishProjectStep implements OnboardingStep
{
    public function roles(): array
    {
        return ['particulier', 'entrepreneur'];
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
        return Project::query()->where('user_id', $user->id)->whereNotNull('published_at')->exists();
    }

    public function label(string $locale): string
    {
        return $locale === 'en' ? 'Publish a project to receive proposals' : 'Publier un projet pour recevoir des propositions';
    }
}
