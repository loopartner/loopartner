<?php

namespace App\Modules\Directory\Actions;

use App\Modules\Directory\Models\UserBlock;

/**
 * Action publique du module Directory : l'un des deux utilisateurs a-t-il bloqué l'autre ?
 *
 * Utilisée par d'autres modules (Events : inscription à l'événement d'un organisateur) sans
 * exposer le modèle UserBlock. Lecture seule : le blocage reste géré par la messagerie.
 */
class IsBlockedBetween
{
    public function handle(int $userId, int $otherUserId): bool
    {
        return UserBlock::existsBetween($userId, $otherUserId);
    }
}
