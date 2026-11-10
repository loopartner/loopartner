<?php

namespace App\Modules\Directory\Notifications;

use App\Models\User;
use App\Modules\Directory\Models\MatchRequest;

/**
 * Demande acceptée, envoyée à son auteur : lien vers la conversation ouverte. Aucun nom, aucun
 * titre de projet (le projet appartient au destinataire), aucun message.
 */
class MatchRequestAcceptedNotification extends MatchingNotification
{
    /**
     * @var array<string, array{subject: string, intro: string, action: string}>
     */
    private const TEXTS = [
        'fr' => [
            'subject' => 'Votre demande a été acceptée',
            'intro' => 'Bonne nouvelle : votre demande de mise en relation a été acceptée. Vous pouvez maintenant échanger avec votre interlocuteur dans la messagerie Loopartner.',
            'action' => 'Ouvrir la conversation',
        ],
        'en' => [
            'subject' => 'Your request has been accepted',
            'intro' => 'Good news: your connection request has been accepted. You can now talk with the other person in the Loopartner messaging.',
            'action' => 'Open the conversation',
        ],
    ];

    public function __construct(public readonly MatchRequest $matchRequest) {}

    protected function texts(User $notifiable, string $locale): array
    {
        return self::TEXTS[$locale];
    }

    protected function path(): string
    {
        $conversation = $this->matchRequest->conversation;

        return $conversation === null ? '/requests/'.$this->matchRequest->uuid : '/conversations/'.$conversation->uuid;
    }
}
