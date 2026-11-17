<?php

namespace App\Modules\Appointments\Notifications;

use App\Models\User;
use App\Modules\Appointments\Enums\AppointmentNotice;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Support\AppointmentIcs;
use App\Modules\Appointments\Support\AppointmentLocation;
use App\Support\Locale;
use App\Support\Notifications\CategorizedNotification;
use App\Support\Time\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email sur un rendez-vous (décision 56), envoyé par la file d'attente après la validation de
 * la transaction, chiffré dans la file, dans la langue du destinataire. Textes :
 * AppointmentNotice.
 *
 * Contenu : titre du type de rendez-vous, date et heure dans le fuseau du destinataire
 * (users.timezone, sinon celui du calendrier), lien vers le frontend. Jamais de note,
 * d'adresse, de téléphone, de lien de visio ni de nom. La confirmation et le report accepté
 * joignent un .ics avec le titre et les dates seulement.
 *
 * Le rendez-vous passe dans la file par son seul identifiant : s'il a disparu, l'email est
 * abandonné. La date citée est celle du rendez-vous au moment de l'envoi.
 */
class AppointmentNotification extends Notification implements CategorizedNotification, ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    /**
     * @var array<string, array{greeting: string, salutation: string, timezone: string}>
     */
    private const COMMON = [
        'fr' => ['greeting' => 'Bonjour,', 'salutation' => 'L\'équipe Loopartner', 'timezone' => 'heure de :zone'],
        'en' => ['greeting' => 'Hello,', 'salutation' => 'The Loopartner team', 'timezone' => ':zone time'],
    ];

    public function __construct(
        public readonly AppointmentNotice $notice,
        public readonly Appointment $appointment,
    ) {}

    public static function sendTo(?User $recipient, AppointmentNotice $notice, Appointment $appointment): void
    {
        $recipient?->notify((new self($notice, $appointment))->locale(self::localeOf($recipient)));
    }

    public function notificationCategory(): string
    {
        return $this->notice->category();
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
        $texts = $this->notice->texts($locale);
        $common = self::COMMON[$locale];

        $mail = (new MailMessage)
            ->subject($texts['subject'])
            ->greeting($common['greeting'])
            ->line(strtr($texts['intro'], [
                ':type' => AppointmentLocation::typeTitle($this->appointment, $locale),
                ':date' => self::date($this->appointment, $notifiable, $locale),
            ]));

        if ($this->notice->attachesIcs()) {
            $mail->attachData(AppointmentIcs::forEmail($this->appointment, $locale), 'rendez-vous.ics', ['mime' => 'text/calendar']);
        }

        return $mail
            ->action($texts['action'], rtrim((string) config('app.frontend_url'), '/').'/me/appointments/'.$this->appointment->uuid)
            ->salutation($common['salutation']);
    }

    /**
     * Date et heure du rendez-vous dans le fuseau du destinataire, avec le nom du fuseau.
     */
    public static function date(Appointment $appointment, User $recipient, string $locale): string
    {
        $timezone = LocalTime::isValidTimezone($recipient->timezone) ? (string) $recipient->timezone : $appointment->timezone;
        $start = CarbonImmutable::parse($appointment->starts_at)->setTimezone($timezone)->locale($locale);
        $format = $locale === 'en' ? 'dddd, MMMM D, YYYY [at] h:mm A' : 'dddd D MMMM YYYY [à] HH:mm';

        return $start->isoFormat($format).' ('.strtr(self::COMMON[$locale]['timezone'], [':zone' => $timezone]).')';
    }

    private static function localeOf(User $user): string
    {
        return in_array($user->locale, Locale::SUPPORTED, true) ? $user->locale : Locale::DEFAULT;
    }
}
