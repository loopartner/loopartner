<?php

namespace App\Modules\Directory\Notifications;

use App\Models\User;
use App\Support\Locale;
use App\Support\Notifications\CategorizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email de la mise en relation, envoyé par la file d'attente après la validation de la
 * transaction, dans la langue de son destinataire (users.locale, fr par défaut).
 *
 * Confidentialité : un email ne contient jamais le contenu d'un message ou d'une demande, ni le
 * nom d'un utilisateur, ni le titre du projet de quelqu'un d'autre. Il annonce l'événement et
 * renvoie vers le frontend, où l'utilisateur doit être connecté. Les modèles passent dans la
 * file d'attente par leur seul identifiant (SerializesModels) : le texte des messages n'est
 * jamais copié dans la table jobs. Si le modèle a disparu entre-temps, l'email est abandonné.
 */
abstract class MatchingNotification extends Notification implements CategorizedNotification, ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * Demandes reçues, acceptées ou refusées ; NewMessageNotification a sa propre catégorie.
     */
    public function notificationCategory(): string
    {
        return 'directory.match_requests';
    }

    public bool $deleteWhenMissingModels = true;

    /**
     * @var array<string, array{greeting: string, footer: string, salutation: string}>
     */
    private const COMMON = [
        'fr' => [
            'greeting' => 'Bonjour,',
            'footer' => 'Pour votre sécurité, ne communiquez jamais vos coordonnées bancaires ni vos mots de passe dans la messagerie.',
            'salutation' => 'L\'équipe Loopartner',
        ],
        'en' => [
            'greeting' => 'Hello,',
            'footer' => 'For your safety, never share your bank details or passwords in the messaging.',
            'salutation' => 'The Loopartner team',
        ],
    ];

    /**
     * Envoie la notification dans la langue du destinataire (textes du gabarit Laravel compris).
     */
    public static function sendTo(User $recipient, self $notification): void
    {
        $recipient->notify($notification->locale(self::localeOf($recipient)));
    }

    /**
     * @return array{subject: string, intro: string, action: string}
     */
    abstract protected function texts(User $notifiable, string $locale): array;

    /**
     * Chemin de la page du frontend, commençant par "/".
     */
    abstract protected function path(): string;

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $locale = self::localeOf($notifiable);
        $texts = $this->texts($notifiable, $locale);
        $common = self::COMMON[$locale];

        return (new MailMessage)
            ->subject($texts['subject'])
            ->greeting($common['greeting'])
            ->line($texts['intro'])
            ->action($texts['action'], rtrim((string) config('app.frontend_url'), '/').$this->path())
            ->line($common['footer'])
            ->salutation($common['salutation']);
    }

    private static function localeOf(User $user): string
    {
        return in_array($user->locale, Locale::SUPPORTED, true) ? $user->locale : Locale::DEFAULT;
    }
}
