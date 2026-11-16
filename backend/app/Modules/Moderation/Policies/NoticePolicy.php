<?php

namespace App\Modules\Moderation\Policies;

use App\Models\User;
use App\Modules\Moderation\Models\Notice;
use Illuminate\Auth\Access\Response;

/**
 * Un signalement n'est visible que de son auteur connecté : tout autre utilisateur, admin
 * compris, reçoit 404 (la modération passe par /admin/moderation).
 */
class NoticePolicy
{
    public function view(User $user, Notice $notice): Response
    {
        return $notice->reporter_id !== null && $notice->reporter_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
