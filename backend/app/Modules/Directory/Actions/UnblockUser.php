<?php

namespace App\Modules\Directory\Actions;

use App\Models\User;
use App\Modules\Directory\Models\UserBlock;
use App\Support\SecurityLog;

/**
 * Lève le blocage posé par l'utilisateur. Un blocage posé par l'autre participant n'est pas
 * concerné : seul celui qui a bloqué peut débloquer. Idempotent.
 */
class UnblockUser
{
    public function handle(User $blocker, int $blockedId): void
    {
        $deleted = UserBlock::query()
            ->where('blocker_id', $blocker->id)
            ->where('blocked_id', $blockedId)
            ->delete();

        if ($deleted > 0) {
            SecurityLog::record('messaging.user_unblocked', [
                'user_id' => $blocker->id,
                'blocked_user_id' => $blockedId,
            ]);
        }
    }
}
