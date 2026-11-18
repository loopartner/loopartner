<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\Locale;
use App\Support\Notifications\CategorizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerte aux admins (2FA confirmée) quand la dépense d'IA du mois atteint un palier du budget
 * global (80 % puis 100 %, décision 62) : le mois et le pourcentage seulement, aucune donnée
 * d'utilisateur. Une alerte par palier et par mois (ai_budgets.alerted_level). Catégorie
 * obligatoire ai.budget_alerts.
 */
class AiBudgetAlert extends Notification implements CategorizedNotification, ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * @var array<string, array{subject: string, intro: string, full: string, partial: string, action: string}>
     */
    private const TEXTS = [
        'fr' => [
            'subject' => 'Loopartner : budget de l\'IA à :percent %',
            'intro' => 'La dépense d\'IA du mois :month a atteint :percent % du budget mensuel global.',
            'full' => 'Le copilote est indisponible pour tous les utilisateurs jusqu\'au mois prochain, sauf hausse du budget (AI_GLOBAL_MONTHLY_BUDGET_CENTS).',
            'partial' => 'Le copilote reste disponible jusqu\'à 100 %.',
            'action' => 'Voir le suivi des coûts',
        ],
        'en' => [
            'subject' => 'Loopartner: AI budget at :percent%',
            'intro' => 'AI spending for :month has reached :percent% of the global monthly budget.',
            'full' => 'The copilot is unavailable to all users until next month, unless the budget is raised (AI_GLOBAL_MONTHLY_BUDGET_CENTS).',
            'partial' => 'The copilot remains available until 100%.',
            'action' => 'View cost tracking',
        ],
    ];

    public function __construct(public readonly string $month, public readonly int $percent) {}

    public function notificationCategory(): string
    {
        return 'ai.budget_alerts';
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
        $values = [':month' => $this->month, ':percent' => (string) $this->percent];

        return (new MailMessage)
            ->subject(strtr($texts['subject'], $values))
            ->line(strtr($texts['intro'], $values))
            ->line($this->percent >= 100 ? $texts['full'] : $texts['partial'])
            ->action($texts['action'], rtrim((string) config('app.frontend_url'), '/').'/admin/ai')
            ->salutation('Loopartner');
    }
}
