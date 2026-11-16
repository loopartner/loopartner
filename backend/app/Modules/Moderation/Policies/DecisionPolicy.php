<?php

namespace App\Modules\Moderation\Policies;

use App\Models\User;
use App\Modules\Moderation\Models\Decision;
use Illuminate\Auth\Access\Response;

/**
 * Un exposé des motifs n'est visible que de l'auteur du contenu visé : tout autre utilisateur,
 * admin compris, reçoit 404 (l'administration passe par /admin/moderation).
 */
class DecisionPolicy
{
    public function view(User $user, Decision $decision): Response
    {
        return $decision->owner_id !== null && $decision->owner_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
