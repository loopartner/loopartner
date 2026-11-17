<?php

namespace App\Modules\Appointments\Policies;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountType;
use Illuminate\Auth\Access\Response;

/**
 * Calendrier, types de rendez-vous et disponibilités : réservés aux prestataires (403 pour les
 * autres rôles, admins compris). L'email vérifié est exigé par les routes. Les éléments d'un
 * autre prestataire donnent 404 (recherche limitée au calendrier de l'utilisateur connecté).
 */
class CalendarPolicy
{
    public function manage(User $user): Response
    {
        return $user->hasRole(AccountType::Prestataire->value)
            ? Response::allow()
            : Response::deny('Seuls les prestataires proposent des rendez-vous.');
    }
}
