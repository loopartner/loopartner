<?php

namespace App\Modules\Directory\Notifications;

use App\Models\User;
use App\Modules\Directory\Models\ConversationParticipant;
use App\Modules\Directory\Models\Message;

/**
 * Nouveau message, envoyé à l'autre participant de la conversation.
 *
 * Au plus un email par conversation et par destinataire toutes les 15 minutes : SendMessage ne
 * l'envoie que s'il a pu avancer last_notified_at (UPDATE conditionnel). Si le destinataire a lu
 * le message avant le traitement de la file d'attente, l'email est abandonné (shouldSend).
 * Ni le contenu du message, ni le nom de l'expéditeur.
 */
class NewMessageNotification extends MatchingNotification
{
    public function notificationCategory(): string
    {
        return 'directory.messages';
    }

    /**
     * @var array<string, array{subject: string, intro: string, action: string}>
     */
    private const TEXTS = [
        'fr' => [
            'subject' => 'Nouveau message sur Loopartner',
            'intro' => 'Vous avez reçu un nouveau message. Connectez-vous pour le lire et répondre.',
            'action' => 'Lire le message',
        ],
        'en' => [
            'subject' => 'New message on Loopartner',
            'intro' => 'You have received a new message. Log in to read it and reply.',
            'action' => 'Read the message',
        ],
    ];

    public function __construct(public readonly Message $message) {}

    /**
     * Le message est-il encore non lu par le destinataire ?
     */
    public function shouldSend(User $notifiable, string $channel): bool
    {
        $lastRead = ConversationParticipant::query()
            ->where('conversation_id', $this->message->conversation_id)
            ->where('user_id', $notifiable->id)
            ->first(['last_read_message_id']);

        return $lastRead !== null && ($lastRead->last_read_message_id ?? 0) < $this->message->id;
    }

    protected function texts(User $notifiable, string $locale): array
    {
        return self::TEXTS[$locale];
    }

    protected function path(): string
    {
        return '/conversations/'.$this->message->conversation->uuid;
    }
}
