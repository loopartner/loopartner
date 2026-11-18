<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Choix d'un utilisateur dans son parcours d'accueil (noyau) : étapes facultatives passées,
 * parcours masqué, date à laquelle toutes les étapes obligatoires ont été faites. Écrit
 * seulement par App\Support\Onboarding\Onboarding ; supprimé avec le compte.
 *
 * @property int $id
 * @property int $user_id
 * @property list<string>|null $skipped_steps
 * @property Carbon|null $dismissed_at
 * @property Carbon|null $completed_at
 */
class OnboardingState extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'skipped_steps' => 'array',
            'dismissed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
