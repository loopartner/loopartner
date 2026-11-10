<?php

namespace App\Modules\Directory\Actions;

use App\Modules\Directory\Models\ConversationParticipant;
use App\Modules\Directory\Models\Message;

/**
 * Marque les messages d'une conversation comme lus, jusqu'au message donné ou jusqu'au dernier.
 *
 * Le marqueur de lecture n'avance jamais en arrière : marquer un ancien message comme lu ne
 * rend pas non lus les messages suivants (UPDATE conditionnel, sûr en cas de requêtes simultanées).
 */
class MarkConversationRead
{
    public function handle(ConversationParticipant $participant, ?Message $upTo = null): void
    {
        $target = $upTo !== null
            ? $upTo->id
            : Message::query()->where('conversation_id', $participant->conversation_id)->max('id');

        if ($target === null) {
            return;
        }

        ConversationParticipant::query()
            ->whereKey($participant->id)
            ->where(fn ($query) => $query->whereNull('last_read_message_id')->orWhere('last_read_message_id', '<', $target))
            ->update(['last_read_message_id' => $target, 'updated_at' => now()]);
    }
}
