<?php

namespace App\Modules\Directory\Notifications;

use App\Models\User;
use App\Modules\Directory\Enums\MatchRequestType;
use App\Modules\Directory\Models\MatchRequest;

/**
 * Nouvelle demande reçue, envoyée à son destinataire.
 *
 * Seule exception à la règle "aucun titre de projet" : la proposition reçue par un porteur de
 * projet cite le titre de SON propre projet, pour qu'il sache lequel est concerné. Aucun nom,
 * aucun message.
 */
class MatchRequestReceivedNotification extends MatchingNotification
{
    /**
     * @var array<string, array<string, array{subject: string, intro: string, action: string}>>
     */
    private const TEXTS = [
        'proposal' => [
            'fr' => [
                'subject' => 'Nouvelle proposition pour votre projet',
                'intro' => 'Un prestataire a proposé ses services pour votre projet « :title ». Consultez sa proposition pour l\'accepter ou la refuser.',
                'action' => 'Voir la proposition',
            ],
            'en' => [
                'subject' => 'New proposal for your project',
                'intro' => 'A provider has offered their services for your project ":title". Review the proposal to accept or decline it.',
                'action' => 'View the proposal',
            ],
        ],
        'contact' => [
            'fr' => [
                'subject' => 'Nouvelle demande de contact',
                'intro' => 'Un porteur de projet souhaite faire appel à vos services. Consultez sa demande pour l\'accepter ou la refuser.',
                'action' => 'Voir la demande',
            ],
            'en' => [
                'subject' => 'New contact request',
                'intro' => 'A project owner would like to work with you. Review the request to accept or decline it.',
                'action' => 'View the request',
            ],
        ],
    ];

    public function __construct(public readonly MatchRequest $matchRequest) {}

    protected function texts(User $notifiable, string $locale): array
    {
        $texts = self::TEXTS[$this->matchRequest->type->value][$locale];

        if ($this->matchRequest->type === MatchRequestType::Proposal) {
            $project = $this->matchRequest->project;

            // Le titre n'est cité que si le destinataire est bien le propriétaire du projet.
            $title = $project !== null && $project->user_id === $notifiable->id ? $project->title : '';
            $texts['intro'] = str_replace(':title', $title, $texts['intro']);
        }

        return $texts;
    }

    protected function path(): string
    {
        return '/requests/'.$this->matchRequest->uuid;
    }
}
