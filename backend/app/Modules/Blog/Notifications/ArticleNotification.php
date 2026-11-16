<?php

namespace App\Modules\Blog\Notifications;

use App\Models\User;
use App\Modules\Blog\Enums\ArticleNotice;
use App\Modules\Blog\Models\Article;
use App\Support\Locale;
use App\Support\Notifications\CategorizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email à l'auteur d'un article (validation, refus, dépublication, décision sur une révision),
 * envoyé par la file d'attente après la validation de la transaction, dans la langue du
 * destinataire. Les textes sont dans ArticleNotice.
 *
 * Contenu : le titre de l'article (celui du destinataire), le motif d'un refus ou d'une
 * dépublication, et un lien vers le frontend. Jamais le contenu de l'article, ni le nom ou
 * l'email d'un modérateur.
 *
 * L'article passe dans la file d'attente par son seul identifiant (SerializesModels) : s'il a
 * disparu, l'email est abandonné.
 */
class ArticleNotification extends Notification implements CategorizedNotification, ShouldQueueAfterCommit
{
    use Queueable;

    public function notificationCategory(): string
    {
        return 'blog.author';
    }

    public bool $deleteWhenMissingModels = true;

    /**
     * @var array<string, array{greeting: string, reason: string, statement: string, salutation: string}>
     */
    private const COMMON = [
        'fr' => ['greeting' => 'Bonjour,', 'reason' => 'Motif :', 'statement' => 'Exposé des motifs de cette décision et possibilité de la contester gratuitement : :url', 'salutation' => 'L\'équipe Loopartner'],
        'en' => ['greeting' => 'Hello,', 'reason' => 'Reason:', 'statement' => 'Statement of reasons for this decision and how to contest it free of charge: :url', 'salutation' => 'The Loopartner team'],
    ];

    public function __construct(
        public readonly Article $article,
        public readonly ArticleNotice $notice,
        public readonly ?string $reason = null,
    ) {}

    public static function sendTo(?User $recipient, Article $article, ArticleNotice $notice, ?string $reason = null): void
    {
        $recipient?->notify((new self($article, $notice, $reason))->locale(self::localeOf($recipient)));
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
        $replace = [':title' => $this->article->title];

        $mail = (new MailMessage)
            ->subject($texts['subject'])
            ->greeting($common['greeting'])
            ->line(strtr($texts['intro'], $replace));

        if ($this->reason !== null) {
            $mail->line($common['reason'].' '.$this->reason);
        }

        if ($this->notice->isRestrictiveDecision()) {
            $mail->line(strtr($common['statement'], [':url' => rtrim((string) config('app.frontend_url'), '/').'/me/moderation/decisions']));
        }

        return $mail
            ->action($texts['action'], rtrim((string) config('app.frontend_url'), '/').$this->path())
            ->salutation($common['salutation']);
    }

    private function path(): string
    {
        return $this->notice->linksToPublicPage() && $this->article->slug !== null
            ? '/blog/'.$this->article->slug
            : '/me/articles/'.$this->article->uuid;
    }

    private static function localeOf(User $user): string
    {
        return in_array($user->locale, Locale::SUPPORTED, true) ? $user->locale : Locale::DEFAULT;
    }
}
