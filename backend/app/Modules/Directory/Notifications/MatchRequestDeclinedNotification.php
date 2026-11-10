<?php

namespace App\Modules\Directory\Notifications;

use App\Models\User;
use App\Modules\Directory\Models\MatchRequest;

/**
 * Demande refusée, envoyée à son auteur. Aucun nom, aucun titre de projet, aucun message.
 * Une demande refusée par un blocage ne déclenche pas cet email (voir BlockFromMatchRequest).
 */
class MatchRequestDeclinedNotification extends MatchingNotification
{
    /**
     * @var array<string, array{subject: string, intro: string, action: string}>
     */
    private const TEXTS = [
        'fr' => [
            'subject' => 'Votre demande n\'a pas été retenue',
            'intro' => 'Votre demande de mise en relation n\'a pas été retenue. D\'autres projets et prestataires vous attendent sur Loopartner.',
            'action' => 'Voir la demande',
        ],
        'en' => [
            'subject' => 'Your request was not accepted',
            'intro' => 'Your connection request was not accepted. Other projects and providers are waiting for you on Loopartner.',
            'action' => 'View the request',
        ],
    ];

    public function __construct(public readonly MatchRequest $matchRequest) {}

    protected function texts(User $notifiable, string $locale): array
    {
        return self::TEXTS[$locale];
    }

    protected function path(): string
    {
        return '/requests/'.$this->matchRequest->uuid;
    }
}
