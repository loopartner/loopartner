<?php

namespace App\Modules\Directory\Http\Resources;

use App\Modules\Directory\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Message d'une conversation, vu par l'un de ses participants. L'expéditeur n'est indiqué que
 * par "mine" (message de l'utilisateur connecté ou de son interlocuteur) : jamais d'identifiant.
 * Un message masqué par la modération garde sa place, avec "body" à null (un message n'est
 * jamais vide autrement).
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewerId = $request->user()?->getAuthIdentifier();

        return [
            'uuid' => $this->uuid,
            // Message masqué par la modération : son texte n'est plus montré (null).
            'body' => $this->isRestricted() ? null : $this->body,
            'mine' => $this->sender_id !== null && $this->sender_id === $viewerId,
            'sent_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
