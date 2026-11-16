<?php

namespace App\Modules\Events\Support;

use App\Models\User;
use App\Modules\Accounts\Actions\HasPublishedProviderProfile;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Contrôles communs aux décisions de modération. Le contrôle de version est celui du noyau
 * (`App\Support\Moderation\VersionGuard`).
 */
final class Moderation
{
    /**
     * L'organisateur peut-il toujours publier (compte existant, rôle, profil prestataire publié) ?
     *
     * @throws ConflictHttpException
     */
    public static function assertOrganizerEligible(?User $organizer): User
    {
        $eligible = $organizer !== null
            && Organizers::hasOrganizerRole($organizer)
            && (! Organizers::isProvider($organizer) || app(HasPublishedProviderProfile::class)->handle($organizer));

        if (! $eligible) {
            throw new ConflictHttpException('L\'organisateur ne peut plus publier d\'événement (compte, rôle ou profil prestataire).');
        }

        /** @var User $organizer */
        return $organizer;
    }
}
