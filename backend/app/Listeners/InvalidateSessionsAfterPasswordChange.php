<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\UserSessions;
use Illuminate\Auth\Events\PasswordReset;
use Laravel\Fortify\Events\PasswordUpdatedViaController;

/**
 * Déconnecte les autres appareils quand le mot de passe change (ASVS 3.3.3).
 *
 * - Réinitialisation (mot de passe oublié) : toutes les sessions de l'utilisateur sont supprimées.
 * - Changement depuis le compte : toutes sauf la session courante.
 *
 * Listener découvert automatiquement (dossier app/Listeners).
 */
class InvalidateSessionsAfterPasswordChange
{
    public function handlePasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            UserSessions::delete($event->user);
        }
    }

    public function handlePasswordUpdated(PasswordUpdatedViaController $event): void
    {
        $request = request();

        UserSessions::delete($event->user, $request->hasSession() ? $request->session()->getId() : null);
    }
}
