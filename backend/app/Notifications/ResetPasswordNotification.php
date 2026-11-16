<?php

namespace App\Notifications;

use App\Support\Notifications\CategorizedNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Lien de réinitialisation du mot de passe, envoyé par la file d'attente.
 *
 * La demande (POST /auth/forgot-password) répond ainsi dans le même temps, que l'adresse
 * corresponde à un compte ou non. Le lien pointe vers le frontend (voir FortifyServiceProvider).
 */
class ResetPasswordNotification extends ResetPassword implements CategorizedNotification, ShouldQueue
{
    use Queueable;

    public function notificationCategory(): string
    {
        return 'account.security';
    }
}
