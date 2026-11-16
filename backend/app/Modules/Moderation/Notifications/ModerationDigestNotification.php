<?php

namespace App\Modules\Moderation\Notifications;

use App\Models\User;
use App\Support\Locale;
use App\Support\Notifications\CategorizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Récapitulatif envoyé aux admins : signalements en cours, urgents, en retard et recours en
 * attente (NotifyModerators). Aucun contenu, aucun titre, aucune identité.
 */
class ModerationDigestNotification extends Notification implements CategorizedNotification, ShouldQueue
{
    use Queueable;

    /**
     * @var array<string, array{subject: string, intro: string, action: string}>
     */
    private const TEXTS = [
        'fr' => [
            'subject' => 'Loopartner : signalements à traiter',
            'intro' => 'En cours : :open signalement(s), dont :urgent urgent(s) et :overdue en retard sur l\'objectif de traitement ; :appeals recours en attente.',
            'action' => 'Ouvrir la modération',
        ],
        'en' => [
            'subject' => 'Loopartner: reports to handle',
            'intro' => 'Open: :open report(s), including :urgent urgent and :overdue past their processing target; :appeals pending appeal(s).',
            'action' => 'Open moderation',
        ],
    ];

    /**
     * @param  array{open: int, urgent: int, overdue: int, appeals: int}  $counts
     */
    public function __construct(public readonly array $counts) {}

    public static function sendTo(User $admin, self $notification): void
    {
        $admin->notify($notification->locale(in_array($admin->locale, Locale::SUPPORTED, true) ? $admin->locale : Locale::DEFAULT));
    }

    public function notificationCategory(): string
    {
        return 'moderation.digest';
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
                ':open' => (string) $this->counts['open'],
                ':urgent' => (string) $this->counts['urgent'],
                ':overdue' => (string) $this->counts['overdue'],
                ':appeals' => (string) $this->counts['appeals'],
            ]))
            ->action($texts['action'], rtrim((string) config('app.frontend_url'), '/').'/admin/moderation')
            ->salutation('Loopartner');
    }
}
