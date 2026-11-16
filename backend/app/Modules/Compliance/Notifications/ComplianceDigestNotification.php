<?php

namespace App\Modules\Compliance\Notifications;

use App\Models\User;
use App\Modules\Compliance\Models\ThresholdAlert;
use App\Modules\Compliance\Support\Disclaimer;
use App\Support\Locale;
use App\Support\Notifications\CategorizedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Récapitulatif du suivi administratif (SendDigests) : échéances à venir, paliers de seuil,
 * calendrier modifié, purge annuelle. Titres, dates et pourcentages seulement : jamais de
 * montant, de numéro d'entreprise ni de note. Chiffré dans la file d'attente (ShouldBeEncrypted)
 * puisqu'il contient des titres saisis par l'utilisateur. Chaque email rappelle que l'outil ne
 * remplace pas un expert-comptable.
 */
class ComplianceDigestNotification extends Notification implements CategorizedNotification, ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function notificationCategory(): string
    {
        return 'compliance.reminders';
    }

    /**
     * @var array<string, array<string, string>>
     */
    private const TEXTS = [
        'fr' => [
            'subject' => 'Loopartner : vos échéances administratives',
            'deadlines' => 'Échéances à venir :',
            'reached' => 'Seuil « :title » (:year) : :level % atteint.',
            'projected' => 'Seuil « :title » (:year) : à ce rythme, il serait dépassé sur l\'année.',
            'changed' => 'Votre calendrier a été mis à jour après un changement de règles.',
            'changed_company' => 'Le calendrier de « :company » a été mis à jour après un changement de règles.',
            'retention' => 'Le chiffre d\'affaires saisi pour :year sera supprimé le 1er janvier : exportez-le avant si vous souhaitez le garder.',
            'action' => 'Ouvrir mon suivi administratif',
        ],
        'en' => [
            'subject' => 'Loopartner: your administrative deadlines',
            'deadlines' => 'Upcoming deadlines:',
            'reached' => 'Threshold “:title” (:year): :level % reached.',
            'projected' => 'Threshold “:title” (:year): at this pace, it would be exceeded over the year.',
            'changed' => 'Your calendar was updated after a change in the rules.',
            'changed_company' => 'The calendar of “:company” was updated after a change in the rules.',
            'retention' => 'The revenue entered for :year will be deleted on January 1: export it beforehand if you want to keep it.',
            'action' => 'Open my administrative follow-up',
        ],
    ];

    /**
     * @param  array{deadlines: list<array{date: string, title: string, company: string|null}>, alerts: list<array{title: string, kind: string, level: int, year: int, company: string|null}>, changed: list<string|null>, retention_year: int|null}  $digest
     */
    public function __construct(public readonly array $digest) {}

    /**
     * @param  array{deadlines: list<array{date: string, title: string, company: string|null}>, alerts: list<array{title: string, kind: string, level: int, year: int, company: string|null}>, changed: list<string|null>, retention_year: int|null}  $digest
     */
    public static function sendTo(User $user, array $digest): void
    {
        $user->notify((new self($digest))->locale(self::localeOf($user)));
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
        $locale = self::localeOf($notifiable);
        $texts = self::TEXTS[$locale];
        $message = (new MailMessage)->subject($texts['subject']);

        if ($this->digest['deadlines'] !== []) {
            $message->line($texts['deadlines']);

            foreach ($this->digest['deadlines'] as $deadline) {
                $date = CarbonImmutable::parse($deadline['date'])->format($locale === 'en' ? 'Y-m-d' : 'd/m/Y');
                $message->line('• '.$date.' — '.$deadline['title'].($deadline['company'] !== null ? ' ('.$deadline['company'].')' : ''));
            }
        }

        foreach ($this->digest['alerts'] as $alert) {
            $line = strtr($texts[$alert['kind'] === ThresholdAlert::PROJECTED ? 'projected' : 'reached'], [
                ':title' => $alert['title'],
                ':year' => (string) $alert['year'],
                ':level' => (string) $alert['level'],
            ]);
            $message->line($line.($alert['company'] !== null ? ' ('.$alert['company'].')' : ''));
        }

        foreach ($this->digest['changed'] as $company) {
            $message->line($company === null ? $texts['changed'] : strtr($texts['changed_company'], [':company' => $company]));
        }

        if ($this->digest['retention_year'] !== null) {
            $message->line(strtr($texts['retention'], [':year' => (string) $this->digest['retention_year']]));
        }

        return $message
            ->action($texts['action'], rtrim((string) config('app.frontend_url'), '/').'/suivi-administratif')
            ->line(Disclaimer::text($locale))
            ->salutation('Loopartner');
    }

    private static function localeOf(User $user): string
    {
        return in_array($user->locale, Locale::SUPPORTED, true) ? $user->locale : Locale::DEFAULT;
    }
}
