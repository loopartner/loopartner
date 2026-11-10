<?php

namespace App\Modules\Directory\Actions;

use App\Models\User;
use App\Modules\Directory\Models\Conversation;
use App\Modules\Directory\Models\ConversationParticipant;
use App\Modules\Directory\Models\Message;
use App\Modules\Directory\Notifications\MatchingNotification;
use App\Modules\Directory\Notifications\NewMessageNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Envoie un message en texte brut dans une conversation.
 *
 * - Le droit d'écrire (participant, pas de blocage, autre compte non supprimé) est vérifié par
 *   ConversationPolicy::sendMessage.
 * - Le message de l'expéditeur compte comme lu pour lui.
 * - Email à l'autre participant au plus une fois toutes les Conversation::EMAIL_COOLDOWN_MINUTES
 *   minutes : un UPDATE conditionnel avance last_notified_at, et seul l'envoi qui l'a avancé
 *   déclenche l'email (deux messages simultanés n'en produisent qu'un).
 * - Le contenu du message n'est jamais journalisé.
 */
class SendMessage
{
    public function handle(User $sender, Conversation $conversation, string $body): Message
    {
        return DB::transaction(function () use ($sender, $conversation, $body): Message {
            $now = now();

            $message = new Message(['body' => $body]);
            $message->forceFill([
                'uuid' => (string) Str::uuid(),
                'conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
            ])->save();

            $conversation->forceFill(['last_activity_at' => $now])->save();

            ConversationParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $sender->id)
                ->update(['last_read_message_id' => $message->id, 'updated_at' => $now]);

            $recipient = $conversation->otherParticipant($sender)?->user;

            if ($recipient !== null && $this->claimNotification($conversation, $recipient)) {
                MatchingNotification::sendTo($recipient, new NewMessageNotification($message->setRelation('conversation', $conversation)));
            }

            return $message;
        });
    }

    /**
     * Avance last_notified_at si le dernier email date de plus de 15 minutes (ou n'existe pas).
     */
    private function claimNotification(Conversation $conversation, User $recipient): bool
    {
        $now = now();

        return ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $recipient->id)
            ->where(fn ($query) => $query
                ->whereNull('last_notified_at')
                ->orWhere('last_notified_at', '<=', $now->copy()->subMinutes(Conversation::EMAIL_COOLDOWN_MINUTES)))
            ->update(['last_notified_at' => $now, 'updated_at' => $now]) === 1;
    }
}
