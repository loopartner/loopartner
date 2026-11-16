<?php

namespace App\Modules\Compliance\Notifications;

use App\Models\User;
use App\Support\Locale;
use App\Support\Notifications\CategorizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Récapitulatif de la mise à jour annuelle des règles, envoyé aux admins : par pays, le nombre
 * de règles à vérifier pour l'année suivante. Aucun contenu de règle : le détail se lit dans
 * l'administration (GET /admin/compliance/coverage), avec la double authentification.
 */
class RuleCoverageNotification extends Notification implements CategorizedNotification, ShouldQueue
{
    use Queueable;

    public function notificationCategory(): string
    {
        return 'compliance.rule_coverage';
    }

    /**
     * @var array<string, array{subject: string, intro: string, line: string, unavailable: string, action: string}>
     */
    private const TEXTS = [
        'fr' => [
            'subject' => 'Loopartner : règles administratives à vérifier pour :year',
            'intro' => 'Ces règles doivent être vérifiées avant le 1er janvier :year pour que le calendrier des utilisateurs reste complet.',
            'line' => ':country : :missing règle(s) sans version vérifiée pour :year, :stale à revérifier, :drafts brouillon(s) en attente.',
            'unavailable' => ':country : aucun statut juridique vérifié pour :year, le pays ne sera plus proposé.',
            'action' => 'Ouvrir l\'administration des règles',
        ],
        'en' => [
            'subject' => 'Loopartner: compliance rules to verify for :year',
            'intro' => 'These rules must be verified before January 1, :year so that users keep a complete calendar.',
            'line' => ':country: :missing rule(s) without a verified version for :year, :stale to re-verify, :drafts draft(s) pending.',
            'unavailable' => ':country: no verified legal form for :year, the country will no longer be offered.',
            'action' => 'Open rules administration',
        ],
    ];

    /**
     * @param  list<array{country: string, available: bool, missing: list<string>, stale: list<string>, drafts: int}>  $report
     */
    public function __construct(public readonly int $year, public readonly array $report) {}

    public static function sendTo(User $admin, self $notification): void
    {
        $admin->notify($notification->locale(self::localeOf($admin)));
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
        $message = (new MailMessage)
            ->subject(strtr($texts['subject'], [':year' => (string) $this->year]))
            ->line(strtr($texts['intro'], [':year' => (string) $this->year]));

        foreach ($this->report as $country) {
            if (! $country['available']) {
                $message->line(strtr($texts['unavailable'], [':country' => $country['country'], ':year' => (string) $this->year]));
            }

            if ($country['missing'] !== [] || $country['stale'] !== []) {
                $message->line(strtr($texts['line'], [
                    ':country' => $country['country'],
                    ':year' => (string) $this->year,
                    ':missing' => (string) count($country['missing']),
                    ':stale' => (string) count($country['stale']),
                    ':drafts' => (string) $country['drafts'],
                ]));
            }
        }

        return $message
            ->action($texts['action'], rtrim((string) config('app.frontend_url'), '/').'/admin/compliance')
            ->salutation('Loopartner');
    }

    private static function localeOf(User $user): string
    {
        return in_array($user->locale, Locale::SUPPORTED, true) ? $user->locale : Locale::DEFAULT;
    }
}
