<?php

namespace App\Modules\Moderation\Notifications;

use App\Models\User;
use App\Modules\Moderation\Enums\ModerationMail;
use App\Modules\Moderation\Support\TargetTypes;
use App\Support\Locale;
use App\Support\Notifications\CategorizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification as Notifier;

/**
 * Email du module Moderation, envoyé par la file d'attente après la validation de la
 * transaction et chiffré dans la file (il peut porter un jeton de suivi et les faits d'une
 * décision). Il reçoit des données simples, jamais un modèle : il part même si le contenu a
 * disparu entre-temps.
 *
 * Un signalant sans compte le reçoit à l'adresse qu'il a donnée, dans la langue de son
 * signalement. Textes : ModerationMail (brouillon à faire valider par le juriste).
 *
 * Données : uuid, type, path (page du frontend), et selon l'email label, measure, source,
 * ground, law, clause, clause_reference, facts, hint, deadline (ISO 8601), reason, outcome.
 */
class ModerationNotification extends Notification implements CategorizedNotification, ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * @param  array<string, string|null>  $data
     */
    public function __construct(
        public readonly ModerationMail $mail,
        public readonly string $category,
        public readonly array $data,
    ) {}

    /**
     * @param  array<string, string|null>  $data
     */
    public static function toUser(User $user, ModerationMail $mail, string $category, array $data): void
    {
        $user->notify((new self($mail, $category, $data))->locale(self::localeOf($user->locale)));
    }

    /**
     * @param  array<string, string|null>  $data
     */
    public static function toAddress(#[\SensitiveParameter] string $email, ?string $locale, ModerationMail $mail, string $category, array $data): void
    {
        Notifier::route('mail', $email)->notify((new self($mail, $category, $data))->locale(self::localeOf($locale)));
    }

    public function notificationCategory(): string
    {
        return $this->category;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = self::localeOf($this->locale);
        $texts = $this->mail->texts($locale);
        $common = ModerationMail::common($locale);
        $replace = [
            ':type' => TargetTypes::label((string) $this->value('type'), $locale),
            ':label' => (string) $this->value('label'),
            ':outcome' => $this->outcome($locale),
        ];

        $mail = (new MailMessage)
            ->subject(strtr($texts['subject'], $replace))
            ->greeting($common['greeting'])
            ->line(strtr($texts['intro'], $replace));

        foreach ($this->lines($common, $locale) as $line) {
            $mail->line($line);
        }

        if (isset($texts['outro'])) {
            $mail->line($texts['outro']);
        }

        return $mail
            ->action($texts['action'], rtrim((string) config('app.frontend_url'), '/').$this->value('path'))
            ->salutation($common['salutation']);
    }

    /**
     * @param  array<string, string>  $common
     * @return list<string>
     */
    private function lines(array $common, string $locale): array
    {
        $deadline = $this->date('deadline', $locale);

        return array_values(array_filter(match ($this->mail) {
            ModerationMail::NoticeReceived => [
                strtr($common['reference'], [':uuid' => (string) $this->value('uuid')]),
            ],
            ModerationMail::NoticeDecided => [
                strtr($common['reference'], [':uuid' => (string) $this->value('uuid')]),
                $this->value('measure') === 'restrict_visibility' ? $common['measure_restrict'] : $common['measure_none'],
                $this->value('measure') === 'restrict_visibility' ? $this->ground($common) : null,
                $deadline !== null ? strtr($common['appeal_notice'], [':date' => $deadline]) : null,
                $common['court'],
            ],
            ModerationMail::DecisionTaken => [
                match ($this->value('source')) {
                    'notice' => $common['source_notice'],
                    'pre_publication_review' => $common['source_review'],
                    default => $common['source_initiative'],
                },
                $this->ground($common),
                strtr($common['facts'], [':facts' => (string) $this->value('facts')]),
                $this->value('hint') !== null ? strtr($common['correction'], [':hint' => (string) $this->value('hint')]) : null,
                $common['human'],
                $common['scope'],
                $deadline !== null ? strtr($common['appeal'], [':date' => $deadline]) : null,
                $common['court'],
            ],
            ModerationMail::RestrictionLifted => [
                $this->value('reason') !== null ? strtr($common['outcome'], [':reason' => (string) $this->value('reason')]) : null,
            ],
            ModerationMail::AppealDecided => [
                $this->value('reason') !== null ? strtr($common['outcome'], [':reason' => (string) $this->value('reason')]) : null,
                $common['court'],
            ],
        }, fn (?string $line): bool => $line !== null && $line !== ''));
    }

    /**
     * @param  array<string, string>  $common
     */
    private function ground(array $common): ?string
    {
        return match ($this->value('ground')) {
            'illegal' => strtr($common['ground_illegal'], [':law' => (string) $this->value('law')]),
            'terms' => strtr($common['ground_terms'], [
                ':clause' => (string) $this->value('clause'),
                ':reference' => $this->value('clause_reference') !== null ? ' ('.$this->value('clause_reference').')' : '',
            ]),
            default => null,
        };
    }

    private function outcome(string $locale): string
    {
        $key = $this->value('outcome');

        return $key !== null ? (ModerationMail::APPEAL_OUTCOMES[$locale][$key] ?? '') : '';
    }

    private function date(string $key, string $locale): ?string
    {
        $value = $this->value($key);

        return $value === null ? null : Carbon::parse($value)->locale($locale)->isoFormat('LL');
    }

    private function value(string $key): ?string
    {
        return $this->data[$key] ?? null;
    }

    private static function localeOf(?string $locale): string
    {
        return in_array($locale, Locale::SUPPORTED, true) ? $locale : Locale::DEFAULT;
    }
}
