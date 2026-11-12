<?php

namespace App\Modules\Events\Support;

use App\Models\User;
use App\Modules\Accounts\Actions\HasPublishedProviderProfile;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Contrôles communs aux décisions de modération.
 */
final class Moderation
{
    /**
     * L'admin décide sur la version qu'il a vue : si l'organisateur a modifié l'élément depuis,
     * la décision est refusée (409) et l'admin doit relire.
     *
     * @throws ConflictHttpException
     */
    public static function assertVersion(int $expected, int $actual): void
    {
        if ($expected !== $actual) {
            throw new ConflictHttpException('Cet élément a été modifié depuis votre lecture : rechargez-le avant de décider.');
        }
    }

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
