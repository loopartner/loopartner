<?php

namespace App\Modules\Appointments\Support\Onboarding;

use App\Models\User;
use App\Modules\Appointments\Models\AvailabilityRule;
use App\Modules\Appointments\Models\Calendar;
use App\Support\Onboarding\OnboardingStep;

/**
 * Ouvrir ses disponibilités pour les rendez-vous (calendrier ouvert avec au moins une plage ;
 * facultatif).
 */
final class AvailabilityStep implements OnboardingStep
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
        $calendar = Calendar::query()->where('user_id', $user->id)->where('is_enabled', true)->first();

        return $calendar !== null && AvailabilityRule::query()->where('calendar_id', $calendar->id)->exists();
    }

    public function label(string $locale): string
    {
        return $locale === 'en' ? 'Open your availability for appointments' : 'Ouvrir vos disponibilités pour les rendez-vous';
    }
}
