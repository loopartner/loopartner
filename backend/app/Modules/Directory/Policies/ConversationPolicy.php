<?php

namespace App\Modules\Directory\Policies;

use App\Models\User;
use App\Modules\Directory\Models\Conversation;
use App\Modules\Directory\Models\UserBlock;
use Illuminate\Auth\Access\Response;

/**
 * Conversations : seuls leurs deux participants y ont accès. Tout autre utilisateur, quel que
 * soit son rôle (admin compris), reçoit 404. La relation participants doit être chargée.
 */
class ConversationPolicy
{
    private const NOT_FOUND = 'Conversation introuvable.';

    public function view(User $user, Conversation $conversation): Response
    {
        return $conversation->hasParticipant($user) ? Response::allow() : Response::denyAsNotFound(self::NOT_FOUND);
    }

    /**
     * Écrire : participant, autre compte non supprimé, et aucun blocage dans un sens ou dans
     * l'autre. Le message ne dit pas qui a bloqué.
     */
    public function sendMessage(User $user, Conversation $conversation): Response
    {
        $response = $this->view($user, $conversation);

        if ($response->denied()) {
            return $response;
        }

        $otherId = $conversation->otherParticipant($user)?->user_id;

        return $otherId === null || UserBlock::existsBetween($user->id, $otherId)
            ? Response::deny('Vous ne pouvez pas envoyer de message dans cette conversation.')
            : Response::allow();
    }

    public function markRead(User $user, Conversation $conversation): Response
    {
        return $this->view($user, $conversation);
    }

    public function block(User $user, Conversation $conversation): Response
    {
        return $this->view($user, $conversation);
    }

    public function report(User $user, Conversation $conversation): Response
    {
        return $this->view($user, $conversation);
    }
}
