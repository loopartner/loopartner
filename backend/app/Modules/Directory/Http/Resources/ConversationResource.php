<?php

namespace App\Modules\Directory\Http\Resources;

use App\Modules\Directory\Models\Conversation;
use App\Modules\Directory\Support\ViewerContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Conversation, vue par l'un de ses deux participants.
 *
 * - Aucun identifiant interne : uuid de la conversation, de la demande, du projet et du
 *   dernier message seulement. L'expéditeur d'un message n'est indiqué que par "mine".
 * - L'interlocuteur est présenté par ViewerContext (nom du porteur de projet révélé : la
 *   conversation n'existe qu'après l'acceptation de la demande). Compte supprimé :
 *   {"deleted": true}, et la conversation passe en lecture seule.
 * - unread_count : messages de l'autre participant plus récents que le marqueur de lecture
 *   (attribut calculé par la requête du contrôleur, 0 sinon).
 *
 * Les relations participants.user.roles, matchRequest.project et lastMessage doivent être chargées.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    private const EXCERPT_LENGTH = 140;

    public function __construct(Conversation $resource, private readonly ViewerContext $context)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $this->context->viewer;
        $otherId = $this->resource->otherParticipant($viewer)?->user_id;
        $other = $this->resource->otherParticipant($viewer)?->user;
        $matchRequest = $this->matchRequest;
        $project = $matchRequest?->project;
        $lastMessage = $this->lastMessage;

        return [
            'uuid' => $this->uuid,
            'match_request' => $matchRequest === null ? null : [
                'uuid' => $matchRequest->uuid,
                'type' => $matchRequest->type->value,
            ],
            'project' => $project === null ? null : [
                'uuid' => $project->uuid,
                'title' => $project->title,
            ],
            'counterpart' => $this->context->counterpart($other, revealName: true),
            'last_message' => $lastMessage === null ? null : [
                'uuid' => $lastMessage->uuid,
                'excerpt' => Str::limit($lastMessage->body, self::EXCERPT_LENGTH),
                'mine' => $lastMessage->sender_id !== null && $lastMessage->sender_id === $viewer->id,
                'sent_at' => $lastMessage->created_at?->toIso8601ZuluString(),
            ],
            'unread_count' => (int) ($this->resource->getAttribute('unread_count') ?? 0),
            'blocked_by_me' => $this->context->hasBlocked($otherId),
            'can_send' => $otherId !== null && ! $this->context->hasBlocked($otherId) && ! $this->context->isBlockedBy($otherId),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'last_activity_at' => $this->last_activity_at?->toIso8601ZuluString(),
        ];
    }
}
