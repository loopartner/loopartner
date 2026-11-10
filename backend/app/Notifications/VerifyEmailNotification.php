<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Lien de vérification de l'adresse email, envoyé par la file d'attente.
 *
 * La file d'attente rend le temps de réponse de l'inscription indépendant de l'envoi de
 * l'email : il ne trahit pas si l'adresse correspondait déjà à un compte. Le lien est signé,
 * expire après auth.verification.expire minutes (60 par défaut) et pointe vers la route
 * verification.verify du module Accounts, utilisable sans être connecté.
 */
class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;
}
