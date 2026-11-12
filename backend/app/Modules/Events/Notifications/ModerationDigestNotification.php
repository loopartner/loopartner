<?php

namespace App\Modules\Events\Notifications;

use App\Models\User;
use App\Support\Locale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Récapitulatif envoyé aux admins : nombre d'événements, de modifications et d'annonces en
 * attente de validation (au plus un email par période, NotifyModerators). Aucun contenu, aucun
 * titre : le détail se lit dans la modération, avec la double authentification.
 */
class ModerationDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @var array<string, array{subject: string, intro: string, action: string, salutation: string}>
     */
    private const TEXTS = [
        'fr' => [
            'subject' => 'Loopartner : éléments à valider',
            'intro' => 'En attente de validation : :events événement(s), :revisions modification(s) d\'événements publiés et :announcements annonce(s) aux inscrits.',
            'action' => 'Ouvrir la modération',
            'salutation' => 'Loopartner',
        ],
        'en' => [
            'subject' => 'Loopartner: items awaiting review',
            'intro' => 'Awaiting review: :events event(s), :revisions change(s) to published events and :announcements announcement(s) to attendees.',
            'action' => 'Open moderation',
            'salutation' => 'Loopartner',
        ],
    ];

    /**
     * @param  array{event: int, revision: int, announcement: int}  $counts
     */
    public function __construct(public readonly array $counts) {}

    public static function sendTo(User $admin, self $notification): void
    {
        $locale = in_array($admin->locale, Locale::SUPPORTED, true) ? $admin->locale : Locale::DEFAULT;

        $admin->notify($notification->locale($locale));
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $texts = self::TEXTS[in_array($notifiable->locale, Locale::SUPPORTED, true) ? $notifiable->locale : Locale::DEFAULT];

        return (new MailMessage)
            ->subject($texts['subject'])
            ->line(strtr($texts['intro'], [
                ':events' => (string) $this->counts['event'],
                ':revisions' => (string) $this->counts['revision'],
                ':announcements' => (string) $this->counts['announcement'],
            ]))
            ->action($texts['action'], rtrim((string) config('app.frontend_url'), '/').'/admin/moderation')
            ->salutation($texts['salutation']);
    }
}
