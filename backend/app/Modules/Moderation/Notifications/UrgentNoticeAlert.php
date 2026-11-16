<?php

namespace App\Modules\Moderation\Notifications;

use App\Models\User;
use App\Modules\Moderation\Support\Categories;
use App\Support\Locale;
use App\Support\Notifications\CategorizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerte immédiate aux admins (2FA confirmée) sur un signalement urgent (abus sexuels sur
 * mineurs, incitation à la violence ou terrorisme) : la catégorie, l'objectif de traitement et
 * un lien vers la modération, **sans aucun contenu** (ni texte, ni titre, ni lien vers le
 * contenu, ni identité). Une alerte par contenu signalé tant qu'il est en cours d'examen.
 */
class UrgentNoticeAlert extends Notification implements CategorizedNotification, ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * @var array<string, array{subject: string, intro: string, target: string, action: string}>
     */
    private const TEXTS = [
        'fr' => [
            'subject' => 'Loopartner : signalement urgent à traiter',
            'intro' => 'Un signalement urgent vient d\'être reçu (catégorie : :category).',
            'target' => 'Objectif de traitement : :hours heures. Il est en tête de la file de modération.',
            'action' => 'Ouvrir la modération',
        ],
        'en' => [
            'subject' => 'Loopartner: urgent report to handle',
            'intro' => 'An urgent report has just been received (category: :category).',
            'target' => 'Processing target: :hours hours. It is at the top of the moderation queue.',
            'action' => 'Open moderation',
        ],
    ];

    public function __construct(public readonly string $category, public readonly int $hours) {}

    public static function sendTo(User $admin, self $alert): void
    {
        $admin->notify($alert->locale(in_array($admin->locale, Locale::SUPPORTED, true) ? $admin->locale : Locale::DEFAULT));
    }

    public function notificationCategory(): string
    {
        return 'moderation.urgent_alerts';
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
        $locale = in_array($notifiable->locale, Locale::SUPPORTED, true) ? $notifiable->locale : Locale::DEFAULT;
        $texts = self::TEXTS[$locale];

        return (new MailMessage)
            ->subject($texts['subject'])
            ->line(strtr($texts['intro'], [':category' => Categories::label($this->category, $locale)]))
            ->line(strtr($texts['target'], [':hours' => (string) $this->hours]))
            ->action($texts['action'], rtrim((string) config('app.frontend_url'), '/').'/admin/moderation')
            ->salutation('Loopartner');
    }
}
