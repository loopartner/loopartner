<?php

namespace App\Modules\Events\Notifications;

use App\Models\User;
use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Support\Locale;
use App\Support\Notifications\CategorizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email du module Events, envoyé par la file d'attente après la validation de la transaction,
 * dans la langue de son destinataire (users.locale). Les textes sont dans EventNotice.
 *
 * Confidentialité (mêmes règles que la messagerie) :
 * - jamais le lien de connexion : l'email renvoie vers la page de l'événement sur le frontend,
 *   où l'inscrit connecté le trouve (GET /me/event-registrations/{slug}) ;
 * - jamais le texte d'une annonce, ni le nom ou l'email d'un autre utilisateur ;
 * - le titre d'un événement n'apparaît que s'il a été validé par un admin (participants) ou
 *   s'il appartient au destinataire (organisateur). Le motif d'une décision, écrit par un admin,
 *   n'est envoyé qu'à l'organisateur.
 *
 * L'événement passe dans la file d'attente par son seul identifiant (SerializesModels) : s'il a
 * disparu, l'email est abandonné.
 */
class EventNotification extends Notification implements CategorizedNotification, ShouldQueueAfterCommit
{
    use Queueable;

    public function notificationCategory(): string
    {
        return $this->notice->notificationCategory();
    }

    public bool $deleteWhenMissingModels = true;

    /**
     * @var array<string, array{greeting: string, reason: string, salutation: string}>
     */
    private const COMMON = [
        'fr' => ['greeting' => 'Bonjour,', 'reason' => 'Motif :', 'salutation' => 'L\'équipe Loopartner'],
        'en' => ['greeting' => 'Hello,', 'reason' => 'Reason:', 'salutation' => 'The Loopartner team'],
    ];

    public function __construct(
        public readonly Event $event,
        public readonly EventNotice $notice,
        public readonly ?string $reason = null,
    ) {}

    /**
     * Envoie l'email dans la langue du destinataire (textes du gabarit Laravel compris).
     */
    public static function sendTo(User $recipient, Event $event, EventNotice $notice, ?string $reason = null): void
    {
        $recipient->notify((new self($event, $notice, $reason))->locale(self::localeOf($recipient)));
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Rappel, modification ou annonce : abandonné si le destinataire n'est plus inscrit.
     */
    public function shouldSend(User $notifiable, string $channel): bool
    {
        if (! $this->notice->requiresActiveRegistration()) {
            return true;
        }

        return EventRegistration::query()
            ->where('event_id', $this->event->id)
            ->where('user_id', $notifiable->id)
            ->where('status', RegistrationStatus::Registered->value)
            ->exists();
    }

    public function toMail(User $notifiable): MailMessage
    {
        $locale = self::localeOf($notifiable);
        $texts = $this->notice->texts($locale);
        $common = self::COMMON[$locale];
        $replace = [':title' => $this->event->title, ':date' => $this->startDate($locale)];

        $mail = (new MailMessage)
            ->subject(strtr($texts['subject'], $replace))
            ->greeting($common['greeting'])
            ->line(strtr($texts['intro'], $replace));

        if ($this->reason !== null && $this->notice->isForOrganizer()) {
            $mail->line($common['reason'].' '.$this->reason);
        }

        if (isset($texts['outro'])) {
            $mail->line($texts['outro']);
        }

        return $mail
            ->action($texts['action'], rtrim((string) config('app.frontend_url'), '/').$this->path())
            ->salutation($common['salutation']);
    }

    /**
     * Page du frontend : l'événement de l'organisateur, ou la page publique pour un participant.
     */
    private function path(): string
    {
        if ($this->notice->isForOrganizer()) {
            return '/me/events/'.$this->event->uuid;
        }

        return $this->event->slug !== null ? '/events/'.$this->event->slug : '/events';
    }

    /**
     * Date de début à l'heure locale de l'événement, avec son fuseau.
     */
    private function startDate(string $locale): string
    {
        return $this->event->starts_at->copy()
            ->setTimezone($this->event->timezone)
            ->locale($locale)
            ->isoFormat('LLLL').' ('.$this->event->timezone.')';
    }

    private static function localeOf(User $user): string
    {
        return in_array($user->locale, Locale::SUPPORTED, true) ? $user->locale : Locale::DEFAULT;
    }
}
