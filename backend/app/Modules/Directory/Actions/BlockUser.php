<?php

namespace App\Modules\Directory\Actions;

use App\Models\User;
use App\Modules\Directory\Models\UserBlock;
use App\Support\SecurityLog;

/**
 * Bloque un utilisateur : plus aucun message entre les deux, ni aucune nouvelle demande, dans
 * les deux sens. Idempotent : bloquer deux fois ne crée qu'un blocage et ne journalise qu'une fois.
 */
class BlockUser
{
    public function handle(User $blocker, int $blockedId): void
    {
        $created = UserBlock::query()->insertOrIgnore([
            'blocker_id' => $blocker->id,
            'blocked_id' => $blockedId,
            'created_at' => now(),
        ]);

        if ($created > 0) {
            SecurityLog::record('messaging.user_blocked', [
                'user_id' => $blocker->id,
                'blocked_user_id' => $blockedId,
            ]);
        }
    }
}
