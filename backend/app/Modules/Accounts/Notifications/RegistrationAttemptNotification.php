<?php

namespace App\Modules\Accounts\Notifications;

use App\Models\User;
use App\Support\Notifications\CategorizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Prévient le titulaire d'une adresse qu'une inscription a été tentée avec elle.
 *
 * L'inscription répond de la même façon que l'adresse soit libre ou non (pas d'énumération
 * des comptes) : c'est cet email, envoyé par la file d'attente, qui informe le vrai titulaire.
 * Il ne contient aucune donnée saisie lors de la tentative.
 */
class RegistrationAttemptNotification extends Notification implements CategorizedNotification, ShouldQueue
{
    use Queueable;

    public function notificationCategory(): string
    {
        return 'account.security';
    }

    /**
     * @var array<string, array{subject: string, greeting: string, intro: string, action: string, reset: string, ignore: string}>
     */
    private const TEXTS = [
        'fr' => [
            'subject' => 'Tentative d\'inscription avec votre adresse',
            'greeting' => 'Bonjour,',
            'intro' => 'Quelqu\'un a essayé de créer un compte Loopartner avec votre adresse email, qui est déjà associée à un compte.',
            'action' => 'Me connecter',
            'reset' => 'Si c\'est vous et que vous avez oublié votre mot de passe, réinitialisez-le ici : :url',
            'ignore' => 'Si ce n\'est pas vous, vous pouvez ignorer cet email : votre compte n\'a pas été modifié.',
        ],
        'en' => [
            'subject' => 'Sign-up attempt with your email address',
            'greeting' => 'Hello,',
            'intro' => 'Someone tried to create a Loopartner account with your email address, which is already linked to an account.',
            'action' => 'Log in',
            'reset' => 'If this was you and you forgot your password, reset it here: :url',
            'ignore' => 'If this was not you, you can ignore this email: your account has not been changed.',
        ],
    ];

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $texts = self::TEXTS[$notifiable->locale] ?? self::TEXTS['fr'];
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return (new MailMessage)
            ->subject($texts['subject'])
            ->greeting($texts['greeting'])
            ->line($texts['intro'])
            ->action($texts['action'], $frontend.'/login')
            ->line(str_replace(':url', $frontend.'/forgot-password', $texts['reset']))
            ->line($texts['ignore']);
    }
}
