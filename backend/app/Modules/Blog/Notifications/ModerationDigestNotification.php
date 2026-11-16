<?php

namespace App\Modules\Blog\Notifications;

use App\Models\User;
use App\Support\Locale;
use App\Support\Notifications\CategorizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Récapitulatif envoyé aux modérateurs du Blog (rédacteurs et admins avec la 2FA confirmée) :
 * nombre d'articles et de modifications en attente (au plus un email par période,
 * NotifyModerators). Aucun contenu, aucun titre : le détail se lit dans la modération, avec la
 * double authentification.
 */
class ModerationDigestNotification extends Notification implements CategorizedNotification, ShouldQueue
{
    use Queueable;

    public function notificationCategory(): string
    {
        return 'blog.moderation_digest';
    }

    /**
     * @var array<string, array{subject: string, intro: string, action: string, salutation: string}>
     */
    private const TEXTS = [
        'fr' => [
            'subject' => 'Loopartner : articles à valider',
            'intro' => 'En attente de validation : :articles article(s) et :revisions modification(s) d\'articles publiés.',
            'action' => 'Ouvrir la modération du blog',
            'salutation' => 'Loopartner',
        ],
        'en' => [
            'subject' => 'Loopartner: articles awaiting review',
            'intro' => 'Awaiting review: :articles article(s) and :revisions change(s) to published articles.',
            'action' => 'Open blog moderation',
            'salutation' => 'Loopartner',
        ],
    ];

    /**
     * @param  array{article: int, revision: int}  $counts
     */
    public function __construct(public readonly array $counts) {}

    public static function sendTo(User $moderator, self $notification): void
    {
        $moderator->notify($notification->locale(self::localeOf($moderator)));
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
        $texts = self::TEXTS[self::localeOf($notifiable)];

        return (new MailMessage)
            ->subject($texts['subject'])
            ->line(strtr($texts['intro'], [
                ':articles' => (string) $this->counts['article'],
                ':revisions' => (string) $this->counts['revision'],
            ]))
            ->action($texts['action'], rtrim((string) config('app.frontend_url'), '/').'/admin/blog')
            ->salutation($texts['salutation']);
    }

    private static function localeOf(User $user): string
    {
        return in_array($user->locale, Locale::SUPPORTED, true) ? $user->locale : Locale::DEFAULT;
    }
}
