<?php

namespace App\Modules\Marketplace\Notifications;

use App\Models\User;
use App\Modules\Marketplace\Enums\QuoteNotice;
use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Support\QuotePdf;
use App\Support\Locale;
use App\Support\Notifications\CategorizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email sur un devis (reçu, refusé, accepté, rétractation), envoyé par la file d'attente après
 * la validation de la transaction, dans la langue du destinataire. Textes : QuoteNotice.
 *
 * Contenu : le numéro du devis et un lien vers le frontend ; jamais de montant, de ligne, de
 * nom ni de titre de projet (décision 46). La confirmation d'acceptation joint le PDF du devis
 * accepté, généré au moment de l'envoi (support durable même sans accès au compte).
 *
 * Le devis passe dans la file d'attente par son seul identifiant : s'il a disparu, l'email est
 * abandonné.
 */
class QuoteNotification extends Notification implements CategorizedNotification, ShouldQueueAfterCommit
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    /**
     * @var array<string, array{greeting: string, contract: string, salutation: string}>
     */
    private const COMMON = [
        'fr' => ['greeting' => 'Bonjour,', 'contract' => 'Loopartner met en relation les parties et fournit l\'outil de devis, mais n\'est pas partie au contrat conclu entre vous.', 'salutation' => 'L\'équipe Loopartner'],
        'en' => ['greeting' => 'Hello,', 'contract' => 'Loopartner connects the parties and provides the quote tool, but is not a party to the contract between you.', 'salutation' => 'The Loopartner team'],
    ];

    public function __construct(
        public readonly QuoteNotice $notice,
        public readonly Quote $quote,
    ) {}

    public static function sendTo(?User $recipient, QuoteNotice $notice, Quote $quote): void
    {
        $recipient?->notify((new self($notice, $quote))->locale(self::localeOf($recipient)));
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
            ->line(strtr($texts['intro'], [':number' => (string) $this->quote->number]));

        if ($this->notice->mentionsContract()) {
            $mail->line($common['contract']);
        }

        if ($this->notice->attachesPdf()) {
            $quote = $this->quote->load('lines');
            $mail->attachData(app(QuotePdf::class)->render($quote, $locale), QuotePdf::filename($quote), ['mime' => 'application/pdf']);
        }

        return $mail
            ->action($texts['action'], rtrim((string) config('app.frontend_url'), '/').'/me/quotes/'.$this->quote->uuid)
            ->salutation($common['salutation']);
    }

    private static function localeOf(User $user): string
    {
        return in_array($user->locale, Locale::SUPPORTED, true) ? $user->locale : Locale::DEFAULT;
    }
}
